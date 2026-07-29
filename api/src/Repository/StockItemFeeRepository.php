<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_item_fees — poplatky přiřazené kartě (Epic ESHOP).
 * Per tenant (supplier_id). amount je money DECIMAL → drží se jako string
 * (money-safe). Sync přes delete+insert v transakci (ProductCardService).
 */
final class StockItemFeeRepository
{
    private const COLUMNS =
        'id, supplier_id, stock_item_id, fee_type_id, amount, currency_code, vat_included';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForItem(int $supplierId, int $stockItemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_item_fees
              WHERE supplier_id = ? AND stock_item_id = ?
              ORDER BY id ASC'
        );
        $stmt->execute([$supplierId, $stockItemId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function deleteForItem(int $supplierId, int $stockItemId): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM stock_item_fees WHERE supplier_id = ? AND stock_item_id = ?'
        )->execute([$supplierId, $stockItemId]);
    }

    /**
     * @param array{fee_type_id:int, amount:string, currency_code?:string, vat_included?:bool} $data
     */
    public function add(int $supplierId, int $stockItemId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_item_fees
                (supplier_id, stock_item_id, fee_type_id, amount, currency_code, vat_included)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $stockItemId,
            (int) $data['fee_type_id'],
            (string) $data['amount'],
            (string) ($data['currency_code'] ?? 'CZK'),
            (int) ($data['vat_included'] ?? false),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Ověří, že fee_type_id patří tenantovi (guard proti cross-tenant vazbě).
     * @param list<int> $feeTypeIds
     * @return list<int>
     */
    public function filterOwnedTypes(int $supplierId, array $feeTypeIds): array
    {
        if ($feeTypeIds === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $feeTypeIds)));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM stock_fee_types WHERE supplier_id = ? AND id IN (' . $ph . ')'
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['stock_item_id'] = (int) $r['stock_item_id'];
        $r['fee_type_id'] = (int) $r['fee_type_id'];
        $r['vat_included'] = (bool) $r['vat_included'];
        // amount zůstává string (money-safe).
        return $r;
    }
}
