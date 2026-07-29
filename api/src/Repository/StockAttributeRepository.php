<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_attributes (+ options) — definice typovaných parametrů
 * zboží (Epic ESHOP). Per tenant (supplier_id). i18n názvů je v
 * stock_attribute_i18n (spravuje AttributeAction). NE čistý EAV — typované
 * hodnoty jsou v stock_item_attribute_values (StockAttributeValueRepository).
 */
final class StockAttributeRepository
{
    private const COLUMNS =
        'id, supplier_id, code, name, data_type, unit, is_filterable, is_multivalue,
         display_order, archived';
    private const OPT_COLUMNS =
        'id, supplier_id, attribute_id, code, label, display_order';

    public function __construct(private readonly Connection $db) {}

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_attributes WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castAttr($row);
    }

    public function findByCode(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_attributes WHERE supplier_id = ? AND code = ?'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castAttr($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM stock_attributes WHERE supplier_id = ?';
        if ($activeOnly) {
            $sql .= ' AND archived = 0';
        }
        $sql .= ' ORDER BY display_order ASC, name ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map([self::class, 'castAttr'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array{code:string, name:string, data_type?:string, unit?:?string,
     *              is_filterable?:bool, is_multivalue?:bool, display_order?:int, archived?:bool} $data
     */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_attributes
                (supplier_id, code, name, data_type, unit, is_filterable, is_multivalue, display_order, archived)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (string) $data['code'],
            (string) $data['name'],
            (string) ($data['data_type'] ?? 'text'),
            $data['unit'] ?? null,
            (int) ($data['is_filterable'] ?? false),
            (int) ($data['is_multivalue'] ?? false),
            (int) ($data['display_order'] ?? 0),
            (int) ($data['archived'] ?? false),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{code:string, name:string, data_type?:string, unit?:?string,
     *              is_filterable?:bool, is_multivalue?:bool, display_order?:int, archived?:bool} $data
     */
    public function update(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_attributes SET
                code = ?, name = ?, data_type = ?, unit = ?, is_filterable = ?,
                is_multivalue = ?, display_order = ?, archived = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            (string) $data['code'],
            (string) $data['name'],
            (string) ($data['data_type'] ?? 'text'),
            $data['unit'] ?? null,
            (int) ($data['is_filterable'] ?? false),
            (int) ($data['is_multivalue'] ?? false),
            (int) ($data['display_order'] ?? 0),
            (int) ($data['archived'] ?? false),
            $id,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function isReferenced(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM stock_item_attribute_values WHERE supplier_id = ? AND attribute_id = ?)'
        );
        $stmt->execute([$supplierId, $id]);
        return (bool) $stmt->fetchColumn();
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_attributes WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    // ── Options (pro data_type=enum) ──────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function listOptions(int $supplierId, int $attributeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::OPT_COLUMNS . ' FROM stock_attribute_options
              WHERE supplier_id = ? AND attribute_id = ?
              ORDER BY display_order ASC, label ASC'
        );
        $stmt->execute([$supplierId, $attributeId]);
        return array_map([self::class, 'castOpt'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findOption(int $supplierId, int $optionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::OPT_COLUMNS . ' FROM stock_attribute_options WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $optionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castOpt($row);
    }

    /**
     * @param array{code:string, label:string, display_order?:int} $data
     */
    public function insertOption(int $supplierId, int $attributeId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_attribute_options (supplier_id, attribute_id, code, label, display_order)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $attributeId,
            (string) $data['code'],
            (string) $data['label'],
            (int) ($data['display_order'] ?? 0),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{code:string, label:string, display_order?:int} $data
     */
    public function updateOption(int $supplierId, int $optionId, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE stock_attribute_options SET code = ?, label = ?, display_order = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            (string) $data['code'],
            (string) $data['label'],
            (int) ($data['display_order'] ?? 0),
            $optionId,
            $supplierId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function deleteOption(int $supplierId, int $optionId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM stock_attribute_options WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$optionId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    private static function castAttr(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['is_filterable'] = (bool) $r['is_filterable'];
        $r['is_multivalue'] = (bool) $r['is_multivalue'];
        $r['display_order'] = (int) $r['display_order'];
        $r['archived'] = (bool) $r['archived'];
        return $r;
    }

    /** @return array<string,mixed> */
    private static function castOpt(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['attribute_id'] = (int) $r['attribute_id'];
        $r['display_order'] = (int) $r['display_order'];
        return $r;
    }
}
