<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_item_vendors — dodavatelé zboží M:N na clients (is_vendor)
 * (Epic ESHOP). Per tenant (supplier_id). purchase_price je money → string.
 * Skladovost/lhůta u dodavatele slouží i neskladovému zboží (is_stocked=0, E5).
 */
final class StockItemVendorRepository
{
    private const COLUMNS =
        'id, supplier_id, stock_item_id, client_id, vendor_sku, purchase_price,
         currency_code, delivery_days, stock_qty, is_preferred, note, updated_at';

    public function __construct(private readonly Connection $db) {}

    /** Dodavatelé karty vč. názvu klienta. @return list<array<string,mixed>> */
    public function listForItem(int $supplierId, int $stockItemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT v.id, v.supplier_id, v.stock_item_id, v.client_id, v.vendor_sku,
                    v.purchase_price, v.currency_code, v.delivery_days, v.stock_qty,
                    v.is_preferred, v.note, v.updated_at,
                    c.company_name AS client_name
               FROM stock_item_vendors v
               JOIN clients c ON c.id = v.client_id AND c.supplier_id = v.supplier_id
              WHERE v.supplier_id = ? AND v.stock_item_id = ?
              ORDER BY v.is_preferred DESC, c.company_name ASC'
        );
        $stmt->execute([$supplierId, $stockItemId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Preferovaný dodavatel (pro pricing_base=manual): purchase_price + měna.
     * Preferuje is_preferred=1, jinak nejlevnější s cenou. Null bez ceny.
     * @return array{purchase_price:string, currency_code:string}|null
     */
    public function preferredPurchase(int $supplierId, int $stockItemId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT purchase_price, currency_code FROM stock_item_vendors
              WHERE supplier_id = ? AND stock_item_id = ? AND purchase_price IS NOT NULL
              ORDER BY is_preferred DESC, purchase_price ASC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $stockItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'purchase_price' => (string) $row['purchase_price'],
            'currency_code'  => (string) $row['currency_code'],
        ];
    }

    public function deleteForItem(int $supplierId, int $stockItemId): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM stock_item_vendors WHERE supplier_id = ? AND stock_item_id = ?'
        )->execute([$supplierId, $stockItemId]);
    }

    /**
     * @param array{client_id:int, vendor_sku?:?string, purchase_price?:?string,
     *              currency_code?:string, delivery_days?:?int, stock_qty?:?string,
     *              is_preferred?:bool, note?:?string} $data
     */
    public function add(int $supplierId, int $stockItemId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_item_vendors
                (supplier_id, stock_item_id, client_id, vendor_sku, purchase_price,
                 currency_code, delivery_days, stock_qty, is_preferred, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $stockItemId,
            (int) $data['client_id'],
            $data['vendor_sku'] ?? null,
            isset($data['purchase_price']) && $data['purchase_price'] !== null ? (string) $data['purchase_price'] : null,
            (string) ($data['currency_code'] ?? 'CZK'),
            isset($data['delivery_days']) && $data['delivery_days'] !== null ? (int) $data['delivery_days'] : null,
            isset($data['stock_qty']) && $data['stock_qty'] !== null ? (string) $data['stock_qty'] : null,
            (int) ($data['is_preferred'] ?? false),
            $data['note'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Klienti (id) patřící tenantovi a označení is_vendor=1 — guard proti
     * cross-tenant / ne-dodavatelské vazbě.
     * @param list<int> $clientIds
     * @return list<int>
     */
    public function filterOwnedVendors(int $supplierId, array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $clientIds)));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM clients
              WHERE supplier_id = ? AND is_vendor = 1 AND id IN (' . $ph . ')'
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
        $r['client_id'] = (int) $r['client_id'];
        $r['delivery_days'] = $r['delivery_days'] !== null ? (int) $r['delivery_days'] : null;
        $r['is_preferred'] = (bool) $r['is_preferred'];
        // purchase_price, stock_qty zůstávají string (money/qty-safe).
        return $r;
    }
}
