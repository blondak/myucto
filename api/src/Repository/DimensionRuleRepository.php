<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Pravidla dimenzí podle účtu (`dimension_account_rules`). Validaci dělá
 * {@see \MyInvoice\Service\Accounting\Dimension\DimensionRuleService}; tady se jen čte
 * a zapisuje v rámci firmy.
 */
final class DimensionRuleRepository
{
    public const ENFORCEMENTS = ['error', 'warning', 'none'];

    private const COLUMNS = 'r.id, r.supplier_id, r.dimension_type_id, r.account_mask, r.enforcement,
        r.default_value_id, r.default_from_card, r.valid_from, r.valid_to, r.is_active, r.note,
        r.created_by, r.created_at, r.updated_at';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ',
                       t.name AS type_name, t.code AS type_code, t.kind AS type_kind, t.is_active AS type_active,
                       v.code AS default_value_code, v.name AS default_value_name, v.is_active AS default_value_active
                  FROM dimension_account_rules r
                  JOIN dimension_types t ON t.id = r.dimension_type_id
             LEFT JOIN dimension_values v ON v.id = r.default_value_id
                 WHERE r.supplier_id = ?';
        if ($activeOnly) {
            $sql .= ' AND r.is_active = 1 AND t.is_active = 1';
        }
        $sql .= ' ORDER BY t.sort_order, t.name, r.account_mask, r.id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $supplierId, int $id): ?array
    {
        foreach ($this->listForSupplier($supplierId) as $rule) {
            if ($rule['id'] === $id) {
                return $rule;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $data */
    public function create(int $supplierId, array $data): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO dimension_account_rules
                (supplier_id, dimension_type_id, account_mask, enforcement, default_value_id, default_from_card,
                 valid_from, valid_to, is_active, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $data['dimension_type_id'],
            $data['account_mask'],
            $data['enforcement'],
            $data['default_value_id'],
            $data['default_from_card'] ? 1 : 0,
            $data['valid_from'],
            $data['valid_to'],
            $data['is_active'] ? 1 : 0,
            $data['note'],
            $data['created_by'] ?? null,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $supplierId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE dimension_account_rules
                SET dimension_type_id = ?, account_mask = ?, enforcement = ?, default_value_id = ?,
                    default_from_card = ?, valid_from = ?, valid_to = ?, is_active = ?, note = ?
              WHERE id = ? AND supplier_id = ?'
        )->execute([
            $data['dimension_type_id'],
            $data['account_mask'],
            $data['enforcement'],
            $data['default_value_id'],
            $data['default_from_card'] ? 1 : 0,
            $data['valid_from'],
            $data['valid_to'],
            $data['is_active'] ? 1 : 0,
            $data['note'],
            $id,
            $supplierId,
        ]);
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM dimension_account_rules WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'dimension_type_id' => (int) $row['dimension_type_id'],
            'type_name' => (string) $row['type_name'],
            'type_code' => (string) $row['type_code'],
            'type_kind' => (string) $row['type_kind'],
            'type_active' => (bool) $row['type_active'],
            'account_mask' => (string) $row['account_mask'],
            'enforcement' => (string) $row['enforcement'],
            'default_value_id' => $row['default_value_id'] !== null ? (int) $row['default_value_id'] : null,
            'default_value_code' => $row['default_value_code'] !== null ? (string) $row['default_value_code'] : null,
            'default_value_name' => $row['default_value_name'] !== null ? (string) $row['default_value_name'] : null,
            'default_value_active' => $row['default_value_active'] !== null ? (bool) $row['default_value_active'] : null,
            'default_from_card' => (bool) $row['default_from_card'],
            'valid_from' => $row['valid_from'] !== null ? (string) $row['valid_from'] : null,
            'valid_to' => $row['valid_to'] !== null ? (string) $row['valid_to'] : null,
            'is_active' => (bool) $row['is_active'],
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
