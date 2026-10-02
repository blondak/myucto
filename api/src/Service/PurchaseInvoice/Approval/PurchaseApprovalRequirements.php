<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice\Approval;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionDefaults;
use PDO;

/**
 * Které hodnoty dimenzí musí přijatý doklad schválit a za jakou částku.
 *
 * Dimenze položky se skládají stejně jako při zaúčtování
 * ({@see \MyInvoice\Service\Accounting\Dimension\DimensionStamper}, kontrola
 * {@see \MyInvoice\Service\PurchaseInvoice\Review\RequiredDimensionReviewCheck}):
 * položka (hodnota nebo rozpad) > produkt (> kategorie) > hlavička dokladu (hodnota
 * nebo rozpad) > zakázka > dodavatel. Hodnoty pravidel podle účtu se nepoužijí —
 * vznikají až v zaúčtování, které schválení předchází.
 *
 * Částka hodnoty = součet základů položek (bez DPH) s touto hodnotou, u rozpadu
 * jejich podíl; doklad bez položek nese celý základ v hlavičce. Přepočet na Kč
 * kurzem dokladu (Kč = 1). Doklad v cizí měně bez kurzu nejde ocenit — limit se
 * u něj neuplatní (schvaluje se vždy), ať ho neprokázaná částka nepustí bez schválení.
 *
 * Neschvaluje se: daňový doklad k přijaté platbě (záloha už prošla schválením
 * jako zálohová faktura) a hodnota s nulovým nebo záporným základem (dobropis
 * náklad nezvyšuje).
 */
final class PurchaseApprovalRequirements
{
    private const SKIPPED_KINDS = ['tax_document'];

    public function __construct(private readonly Connection $db) {}

