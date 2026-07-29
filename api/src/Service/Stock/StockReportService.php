<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockLevelRepository;
use PDO;

/**
 * Skladové sestavy (Epic SKLAD §6, §8.1): stav zásob k dnešku a ocenění
 * k historickému datu (replay ledgeru per karta). Čtení stock_items/warehouses
 * přímým SQL (vzor {@see StockDocumentService::itemsMeta()}) — repository
 * modulu jsou read-only bez batch-meta metod pro sestavy.
 */
final class StockReportService
{
    /** B8: nad tento počet posted+reversed řádků skladové knihy firmy → 422 (v2 = async). */
    private const MAX_MOVEMENTS = 50000;

    public function __construct(
        private readonly Connection $db,
        private readonly StockLevelRepository $levels,
    ) {}

    /**
     * Stav zásob k dnešku (aktuální stock_levels) s filtry sklad/typ/pod minimem.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function status(int $supplierId, array $filters): array
    {
        $levelFilters = [];
        if (!empty($filters['warehouse_id'])) {
            $levelFilters['warehouse_id'] = (int) $filters['warehouse_id'];
        }
        if (!empty($filters['item_type'])) {
            $levelFilters['item_type'] = (string) $filters['item_type'];
        }
        if (!empty($filters['below_min'])) {
            $levelFilters['below_min'] = true;
        }
        if (array_key_exists('active', $filters) && $filters['active'] !== null && $filters['active'] !== '') {
            $levelFilters['active'] = $filters['active'];
        }
        if (!empty($filters['q'])) {
            $levelFilters['q'] = (string) $filters['q'];
        }

        $rows = $this->levels->levels($supplierId, $levelFilters);
        $totalValueC = 0;
        foreach ($rows as $r) {
            $totalValueC += StockValuation::valueToC((string) $r['value_total']);
        }

        return [
            'items'  => $rows,
            'totals' => [
                'value_total' => StockValuation::cToDecimal($totalValueC),
                'count'       => count($rows),
            ],
        ];
    }

    /**
     * Ocenění zásob k historickému datu — replay average-cost sekvence každé
     * dvojice (sklad, karta) od nuly do `$date` (§3.2 stejný algoritmus jako
     * {@see StockRecomputeService::replay()}, ale READ-ONLY — nic se nezapisuje).
     *
     * @param array<string,mixed> $filters {warehouse_id?:int}
     * @return array<string,mixed>
     * @throws StockException too_many_movements (422, B8) — nad 50 000 pohybů firmy
     */
    public function valuation(int $supplierId, string $date, array $filters): array
    {
        if (!self::isDate($date)) {
            throw new StockException('invalid_document', 'Datum sestavy je povinné (YYYY-MM-DD).');
        }

        if ($this->countPostedLines($supplierId) > self::MAX_MOVEMENTS) {
            throw new StockException(
                'too_many_movements',
                'Příliš mnoho skladových pohybů — sestava k historickému datu se pro tak velký objem generuje asynchronně (v2).',
                422,
            );
        }

        $warehouseId = !empty($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $rows        = $this->fetchLinesUpTo($supplierId, $date, $warehouseId);
        $itemsMeta   = $this->itemsMetaMap($supplierId);
        $warehouses  = $this->warehouseMetaMap($supplierId);

        $out         = [];
        $totalValueC = 0;
        $curKey      = null;
        $qtyT        = 0;
        $valueC      = 0;

        $flush = function () use (&$out, &$curKey, &$qtyT, &$valueC, $itemsMeta, $warehouses, &$totalValueC): void {
            if ($curKey === null || ($qtyT === 0 && $valueC === 0)) {
                return;
            }
            [$whId, $itemId] = $curKey;
            $out[] = [
                'warehouse_id'   => $whId,
                'warehouse_code' => $warehouses[$whId]['code'] ?? '',
                'warehouse_name' => $warehouses[$whId]['name'] ?? '',
                'stock_item_id'  => $itemId,
                'sku'            => $itemsMeta[$itemId]['sku'] ?? '',
                'name'           => $itemsMeta[$itemId]['name'] ?? '',
                'unit'           => $itemsMeta[$itemId]['unit'] ?? '',
                'qty'            => StockValuation::tToDecimal($qtyT),
                'value_total'    => StockValuation::cToDecimal($valueC),
            ];
            $totalValueC += $valueC;
        };

        foreach ($rows as $r) {
            $key = [(int) $r['warehouse_id'], (int) $r['stock_item_id']];
            if ($curKey === null || $key !== $curKey) {
                $flush();
                $curKey = $key;
                $qtyT   = 0;
                $valueC = 0;
            }

            $lineQtyT = StockValuation::qtyToT((string) $r['qty']);
            if ((int) $r['direction'] === 1) {
                $qtyT   += $lineQtyT;
                $valueC += StockValuation::valueToC((string) $r['value_total']);
                continue;
            }

            // Fixní (nepřeceňovaná) výdejová noha storna — hodnotová neutralita §4.4,
            // shodně s StockRecomputeService::replay().
            $frozen = (bool) $r['is_reversal'] || ((string) $r['status'] === 'reversed');
            if ($frozen) {
                $lineValueC = StockValuation::valueToC((string) $r['value_total']);
                $qtyT   -= $lineQtyT;
                $valueC -= $lineValueC;
                continue;
            }

            $res    = StockValuation::issue($qtyT, $valueC, $lineQtyT);
            $qtyT   = $res['qtyT'];
            $valueC = $res['valueC'];
        }
        $flush();

        return [
            'date'   => $date,
            'items'  => $out,
            'totals' => [
                'value_total' => StockValuation::cToDecimal($totalValueC),
                'count'       => count($out),
            ],
        ];
    }

    // ── interní: skladová kniha k datu ──────────────────────────────────────────

    /**
     * Řádky skladové knihy (obě nohy převodky) se supplier_id ≤ `$date`,
     * seřazené (stock_item_id, warehouse_id, doc_date, booked_at, document_id,
     * line_no, line_id) — replay-ready pořadí (vzor
     * {@see \MyInvoice\Repository\StockDocumentRepository::postedLinesForItemFrom()}).
     *
     * @return list<array<string,mixed>>
     */
    private function fetchLinesUpTo(int $supplierId, string $date, ?int $warehouseId): array
    {
        $wCondSource = $warehouseId !== null ? ' AND d.warehouse_id = ?' : '';
        $wCondDest   = $warehouseId !== null ? ' AND d.warehouse_to_id = ?' : '';

        $isReversalExpr = 'EXISTS (SELECT 1 FROM stock_documents o WHERE o.supplier_id = d.supplier_id AND o.reversal_document_id = d.id)';

        $sql = "
            (SELECT l.id AS line_id, l.document_id, d.doc_type, d.status, l.doc_date, d.booked_at, l.line_no,
                    d.warehouse_id AS warehouse_id, l.stock_item_id, l.qty, l.unit_cost, l.value_total, l.extra_cost,
                    CASE WHEN d.doc_type = 'receipt' THEN 1 ELSE -1 END AS direction,
                    {$isReversalExpr} AS is_reversal
               FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND d.status IN ('posted','reversed') AND l.doc_date <= ?
                AND d.doc_type IN ('receipt','issue'){$wCondSource})
            UNION ALL
            (SELECT l.id AS line_id, l.document_id, d.doc_type, d.status, l.doc_date, d.booked_at, l.line_no,
                    d.warehouse_id AS warehouse_id, l.stock_item_id, l.qty, l.unit_cost, l.value_total, l.extra_cost,
                    -1 AS direction,
                    {$isReversalExpr} AS is_reversal
               FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND d.status IN ('posted','reversed') AND l.doc_date <= ?
                AND d.doc_type = 'transfer'{$wCondSource})
            UNION ALL
            (SELECT l.id AS line_id, l.document_id, d.doc_type, d.status, l.doc_date, d.booked_at, l.line_no,
                    d.warehouse_to_id AS warehouse_id, l.stock_item_id, l.qty, l.unit_cost, l.value_total, l.extra_cost,
                    1 AS direction,
                    {$isReversalExpr} AS is_reversal
               FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND d.status IN ('posted','reversed') AND l.doc_date <= ?
                AND d.doc_type = 'transfer'{$wCondDest})
            ORDER BY stock_item_id ASC, warehouse_id ASC, doc_date ASC, booked_at ASC, document_id ASC, line_no ASC, line_id ASC";

        $params = [];
        foreach ([$wCondSource, $wCondSource, $wCondDest] as $cond) {
            $params[] = $supplierId;
            $params[] = $date;
            if ($cond !== '') {
                $params[] = $warehouseId;
            }
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function countPostedLines(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM stock_document_lines l
               JOIN stock_documents d ON d.id = l.document_id AND d.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND d.status IN ('posted','reversed')"
        );
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<int,array{sku:string,name:string,unit:string}> */
    private function itemsMetaMap(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, sku, name, unit FROM stock_items WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = ['sku' => (string) $r['sku'], 'name' => (string) $r['name'], 'unit' => (string) $r['unit']];
        }
        return $out;
    }

    /** @return array<int,array{code:string,name:string}> */
    private function warehouseMetaMap(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, code, name FROM warehouses WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = ['code' => (string) $r['code'], 'name' => (string) $r['name']];
        }
        return $out;
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
