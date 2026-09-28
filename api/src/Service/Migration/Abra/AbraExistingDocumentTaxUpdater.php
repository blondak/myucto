<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\OssMigrationPolicy;
use MyInvoice\Service\Migration\Shared\MigratedPaymentWriter;
use PDO;

final class AbraExistingDocumentTaxUpdater
{
    public function __construct(
        private readonly Connection $db,
        private readonly AbraTaxPlanner $planner,
        private readonly MigratedPaymentWriter $payments,
    ) {}

    /** @param array<string,mixed> $plan @return array{updated:bool,reason:string,warnings:list<string>,oss_items:int} */
    public function update(int $supplierId, int $targetId, array $plan): array
    {
        $result = ['updated' => false, 'reason' => 'not_eligible', 'warnings' => [], 'oss_items' => 0];
        if ($targetId < 1 || $plan['blockers'] !== []) return $result;
        $issued = $plan['kind'] === 'issued';
        $table = $issued ? 'invoices' : 'purchase_invoices';
        $itemTable = $issued ? 'invoice_items' : 'purchase_invoice_items';
        $owner = $issued ? 'client_id' : 'vendor_id';
        $itemOwner = $issued ? 'invoice_id' : 'purchase_invoice_id';
        $map = $this->db->pdo()->prepare('SELECT target_id FROM abra_flexi_import_map
            WHERE supplier_id = ? AND kind = ? AND abra_key = ? AND source_hash = ?');
        $map->execute([$supplierId, $plan['evidence'], $plan['source_key'], $plan['source_hash']]);
        if ((int) $map->fetchColumn() !== $targetId) return $result;
        $doc = $this->db->pdo()->prepare("SELECT d.*, c.code AS currency_code FROM {$table} d
            JOIN currencies c ON c.id = d.currency_id AND c.supplier_id = d.supplier_id
            WHERE d.supplier_id = ? AND d.id = ?");
        $doc->execute([$supplierId, $targetId]);
        $target = $doc->fetch(PDO::FETCH_ASSOC);
        if ($target === false || $target['status'] !== 'draft' || $target['booked_at'] !== null
            || $target['issue_date'] !== $plan['date']
            || abs((float) $target['total_with_vat'] - (float) ($issued
                ? $plan['total_with_vat'] : ($plan['reverse_charge']
                    ? $plan['legacy_stored_total_with_vat'] : $plan['stored_total_with_vat']))) > 0.02) {
            return $result;
        }
        $itemsQuery = $this->db->pdo()->prepare("SELECT * FROM {$itemTable} WHERE {$itemOwner} = ? ORDER BY order_index, id");
        $itemsQuery->execute([$targetId]);
        $targetItems = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        if (count($targetItems) !== count($plan['items'])) return $result;
        $roundingDraft = !$issued && $plan['status'] !== 'draft'
            && array_any($plan['items'], static fn (array $item): bool =>
                $item['source_vat_class'] === '40-41' && $item['vat_rate'] === 0.0
                && abs($item['base']) <= 1.0 && $item['vat'] === 0.0
                && !$item['vat_requires_review']);
        foreach ($targetItems as $index => $item) {
            $source = $plan['items'][$index];
            if ((int) $item['order_index'] !== $index
                || ($item['vat_classification_code'] !== null
                    && (!$roundingDraft || $item['vat_classification_code'] !== $source['vat_classification']))
                || ($issued && (int) $item['oss_applicable'] !== 0)
                || abs((float) $item['total_without_vat'] - (float) $source['base']) > 0.02
                || abs((float) $item['total_vat'] - (float) ($source['source_vat'] ?? $source['vat'])) > 0.02
                || abs((float) $item['total_with_vat'] - (float) ($source['source_total'] ?? $source['total'])) > 0.02) {
                return $result;
            }
        }
        $decision = $this->planner->plan($supplierId, (int) $target[$owner], $plan);
        $result['warnings'] = $decision['warnings'];
        $result['oss_items'] = $decision['oss_items'];
        $ready = $decision['plan'];
        if ($ready['status'] === 'draft') {
            $result['reason'] = 'tax_review';
            return $result;
        }
        foreach ($targetItems as $index => $item) {
            $source = $ready['items'][$index];
            if ($issued) {
                $oss = $source['oss'] ?? OssMigrationPolicy::DOMESTIC_COLUMNS;
                $this->db->pdo()->prepare('UPDATE invoice_items SET vat_classification_code = ?,
                    vat_rate_id = COALESCE(?, vat_rate_id),
                    vat_rate_snapshot = CASE WHEN ? IS NULL THEN vat_rate_snapshot ELSE ? END,
                    oss_applicable = ?, oss_consumer_country = ?, oss_rate_type = ?, oss_supply_type = ?,
                    oss_needs_manual_review = ?, oss_taxable_amount_return = ?, oss_vat_amount_return = ?
                    WHERE id = ? AND invoice_id = ?')->execute([
                    $source['vat_classification'], $source['oss_rate_id'] ?? $source['foreign_rate_id'] ?? null,
                    $source['oss_rate_id'] ?? $source['foreign_rate_id'] ?? null, $source['vat_rate'],
                    $oss['oss_applicable'], $oss['oss_consumer_country'], $oss['oss_rate_type'],
                    $oss['oss_supply_type'], $oss['oss_needs_manual_review'],
                    $oss['oss_taxable_amount_return'] ?? null, $oss['oss_vat_amount_return'] ?? null,
                    $item['id'], $targetId,
                ]);
            } else {
                $fixed = (bool) ($source['is_fixed_asset'] ?? false);
                $this->db->pdo()->prepare('UPDATE purchase_invoice_items
                    SET vat_classification_code = ?, is_fixed_asset = ?, total_vat = ?, total_with_vat = ?,
                        unit_price_without_vat = ?,
                        expense_kind = CASE WHEN ? = 1 THEN "fixed_asset" ELSE expense_kind END
                    WHERE id = ? AND purchase_invoice_id = ?')->execute([
                    $source['vat_classification'], $fixed ? 1 : 0, $source['vat'], $source['total'],
                    $source['unit_price'], $fixed ? 1 : 0,
                    $item['id'], $targetId,
                ]);
            }
        }
        if ($issued) {
            $this->db->pdo()->prepare('UPDATE invoices SET status = ?, booked_at = ?,
                booked_by = CASE WHEN ? IS NULL THEN NULL ELSE created_by END, reverse_charge = ?
                WHERE id = ? AND supplier_id = ? AND status = "draft"')->execute([
                $ready['status'], $ready['booked_at'], $ready['booked_at'],
                $ready['reverse_charge'] ? 1 : 0, $targetId, $supplierId,
            ]);
        } else {
            $this->db->pdo()->prepare('UPDATE purchase_invoices SET status = ?, booked_at = ?,
                booked_by = CASE WHEN ? IS NULL THEN NULL ELSE created_by END,
                vat_deduction = ?, reverse_charge = ?, is_fixed_asset = ?,
                total_vat = ?, total_with_vat = ?, rounding = ?, prices_include_vat = ?
                WHERE id = ? AND supplier_id = ? AND status = "draft"')->execute([
                $ready['status'], $ready['booked_at'], $ready['booked_at'], $ready['vat_deduction'],
                $ready['reverse_charge'] ? 1 : 0, $ready['is_fixed_asset'] ? 1 : 0,
                $ready['total_vat'], $ready['stored_total_with_vat'], $ready['rounding'],
                $ready['prices_include_vat'] ? 1 : 0,
                $targetId, $supplierId,
            ]);
        }
        if ($target['currency_code'] === 'CZK') {
            $this->payments->refreshBalances($supplierId, $issued ? [$targetId] : [],
                $issued ? [] : [$targetId], []);
        }
        return ['updated' => true, 'reason' => 'updated', 'warnings' => $decision['warnings'],
            'oss_items' => $decision['oss_items']];
    }
}