    /**
     * Typy dimenzí firmy se zapnutým schvalováním (jen aktivní, jen se zapnutou
     * sekcí Dimenze). Prázdné pole = schvalování je vypnuté.
     *
     * @return array<int,array{id:int, name:string, threshold:float}>
     */
    public function approvalTypes(int $supplierId): array
    {
        $dimRepo = new DimensionRepository($this->db);
        if (!$dimRepo->enabled($supplierId)) {
            return [];
        }
        [$vis, $params] = $dimRepo->visibleSql($supplierId, 't');
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.id, t.name, t.approval_threshold FROM dimension_types t
              WHERE {$vis} AND t.is_active = 1 AND t.requires_approval = 1"
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'threshold' => $r['approval_threshold'] !== null ? (float) $r['approval_threshold'] : 0.0,
            ];
        }
        return $out;
    }

    /**
     * @return list<array{type_id:int, type_name:string, value_id:int, value_code:string, value_name:string,
     *                    approver_user_id:?int, approver_name:?string, approver_email:?string, amount_czk:float}>
     */
    public function forInvoice(int $supplierId, int $invoiceId): array
    {
        $types = $this->approvalTypes($supplierId);
        if ($types === []) {
            return [];
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT pi.id, pi.vendor_id, pi.project_id, pi.document_kind, pi.exchange_rate, pi.total_without_vat,
                    cur.code AS currency
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.id = ? AND pi.supplier_id = ?'
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv === false || in_array((string) ($inv['document_kind'] ?? 'invoice'), self::SKIPPED_KINDS, true)) {
            return [];
        }

        $rate = 1.0;
        $rateKnown = true;
        if ((string) $inv['currency'] !== 'CZK') {
            $r = $inv['exchange_rate'] !== null ? (float) $inv['exchange_rate'] : 0.0;
            if ($r > 0) {
                $rate = $r;
            } else {
                $rateKnown = false;
            }
        }

        $items = $pdo->prepare(
            'SELECT pii.id, pii.stock_item_id, pii.total_without_vat
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id AND pi.supplier_id = ?
              WHERE pii.purchase_invoice_id = ?
              ORDER BY pii.order_index, pii.id'
        );
        $items->execute([$supplierId, $invoiceId]);
        $items = $items->fetchAll(PDO::FETCH_ASSOC);

        $assignments = new DimensionAssignmentRepository($this->db);
        $own = $assignments->documentDimensions($supplierId, 'purchase_invoice', $invoiceId);
        $splits = $assignments->documentSplits($supplierId, 'purchase_invoice', $invoiceId);
        $defaults = new DimensionDefaults($this->db);
        $party = $defaults->resolve(
            $supplierId,
            $inv['vendor_id'] !== null ? (int) $inv['vendor_id'] : null,
            $inv['project_id'] !== null ? (int) $inv['project_id'] : null,
        )['header'];
        $header = DimensionDefaults::layer([
            ['header' => $own['header'], 'splits' => $splits[0] ?? []],
            ['header' => $party, 'splits' => []],
        ]);
        $products = $defaults->forProducts($supplierId, array_map(static fn (array $r): int => (int) ($r['stock_item_id'] ?? 0), $items));

        /** @var array<int,array<int,float>> $amounts typ => hodnota => základ v měně dokladu */
        $amounts = [];
        $add = static function (int $typeId, array $shares, float $base) use (&$amounts): void {
            foreach ($shares as $valueId => $share) {
                $amounts[$typeId][(int) $valueId] = ($amounts[$typeId][(int) $valueId] ?? 0.0) + $base * (float) $share;
            }
        };
        foreach (array_keys($types) as $typeId) {
            if ($items === []) {
                $shares = self::shares($typeId, $header['header'], $header['splits']);
                if ($shares !== null) {
                    $add($typeId, $shares, (float) $inv['total_without_vat']);
                }
                continue;
            }
            foreach ($items as $i => $row) {
                $itemNo = $i + 1;
                $product = $products[(int) ($row['stock_item_id'] ?? 0)]['header'] ?? [];
                $shares = self::shares($typeId, $own['items'][$itemNo] ?? [], $splits[$itemNo] ?? [])
                    ?? (isset($product[$typeId]) ? [(int) $product[$typeId] => 1.0] : null)
                    ?? self::shares($typeId, $header['header'], $header['splits']);
                if ($shares !== null) {
                    $add($typeId, $shares, (float) $row['total_without_vat']);
                }
            }
        }
        if ($amounts === []) {
            return [];
        }

        $valueIds = [];
        foreach ($amounts as $byValue) {
            array_push($valueIds, ...array_keys($byValue));
        }
        $values = $this->values($supplierId, $valueIds);

        $out = [];
        foreach ($amounts as $typeId => $byValue) {
            $threshold = $types[$typeId]['threshold'];
            foreach ($byValue as $valueId => $base) {
                $value = $values[$valueId] ?? null;
                if ($value === null || $value['type_id'] !== $typeId) {
                    continue;
                }
                $czk = round($base * $rate, 2);
                if ($czk <= 0.0) {
                    continue;
                }
                if ($rateKnown && $threshold > 0 && $czk < $threshold) {
                    continue;
                }
                $out[] = [
                    'type_id' => $typeId,
                    'type_name' => $types[$typeId]['name'],
                    'value_id' => $valueId,
                    'value_code' => $value['code'],
                    'value_name' => $value['name'],
                    'approver_user_id' => $value['approver_user_id'],
                    'approver_name' => $value['approver_name'],
                    'approver_email' => $value['approver_email'],
                    'amount_czk' => $czk,
                ];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['type_id'], $a['value_code']] <=> [$b['type_id'], $b['value_code']]);
        return $out;
    }

    /**
     * Hodnota nebo rozpad typu na jedné vrstvě; null = vrstva typ nenese.
     *
     * @param array<int,int> $dims typ => hodnota
     * @param array<int,array<int,float>> $splits typ => hodnota => podíl
     * @return array<int,float>|null hodnota => podíl
     */
    private static function shares(int $typeId, array $dims, array $splits): ?array
    {
        if (isset($dims[$typeId])) {
            return [(int) $dims[$typeId] => 1.0];
        }
        if (!empty($splits[$typeId])) {
            return $splits[$typeId];
        }
        return null;
    }

    /**
     * Hodnoty se schvalovatelem. Neaktivní uživatel schvalovat nemůže — hodnota se
     * pak tváří jako bez schvalovatele, ať doklad neuvízne u někoho, kdo se už
     * nepřihlásí.
     *
     * @param list<int> $ids
     * @return array<int,array{type_id:int, code:string, name:string, approver_user_id:?int, approver_name:?string, approver_email:?string}>
     */
    private function values(int $supplierId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        [$vis, $params] = (new DimensionRepository($this->db))->visibleSql($supplierId, 'v');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT v.id, v.type_id, v.code, v.name, u.id AS user_id, u.name AS user_name, u.email AS user_email
               FROM dimension_values v
          LEFT JOIN users u ON u.id = v.responsible_user_id AND u.is_active = 1
              WHERE v.id IN ({$marks}) AND {$vis}"
        );
        $stmt->execute([...$ids, ...$params]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = [
                'type_id' => (int) $r['type_id'],
                'code' => (string) $r['code'],
                'name' => (string) $r['name'],
                'approver_user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
                'approver_name' => $r['user_id'] !== null ? (string) ($r['user_name'] ?: $r['user_email']) : null,
                'approver_email' => $r['user_id'] !== null ? (string) $r['user_email'] : null,
            ];
        }
        return $out;
    }
}
