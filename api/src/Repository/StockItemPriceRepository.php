<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_item_prices — cenotvorba karty per měna (Epic ESHOP).
 * Per tenant (supplier_id). UNIQUE (stock_item_id, currency_code). Peněžní
 * pole (markup_pct, fixed_price, computed_*) drží jako string (money-safe).
 */
final class StockItemPriceRepository
{
    private const COLUMNS =
        'id, supplier_id, stock_item_id, currency_code, price_mode, markup_pct,
         fixed_price, rounding, computed_price, computed_base, computed_rate,
         computed_at, is_manual_override, updated_at';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForItem(int $supplierId, int $stockItemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_item_prices
              WHERE supplier_id = ? AND stock_item_id = ?
              ORDER BY currency_code ASC'
        );
        $stmt->execute([$supplierId, $stockItemId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findByCurrency(int $supplierId, int $stockItemId, string $currency): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_item_prices
              WHERE supplier_id = ? AND stock_item_id = ? AND currency_code = ?'
        );
        $stmt->execute([$supplierId, $stockItemId, $currency]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * Upsert definice ceny (mode/markup/fixed/rounding/override) — NEpřepisuje
     * computed_* (ty nastavuje updateComputed po přepočtu).
     * @param array{price_mode:string, markup_pct:?string, fixed_price:?string,
     *              rounding:string, is_manual_override:bool} $data
     */
    public function upsert(int $supplierId, int $stockItemId, string $currency, array $data): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_prices
                (supplier_id, stock_item_id, currency_code, price_mode, markup_pct,
                 fixed_price, rounding, is_manual_override)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                price_mode = VALUES(price_mode), markup_pct = VALUES(markup_pct),
                fixed_price = VALUES(fixed_price), rounding = VALUES(rounding),
                is_manual_override = VALUES(is_manual_override)'
        )->execute([
            $supplierId,
            $stockItemId,
            $currency,
            (string) $data['price_mode'],
            $data['markup_pct'] !== null ? (string) $data['markup_pct'] : null,
            $data['fixed_price'] !== null ? (string) $data['fixed_price'] : null,
            (string) $data['rounding'],
            (int) $data['is_manual_override'],
        ]);
    }

    /** Zapíše výsledek přepočtu (computed_*) — po PriceCalculationService. */
    public function updateComputed(
        int $supplierId,
        int $id,
        ?string $computedPrice,
        ?string $computedBase,
        ?string $computedRate,
        string $computedAt,
    ): void {
        $this->db->pdo()->prepare(
            'UPDATE stock_item_prices
                SET computed_price = ?, computed_base = ?, computed_rate = ?, computed_at = ?
              WHERE id = ? AND supplier_id = ?'
        )->execute([
            $computedPrice,
            $computedBase,
            $computedRate,
            $computedAt,
            $id,
            $supplierId,
        ]);
    }

    /** Karty firmy, které mají aspoň jeden cenový řádek (pro dávkový přepočet). @return list<int> */
    public function itemIdsWithPrices(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT stock_item_id FROM stock_item_prices WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function delete(int $supplierId, int $stockItemId, string $currency): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_item_prices
              WHERE supplier_id = ? AND stock_item_id = ? AND currency_code = ?'
        );
        $stmt->execute([$supplierId, $stockItemId, $currency]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['stock_item_id'] = (int) $r['stock_item_id'];
        $r['is_manual_override'] = (bool) $r['is_manual_override'];
        // markup_pct, fixed_price, computed_* zůstávají string (money-safe).
        return $r;
    }
}
