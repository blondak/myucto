<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_category_i18n — překlady kategorií 1:N (Epic ESHOP).
 * Per tenant (supplier_id). UNIQUE (category_id, locale).
 */
final class StockCategoryI18nRepository
{
    private const COLUMNS =
        'id, supplier_id, category_id, locale, name, description, seo_slug';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForCategory(int $supplierId, int $categoryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_category_i18n
              WHERE supplier_id = ? AND category_id = ?
              ORDER BY locale ASC'
        );
        $stmt->execute([$supplierId, $categoryId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array{name:string, description?:?string, seo_slug?:?string} $data
     */
    public function upsert(int $supplierId, int $categoryId, string $locale, array $data): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO stock_category_i18n (supplier_id, category_id, locale, name, description, seo_slug)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), seo_slug = VALUES(seo_slug)'
        )->execute([
            $supplierId,
            $categoryId,
            $locale,
            (string) $data['name'],
            $data['description'] ?? null,
            $data['seo_slug'] ?? null,
        ]);
    }

    public function deleteLocale(int $supplierId, int $categoryId, string $locale): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_category_i18n WHERE supplier_id = ? AND category_id = ? AND locale = ?'
        );
        $stmt->execute([$supplierId, $categoryId, $locale]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['category_id'] = (int) $r['category_id'];
        return $r;
    }
}
