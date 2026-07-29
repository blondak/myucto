<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_item_categories — M:N zboží ↔ kategorie s příznakem
 * is_primary (Epic ESHOP). Max 1 primární kategorie na kartu vynucuje
 * aplikace (ProductCardService). Denormalizovaný supplier_id.
 */
final class StockItemCategoryRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array{category_id:int, is_primary:bool, display_order:int}> */
    public function listForItem(int $supplierId, int $stockItemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT category_id, is_primary, display_order FROM stock_item_categories
              WHERE supplier_id = ? AND stock_item_id = ?
              ORDER BY is_primary DESC, display_order ASC, category_id ASC'
        );
        $stmt->execute([$supplierId, $stockItemId]);
        return array_map(static fn (array $r): array => [
            'category_id'   => (int) $r['category_id'],
            'is_primary'    => (bool) $r['is_primary'],
            'display_order' => (int) $r['display_order'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function deleteForItem(int $supplierId, int $stockItemId): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM stock_item_categories WHERE supplier_id = ? AND stock_item_id = ?'
        )->execute([$supplierId, $stockItemId]);
    }

    public function add(int $supplierId, int $stockItemId, int $categoryId, bool $isPrimary, int $displayOrder): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_categories
                (supplier_id, stock_item_id, category_id, is_primary, display_order)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_primary = VALUES(is_primary), display_order = VALUES(display_order)'
        )->execute([$supplierId, $stockItemId, $categoryId, (int) $isPrimary, $displayOrder]);
    }

    /**
     * Ověří, že category_id patří tenantovi (guard proti cross-tenant vazbě).
     * @param list<int> $categoryIds
     * @return list<int> validní category_id patřící supplierovi
     */
    public function filterOwned(int $supplierId, array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $categoryIds)));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM stock_categories WHERE supplier_id = ? AND id IN (' . $ph . ')'
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
}
