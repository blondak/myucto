<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Kontroly souběhu se starým účetním programem (`migration_parallel_checks`): jedna
 * kontrola měsíce = jeden řádek s výsledkem, zařazením rozdílů a stavem cyklu.
 */
final class ParallelRunCheckRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $result
     * @param list<array<string,mixed>> $inputs
     */
    public function create(int $supplierId, string $month, string $source, string $status, array $inputs, array $result, ?int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO migration_parallel_checks (supplier_id, period_month, source_system, status, inputs, result, classifications, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId, $month, $source, $status,
            json_encode($inputs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            '{}',
            $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Historie kontrol firmy bez výsledku (jen hlavičky), nejnovější první.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, int $limit = 200): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, period_month, source_system, status, cycle_status, inputs, classifications, note, created_by, created_at, closed_by, closed_at
               FROM migration_parallel_checks
              WHERE supplier_id = ?
              ORDER BY period_month DESC, id DESC
              LIMIT ' . max(1, min(1000, $limit))
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $r): array => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, period_month, source_system, status, cycle_status, inputs, result, classifications, note, created_by, created_at, closed_by, closed_at
               FROM migration_parallel_checks
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Poslední kontrola měsíce (hlavička se zařazením rozdílů), null = měsíc ještě kontrolován nebyl.
     *
     * @return array<string,mixed>|null
     */
    public function latestForMonth(int $supplierId, string $month): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, period_month, source_system, status, cycle_status, inputs, classifications, note, created_by, created_at, closed_by, closed_at
               FROM migration_parallel_checks
              WHERE supplier_id = ? AND period_month = ?
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /** @param array<string,array<string,mixed>> $classifications */
    public function saveClassifications(int $supplierId, int $id, array $classifications): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE migration_parallel_checks SET classifications = ? WHERE supplier_id = ? AND id = ?');
        $stmt->execute([json_encode((object) $classifications, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $supplierId, $id]);
    }

    public function setCycle(int $supplierId, int $id, string $cycle, ?int $userId, ?string $note): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE migration_parallel_checks
                SET cycle_status = ?, closed_by = ?, closed_at = IF(? = 'closed', CURRENT_TIMESTAMP, NULL), note = COALESCE(?, note)
              WHERE supplier_id = ? AND id = ?"
        );
        $stmt->execute([$cycle, $cycle === 'closed' ? $userId : null, $cycle, $note, $supplierId, $id]);
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM migration_parallel_checks WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        $decode = static function (mixed $json, mixed $default): mixed {
            if (!is_string($json) || $json === '') {
                return $default;
            }
            $v = json_decode($json, true);
            return $v ?? $default;
        };
        $out = [
            'id' => (int) $row['id'],
            'month' => (string) $row['period_month'],
            'source' => (string) $row['source_system'],
            'status' => (string) $row['status'],
            'cycle_status' => (string) $row['cycle_status'],
            'inputs' => $decode($row['inputs'] ?? null, []),
            'classifications' => $decode($row['classifications'] ?? null, []),
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
            'closed_by' => $row['closed_by'] !== null ? (int) $row['closed_by'] : null,
            'closed_at' => $row['closed_at'] !== null ? (string) $row['closed_at'] : null,
        ];
        if (array_key_exists('result', $row)) {
            $out['result'] = $decode($row['result'], null);
        }
        return $out;
    }
}
