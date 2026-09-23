<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Tax\Return\JournalTaxOrigin;
use PDO;

/**
 * Výsledovka po dimenzi: řádky = hodnoty jednoho typu (strom), sloupce = výnosy
 * (třída 6), náklady (třída 5) a výsledek. Počítá se z deníku, ze zaúčtovaných
 * zápisů bez uzávěrkového převodu výsledkových účtů (jako výkazy).
 *
 * Hodnota ve stromu má vlastní částky (řádky přímo s ní) a souhrn za celou větev.
 * Řádky bez hodnoty daného typu tvoří řádek „bez hodnoty", takže součet sestavy
 * sedí na výsledek hospodaření firmy za období.
 *
 * Globální typ lze sečíst za víc firem skupiny najednou (projekt vedený přes
 * mateřskou firmu i SPV); které firmy smí do součtu, určuje volající.
 */
final class DimensionProfitService
{
    public function __construct(
        private readonly Connection $db,
        private readonly DimensionRepository $dimensions,
    ) {}

    /**
     * @param list<int> $supplierIds firmy do součtu (první je aktuální firma)
     * @return array<string,mixed>
     */
    public function build(int $supplierId, int $typeId, string $from, string $to, array $supplierIds): array
    {
        $type = $this->dimensions->findType($supplierId, $typeId);
        if ($type === null) {
            throw new ReportException('not_found', 'Typ dimenze nenalezen.', 404);
        }
        $values = $this->dimensions->listValues($supplierId, $typeId);

        $direct = [];
        foreach ($supplierIds as $sid) {
            foreach ($this->sums($sid, $typeId, $from, $to) as $key => $sum) {
                $direct[$key]['revenue'] = ($direct[$key]['revenue'] ?? 0) + $sum['revenue'];
                $direct[$key]['cost'] = ($direct[$key]['cost'] ?? 0) + $sum['cost'];
            }
        }

        $byParent = [];
        $known = [];
        foreach ($values as $v) {
            $known[$v['id']] = true;
        }
        foreach ($values as $v) {
            $parent = $v['parent_id'] !== null && isset($known[$v['parent_id']]) ? $v['parent_id'] : 0;
            $byParent[$parent][] = $v;
        }

        $rows = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$rows, $byParent, $direct): array {
            $sum = ['revenue' => 0, 'cost' => 0];
            foreach ($byParent[$parentId] ?? [] as $v) {
                $index = count($rows);
                $rows[] = null;
                $own = $direct[(string) $v['id']] ?? ['revenue' => 0, 'cost' => 0];
                $children = $walk($v['id'], $depth + 1);
                $total = ['revenue' => $own['revenue'] + $children['revenue'], 'cost' => $own['cost'] + $children['cost']];
                $rows[$index] = [
                    'value_id' => $v['id'],
                    'parent_id' => $v['parent_id'],
                    'code' => $v['code'],
                    'name' => $v['name'],
                    'is_active' => $v['is_active'],
                    'depth' => $depth,
                    'has_children' => isset($byParent[$v['id']]),
                    'own' => self::money($own),
                    'total' => self::money($total),
                ];
                $sum['revenue'] += $total['revenue'];
                $sum['cost'] += $total['cost'];
            }
            return $sum;
        };
        $assigned = $walk(0, 0);

        // Řádky s hodnotou, kterou firma nevidí (firma mezitím skupinu opustila),
        // se nesmí ztratit — patří k řádku „bez hodnoty" se zvláštním příznakem.
        $unassigned = $direct[''] ?? ['revenue' => 0, 'cost' => 0];
        foreach ($direct as $key => $sum) {
            if ($key !== '' && !isset($known[(int) $key])) {
                $unassigned['revenue'] += $sum['revenue'];
                $unassigned['cost'] += $sum['cost'];
            }
        }
        $all = [
            'revenue' => $assigned['revenue'] + $unassigned['revenue'],
            'cost' => $assigned['cost'] + $unassigned['cost'],
        ];

        return [
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'supplier_ids' => $supplierIds,
            'rows' => $rows,
            'unassigned' => self::money($unassigned),
            'totals' => self::money($all),
        ];
    }

    /**
     * Součty v haléřích podle hodnoty ('' = řádek bez hodnoty).
     *
     * @return array<string,array{revenue:int,cost:int}>
     */
    private function sums(int $supplierId, int $typeId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            'WITH RECURSIVE ' . JournalTaxOrigin::cte($supplierId) . "
            SELECT COALESCE(jd.dimension_value_id, ccv.id) AS value_id,
                   SUM(CASE WHEN a.account_type = 'revenue'
                            THEN CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END ELSE 0 END) AS revenue,
                   SUM(CASE WHEN a.account_type = 'expense'
                            THEN CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END ELSE 0 END) AS cost
              FROM journal_entry_lines l
              JOIN journal_entries e ON e.id = l.entry_id
              " . JournalTaxOrigin::join() . "
              JOIN chart_of_accounts a ON a.id = l.account_id
         LEFT JOIN journal_entry_line_dimensions jd
                ON jd.line_id = l.id AND jd.dimension_type_id = ?
         LEFT JOIN cost_centers cc
                ON jd.line_id IS NULL AND l.cost_center IS NOT NULL
               AND cc.supplier_id = l.supplier_id AND cc.code = l.cost_center
         LEFT JOIN dimension_values ccv ON ccv.cost_center_id = cc.id AND ccv.type_id = ?
             WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
               AND e.entry_date BETWEEN ? AND ?
               AND a.account_type IN ('revenue', 'expense')
               AND " . JournalTaxOrigin::includedSql() . '
             GROUP BY COALESCE(jd.dimension_value_id, ccv.id)'
        );
        $stmt->execute([$typeId, $typeId, $supplierId, $from, $to, ClosingSourceId::STOCK_SLOT_BASE]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = $r['value_id'] === null ? '' : (string) (int) $r['value_id'];
            $out[$key] = [
                'revenue' => (int) round(((float) $r['revenue']) * 100),
                'cost' => (int) round(((float) $r['cost']) * 100),
            ];
        }
        return $out;
    }

    /**
     * @param array{revenue:int,cost:int} $cents
     * @return array{revenue:float,cost:float,result:float}
     */
    private static function money(array $cents): array
    {
        return [
            'revenue' => $cents['revenue'] / 100,
            'cost' => $cents['cost'] / 100,
            'result' => ($cents['revenue'] - $cents['cost']) / 100,
        ];
    }
}
