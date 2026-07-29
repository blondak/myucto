<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro stock_item_attribute_values — typované hodnoty parametrů
 * karty zboží (Epic ESHOP). Per tenant (supplier_id). Single vs multivalue
 * a validaci dle data_type vynucuje AttributeValueService (delete+insert v tx).
 * value_num zůstává string (money/číselná přesnost, indexovatelné rozsahy).
 */
final class StockAttributeValueRepository
{
    private const COLUMNS =
        'id, supplier_id, stock_item_id, attribute_id, option_id, value_text,
         value_num, value_bool, display_order';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForItem(int $supplierId, int $stockItemId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stock_item_attribute_values
              WHERE supplier_id = ? AND stock_item_id = ?
              ORDER BY attribute_id ASC, display_order ASC, id ASC'
        );
        $stmt->execute([$supplierId, $stockItemId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function deleteForItem(int $supplierId, int $stockItemId): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM stock_item_attribute_values WHERE supplier_id = ? AND stock_item_id = ?'
        )->execute([$supplierId, $stockItemId]);
    }

    public function deleteForItemAttribute(int $supplierId, int $stockItemId, int $attributeId): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM stock_item_attribute_values
              WHERE supplier_id = ? AND stock_item_id = ? AND attribute_id = ?'
        )->execute([$supplierId, $stockItemId, $attributeId]);
    }

    /**
     * @param array{attribute_id:int, option_id?:?int, value_text?:?string,
     *              value_num?:?string, value_bool?:?bool, display_order?:int} $data
     */
    public function add(int $supplierId, int $stockItemId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_item_attribute_values
                (supplier_id, stock_item_id, attribute_id, option_id, value_text, value_num, value_bool, display_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $stockItemId,
            (int) $data['attribute_id'],
            isset($data['option_id']) && $data['option_id'] !== null ? (int) $data['option_id'] : null,
            $data['value_text'] ?? null,
            isset($data['value_num']) && $data['value_num'] !== null ? (string) $data['value_num'] : null,
            isset($data['value_bool']) && $data['value_bool'] !== null ? (int) (bool) $data['value_bool'] : null,
            (int) ($data['display_order'] ?? 0),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['stock_item_id'] = (int) $r['stock_item_id'];
        $r['attribute_id'] = (int) $r['attribute_id'];
        $r['option_id'] = $r['option_id'] !== null ? (int) $r['option_id'] : null;
        $r['value_bool'] = $r['value_bool'] !== null ? (bool) $r['value_bool'] : null;
        $r['display_order'] = (int) $r['display_order'];
        // value_num zůstává string (přesnost).
        return $r;
    }
}
