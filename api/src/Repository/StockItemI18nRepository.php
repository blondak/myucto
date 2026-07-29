<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_item_i18n — překlady karty zboží 1:N (Epic ESHOP).
 * Per tenant (supplier_id). UNIQUE (stock_item_id, locale). Fallback řetěz
 * (locale → cs → stock_items.name) řeší čtecí vrstva (ProductCardService).
 */
final class StockItemI18nRepository
{
    private const COLUMNS =
        'id, supplier_id, stock_item_id, locale, name, short_desc, description,
         seo_title, seo_description, seo_slug, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForItem(int $supplierId, int $stockItemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_item_i18n
              WHERE supplier_id = ? AND stock_item_id = ?
              ORDER BY locale ASC'
        );
        $stmt->execute([$supplierId, $stockItemId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Upsert překladu pro (item, locale). Zápis přes UNIQUE (stock_item_id, locale).
     * @param array{name:string, short_desc?:?string, description?:?string,
     *              seo_title?:?string, seo_description?:?string, seo_slug?:?string} $data
     */
    public function upsert(int $supplierId, int $stockItemId, string $locale, array $data): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_i18n
                (supplier_id, stock_item_id, locale, name, short_desc, description,
                 seo_title, seo_description, seo_slug)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name), short_desc = VALUES(short_desc),
                description = VALUES(description), seo_title = VALUES(seo_title),
                seo_description = VALUES(seo_description), seo_slug = VALUES(seo_slug)'
        )->execute([
            $supplierId,
            $stockItemId,
            $locale,
            (string) $data['name'],
            $data['short_desc'] ?? null,
            $data['description'] ?? null,
            $data['seo_title'] ?? null,
            $data['seo_description'] ?? null,
            $data['seo_slug'] ?? null,
        ]);
    }

    public function deleteLocale(int $supplierId, int $stockItemId, string $locale): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_item_i18n
              WHERE supplier_id = ? AND stock_item_id = ? AND locale = ?'
        );
        $stmt->execute([$supplierId, $stockItemId, $locale]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['stock_item_id'] = (int) $r['stock_item_id'];
        return $r;
    }
}
