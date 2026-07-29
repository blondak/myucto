<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_items — skladové karty (Epic SKLAD).
 * Per tenant (supplier_id); UNIQUE (supplier_id, sku). Peněžní/množstevní
 * DECIMAL sloupce (sale_price_without_vat, min_qty) se drží jako string —
 * žádné floatování, přesnost řeší volající vrstva (money-safe vzor).
 */
final class StockItemRepository
{
    private const COLUMNS =
        'id, supplier_id, sku, name, item_type, manufacturer_id, unit, ean, vat_rate_id,
         sale_price_without_vat, min_qty, warranty_months, delivery_days, export_eshop,
         is_stocked, weight_g, pricing_base, is_active, note, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_items WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function findBySku(int $supplierId, string $sku): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_items WHERE supplier_id = ? AND sku = ?'
        );
        $stmt->execute([$supplierId, $sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * @param array{type?:string, active?:bool, q?:string, only_below_min?:bool,
     *              limit?:int, offset?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, array $filters = []): array
    {
        $parts = $this->buildListQueryParts($supplierId, $filters);
        $cols = implode(', ', array_map(
            static fn (string $c): string => 'si.' . trim($c),
            explode(',', self::COLUMNS)
        ));

        $sql = 'SELECT ' . $cols . ' FROM stock_items si' . $parts['join']
             . ' WHERE ' . $parts['where']
             . ' ORDER BY si.name ASC';

        // LIMIT/OFFSET inlinujeme jako validované inty (vzor DocumentRepository::search) —
        // native prepared statements neumí LIMIT/OFFSET s parametrem typu string.
        if (isset($filters['limit'])) {
            $sql .= ' LIMIT ' . max(1, (int) $filters['limit']);
            if (isset($filters['offset'])) {
                $sql .= ' OFFSET ' . max(0, (int) $filters['offset']);
            }
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($parts['params']);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Stránkovaná verze list() pro API (Support\Pagination kontrakt) — vrací
     * i celkový počet (COUNT přes stejné WHERE/JOIN, bez LIMIT).
     *
     * @param array{type?:string, active?:bool, q?:string, only_below_min?:bool} $filters
     * @return array{0:list<array<string,mixed>>, 1:int}
     */
    public function listPaged(int $supplierId, array $filters, int $perPage, int $offset): array
    {
        $parts = $this->buildListQueryParts($supplierId, $filters);

        $countStmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM stock_items si' . $parts['join'] . ' WHERE ' . $parts['where']
        );
        $countStmt->execute($parts['params']);
        $total = (int) $countStmt->fetchColumn();

        $cols = implode(', ', array_map(
            static fn (string $c): string => 'si.' . trim($c),
            explode(',', self::COLUMNS)
        ));
        $sql = 'SELECT ' . $cols . ' FROM stock_items si' . $parts['join']
             . ' WHERE ' . $parts['where']
             . ' ORDER BY si.name ASC'
             . ' LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, $offset);

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($parts['params']);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return [$rows, $total];
    }

    /**
     * Sestaví sdílené WHERE/JOIN/params pro list()/listPaged() (stejné filtry,
     * bez LIMIT/OFFSET) — aby COUNT(*) a datový dotaz vždy zůstaly konzistentní.
     *
     * @param array{type?:string, active?:bool, q?:string, only_below_min?:bool} $filters
     * @return array{where:string, join:string, params:array<int,mixed>}
     */
    private function buildListQueryParts(int $supplierId, array $filters): array
    {
        $where = ['si.supplier_id = ?'];
        $whereParams = [$supplierId];

        if (!empty($filters['type'])) {
            $where[] = 'si.item_type = ?';
            $whereParams[] = (string) $filters['type'];
        }
        if (array_key_exists('active', $filters)) {
            $where[] = 'si.is_active = ?';
            $whereParams[] = (int) $filters['active'];
        }
        if (array_key_exists('export_eshop', $filters)) {
            $where[] = 'si.export_eshop = ?';
            $whereParams[] = (int) $filters['export_eshop'];
        }
        if (!empty($filters['q'])) {
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(si.sku LIKE ? OR si.name LIKE ? OR si.ean LIKE ?)';
            $whereParams[] = '%' . $q . '%';
            $whereParams[] = '%' . $q . '%';
            $whereParams[] = '%' . $q . '%';
        }

        $onlyBelowMin = !empty($filters['only_below_min']);
        $join = '';
        $params = [];
        if ($onlyBelowMin) {
            // Součet stavu napříč sklady pro danou kartu — porovnání s min_qty.
            $join = ' LEFT JOIN (
                        SELECT stock_item_id, SUM(qty) AS tot FROM stock_levels
                         WHERE supplier_id = ? GROUP BY stock_item_id
                      ) sl ON sl.stock_item_id = si.id';
            $params[] = $supplierId;
        }
        $params = array_merge($params, $whereParams);

        $whereSql = implode(' AND ', $where);
        if ($onlyBelowMin) {
            $whereSql .= ' AND si.min_qty IS NOT NULL AND COALESCE(sl.tot, 0) < si.min_qty';
        }

        return ['where' => $whereSql, 'join' => $join, 'params' => $params];
    }

    /**
     * Autocomplete — aktivní karty dle sku/name/ean.
     * @return list<array<string,mixed>>
     */
    public function search(int $supplierId, string $q, int $limit = 50): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $lim = max(1, min(200, $limit));
        $like = '%' . addcslashes($q, '%_\\') . '%';

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, sku, name, unit, vat_rate_id, sale_price_without_vat
               FROM stock_items
              WHERE supplier_id = ? AND is_active = 1
                AND (sku LIKE ? OR name LIKE ? OR ean LIKE ?)
              ORDER BY name ASC
              LIMIT ' . $lim
        );
        $stmt->execute([$supplierId, $like, $like, $like]);
        return array_map(static function (array $r): array {
            return [
                'id'                     => (int) $r['id'],
                'sku'                    => (string) $r['sku'],
                'name'                   => (string) $r['name'],
                'unit'                   => (string) $r['unit'],
                'vat_rate_id'            => $r['vat_rate_id'] !== null ? (int) $r['vat_rate_id'] : null,
                'sale_price_without_vat' => $r['sale_price_without_vat'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array{sku:string, name:string, item_type?:string, unit?:string, ean?:?string,
     *              vat_rate_id?:?int, sale_price_without_vat?:?string, min_qty?:?string,
     *              is_active?:bool, note?:?string} $data
     */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_items
                (supplier_id, sku, name, item_type, unit, ean, vat_rate_id,
                 sale_price_without_vat, min_qty, is_active, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (string) $data['sku'],
            (string) $data['name'],
            (string) ($data['item_type'] ?? 'goods'),
            (string) ($data['unit'] ?? 'ks'),
            $data['ean'] ?? null,
            isset($data['vat_rate_id']) ? (int) $data['vat_rate_id'] : null,
            isset($data['sale_price_without_vat']) ? (string) $data['sale_price_without_vat'] : null,
            isset($data['min_qty']) ? (string) $data['min_qty'] : null,
            (int) ($data['is_active'] ?? true),
            $data['note'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{sku:string, name:string, item_type?:string, unit?:string, ean?:?string,
     *              vat_rate_id?:?int, sale_price_without_vat?:?string, min_qty?:?string,
     *              is_active?:bool, note?:?string} $data
     */
    public function update(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_items SET
                sku = ?, name = ?, item_type = ?, unit = ?, ean = ?, vat_rate_id = ?,
                sale_price_without_vat = ?, min_qty = ?, is_active = ?, note = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            (string) $data['sku'],
            (string) $data['name'],
            (string) ($data['item_type'] ?? 'goods'),
            (string) ($data['unit'] ?? 'ks'),
            $data['ean'] ?? null,
            isset($data['vat_rate_id']) ? (int) $data['vat_rate_id'] : null,
            isset($data['sale_price_without_vat']) ? (string) $data['sale_price_without_vat'] : null,
            isset($data['min_qty']) ? (string) $data['min_qty'] : null,
            (int) ($data['is_active'] ?? true),
            $data['note'] ?? null,
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Aktualizace eshopových sloupců karty (Epic ESHOP) — bez zásahu do
     * skladové identity (sku/name/vat/cena řeší update()).
     * @param array{manufacturer_id?:?int, warranty_months?:?int, delivery_days?:?int,
     *              export_eshop?:bool, is_stocked?:bool, weight_g?:?int, pricing_base?:string} $data
     */
    public function updateEshopFields(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_items SET
                manufacturer_id = ?, warranty_months = ?, delivery_days = ?,
                export_eshop = ?, is_stocked = ?, weight_g = ?, pricing_base = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            isset($data['manufacturer_id']) && $data['manufacturer_id'] !== null ? (int) $data['manufacturer_id'] : null,
            isset($data['warranty_months']) && $data['warranty_months'] !== null ? (int) $data['warranty_months'] : null,
            isset($data['delivery_days']) && $data['delivery_days'] !== null ? (int) $data['delivery_days'] : null,
            (int) ($data['export_eshop'] ?? false),
            (int) ($data['is_stocked'] ?? true),
            isset($data['weight_g']) && $data['weight_g'] !== null ? (int) $data['weight_g'] : null,
            (string) ($data['pricing_base'] ?? 'weighted_avg'),
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() >= 0;
    }

    /**
     * Zrcadlo prodejní ceny (Epic ESHOP cenotvorba) — zapíše CZK computed_price
     * do sale_price_without_vat (default do řádku FV). Money string.
     */
    public function setSalePrice(int $supplierId, int $id, ?string $price): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_items SET sale_price_without_vat = ? WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$price !== null ? (string) $price : null, $id, $supplierId]);
        return $stmt->rowCount() >= 0;
    }

    /** Patří manufacturer_id témuž tenantovi? (guard proti cross-tenant vazbě) */
    public function manufacturerOwned(int $supplierId, int $manufacturerId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM manufacturers WHERE supplier_id = ? AND id = ?)'
        );
        $stmt->execute([$supplierId, $manufacturerId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Existuje aspoň jeden pohyb (stock_document_lines) na této kartě? */
    public function hasMovements(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM stock_document_lines WHERE supplier_id = ? AND stock_item_id = ?
             )'
        );
        $stmt->execute([$supplierId, $id]);
        return (bool) $stmt->fetchColumn();
    }

    public function deactivate(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_items SET is_active = 0 WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Hard delete — volající MUSÍ nejdřív ověřit hasMovements() === false. */
    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_items WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['vat_rate_id'] = $r['vat_rate_id'] !== null ? (int) $r['vat_rate_id'] : null;
        $r['is_active'] = (bool) $r['is_active'];
        // Eshop rozšíření (1028) — sloupce mohou chybět u projekcí bez COLUMNS.
        if (array_key_exists('manufacturer_id', $r)) {
            $r['manufacturer_id'] = $r['manufacturer_id'] !== null ? (int) $r['manufacturer_id'] : null;
            $r['warranty_months'] = $r['warranty_months'] !== null ? (int) $r['warranty_months'] : null;
            $r['delivery_days'] = $r['delivery_days'] !== null ? (int) $r['delivery_days'] : null;
            $r['export_eshop'] = (bool) $r['export_eshop'];
            $r['is_stocked'] = (bool) $r['is_stocked'];
            $r['weight_g'] = $r['weight_g'] !== null ? (int) $r['weight_g'] : null;
        }
        return $r;
    }
}
