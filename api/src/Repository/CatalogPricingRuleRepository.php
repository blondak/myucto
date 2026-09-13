<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class CatalogPricingRuleRepository
{
    private const COLUMNS = 'r.id, r.supplier_id, r.profile_id, r.match_type, r.match_id,
        r.priority, r.is_active, r.created_at, r.updated_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . '
            FROM stock_pricing_rules r WHERE r.supplier_id = ? AND r.id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function listForSupplier(int $supplierId, bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM stock_pricing_rules r WHERE r.supplier_id = ?';
        if ($activeOnly) {
            $sql .= ' AND r.is_active = 1';
        }
        $sql .= " ORDER BY FIELD(r.match_type, 'product','category','manufacturer','vendor','default'),
            r.priority DESC, r.id ASC";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map(self::cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO stock_pricing_rules
            (supplier_id, profile_id, match_type, match_id, priority, is_active)
            VALUES (?, ?, ?, ?, ?, ?)')->execute([
                $supplierId, $data['profile_id'], $data['match_type'], $data['match_id'],
                $data['priority'], (int) $data['is_active'],
            ]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare('UPDATE stock_pricing_rules SET
            profile_id = ?, match_type = ?, match_id = ?, priority = ?, is_active = ?
            WHERE supplier_id = ? AND id = ?');
        $stmt->execute([
            $data['profile_id'], $data['match_type'], $data['match_id'], $data['priority'],
            (int) $data['is_active'], $supplierId, $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_pricing_rules WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        return $stmt->rowCount() > 0;
    }

    public function itemContext(int $supplierId, int $stockItemId): array
    {
        return $this->itemContexts($supplierId, [$stockItemId])[$stockItemId];
    }

    /**
     * Kontext shody pravidel pro víc karet najednou (výrobce zděděný z hlavního
     * produktu, kategorie, dodavatelé s nákupní cenou) — tři dotazy bez ohledu na
     * počet karet. Karta, která neexistuje, dostane prázdný kontext.
     *
     * @param list<int> $stockItemIds
     * @return array<int, array{product_id:int, manufacturer_id:?int, category_ids:list<int>, vendor_ids:list<int>}>
     */
    public function itemContexts(int $supplierId, array $stockItemIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $stockItemIds)));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['product_id' => $id, 'manufacturer_id' => null, 'category_ids' => [], 'vendor_ids' => []];
        }
        if ($ids === []) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$supplierId], $ids);

        $stmt = $this->db->pdo()->prepare('SELECT s.id, CASE WHEN v.inherit_manufacturer = 1 THEN m.manufacturer_id ELSE s.manufacturer_id END AS manufacturer_id
            FROM stock_items s
            LEFT JOIN product_variants v ON v.supplier_id = s.supplier_id AND v.stock_item_id = s.id
            LEFT JOIN product_masters m ON m.supplier_id = v.supplier_id AND m.id = v.master_id
            WHERE s.supplier_id = ? AND s.id IN (' . $in . ')');
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']]['manufacturer_id'] = $r['manufacturer_id'] === null ? null : (int) $r['manufacturer_id'];
        }

        $stmt = $this->db->pdo()->prepare('SELECT stock_item_id, category_id FROM stock_item_categories
            WHERE supplier_id = ? AND stock_item_id IN (' . $in . ')');
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['stock_item_id']]['category_ids'][] = (int) $r['category_id'];
        }

        $stmt = $this->db->pdo()->prepare('SELECT stock_item_id, client_id FROM stock_item_vendors
            WHERE supplier_id = ? AND stock_item_id IN (' . $in . ') AND purchase_price IS NOT NULL
            ORDER BY is_preferred DESC, purchase_price ASC, id ASC');
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['stock_item_id']]['vendor_ids'][] = (int) $r['client_id'];
        }

        return $out;
    }

    private static function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'profile_id', 'priority'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['match_id'] = $row['match_id'] === null ? null : (int) $row['match_id'];
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }
}
