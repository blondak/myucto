<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_item_tags — M:N zboží ↔ štítky (Epic ESHOP).
 * Denormalizovaný supplier_id pro tenant filtr bez joinu. Sync přes
 * delete+insert v transakci (ProductCardService).
 */
final class StockItemTagRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<int> tag_id přiřazené kartě */
    public function tagIdsForItem(int $supplierId, int $stockItemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT tag_id FROM stock_item_tags
              WHERE supplier_id = ? AND stock_item_id = ?
              ORDER BY tag_id ASC'
        );
        $stmt->execute([$supplierId, $stockItemId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function deleteForItem(int $supplierId, int $stockItemId): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM stock_item_tags WHERE supplier_id = ? AND stock_item_id = ?'
        )->execute([$supplierId, $stockItemId]);
    }

    public function add(int $supplierId, int $stockItemId, int $tagId): void
    {
        $this->db->pdo()->prepare(
            'INSERT IGNORE INTO stock_item_tags (supplier_id, stock_item_id, tag_id)
             VALUES (?, ?, ?)'
        )->execute([$supplierId, $stockItemId, $tagId]);
    }

    /**
     * Ověří, že všechna tag_id patří tenantovi (guard proti cross-tenant vazbě).
     * @param list<int> $tagIds
     * @return list<int> validní tag_id patřící supplierovi
     */
    public function filterOwned(int $supplierId, array $tagIds): array
    {
        if ($tagIds === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $tagIds)));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM stock_tags WHERE supplier_id = ? AND id IN (' . $ph . ')'
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
}
