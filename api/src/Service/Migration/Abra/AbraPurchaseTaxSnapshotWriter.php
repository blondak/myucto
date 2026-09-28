<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class AbraPurchaseTaxSnapshotWriter
{
    public function __construct(private readonly Connection $db) {}

    public function apply(int $supplierId, int $purchaseId, array $plan): array
    {
        if ($plan['kind'] !== 'purchase') return ['updated' => 0, 'matched' => true];
        $stmt = $this->db->pdo()->prepare('SELECT i.id, i.order_index, i.total_without_vat, i.total_vat,
                i.total_with_vat, i.import_tax_base_czk, i.import_tax_vat_czk, i.import_tax_excluded
            FROM purchase_invoice_items i
            JOIN purchase_invoices p ON p.id = i.purchase_invoice_id AND p.supplier_id = ?
            WHERE i.purchase_invoice_id = ? ORDER BY i.order_index, i.id');
        $stmt->execute([$supplierId, $purchaseId]);
        $target = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($target) !== count($plan['items'])) return ['updated' => 0, 'matched' => false];
        foreach ($target as $index => $row) {
            $source = $plan['items'][$index];
            if ((int) $row['order_index'] !== $index
                || abs((float) $row['total_without_vat'] - $source['base']) > 0.02
                || abs((float) $row['total_vat'] - $source['vat']) > 0.02
                || abs((float) $row['total_with_vat'] - $source['total']) > 0.02) {
                return ['updated' => 0, 'matched' => false];
            }
        }
        $update = $this->db->pdo()->prepare('UPDATE purchase_invoice_items
            SET import_tax_base_czk = ?, import_tax_vat_czk = ?, import_tax_excluded = ?
            WHERE purchase_invoice_id = ? AND id = ?');
        $count = 0;
        foreach ($target as $index => $row) {
            $source = $plan['items'][$index];
            $base = $source['import_tax_base_czk'] ?? null;
            $vat = $source['import_tax_vat_czk'] ?? null;
            $excluded = !empty($source['import_tax_excluded']) ? 1 : 0;
            if (($base === null ? $row['import_tax_base_czk'] === null
                    : $row['import_tax_base_czk'] !== null && abs((float) $row['import_tax_base_czk'] - $base) < 0.005)
                && ($vat === null ? $row['import_tax_vat_czk'] === null
                    : $row['import_tax_vat_czk'] !== null && abs((float) $row['import_tax_vat_czk'] - $vat) < 0.005)
                && (int) $row['import_tax_excluded'] === $excluded) continue;
            $update->execute([$base, $vat, $excluded, $purchaseId, $row['id']]);
            ++$count;
        }
        return ['updated' => $count, 'matched' => true];
    }
}
