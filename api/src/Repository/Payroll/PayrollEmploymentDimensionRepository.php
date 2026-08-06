<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollEmploymentDimensionRepository
{
    private const COLUMNS = <<<'SQL'
        ed.id, ed.supplier_id, ed.employment_id, ed.dimension_id,
        ed.valid_from, ed.valid_to, ed.created_by, ed.updated_by,
        ed.row_version, ed.created_at, ed.updated_at
        SQL;

    private const JOINED_COLUMNS = <<<'SQL'
        ed.id, ed.supplier_id, ed.employment_id, ed.dimension_id,
        ed.valid_from, ed.valid_to, ed.created_by, ed.updated_by,
        ed.row_version, ed.created_at, ed.updated_at,
        d.dimension_type, d.code AS dimension_code, d.name AS dimension_name
        SQL;

    public function __construct(private readonly Connection $db) {}

    public function employmentExists(int $supplierId, int $employmentId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $employmentId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_employment_dimensions ed
              WHERE ed.supplier_id = ? AND ed.id = ?',
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForEmployment(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::JOINED_COLUMNS . '
               FROM payroll_employment_dimensions ed
               JOIN payroll_dimensions d
                 ON d.supplier_id = ed.supplier_id AND d.id = ed.dimension_id
              WHERE ed.supplier_id = ? AND ed.employment_id = ?
              ORDER BY d.dimension_type, ed.valid_from DESC, ed.id DESC',
        );
        $stmt->execute([$supplierId, $employmentId]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = self::hydrate($row);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function create(
        int $supplierId,
        int $employmentId,
        int $dimensionId,
        string $validFrom,
        ?string $validTo,
        ?int $actorUserId,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->lockTenant($supplierId);
            if (!$this->employmentExists($supplierId, $employmentId)) {
                throw new \RuntimeException('Pracovní vztah pro přiřazení dimenze nebyl nalezen.');
            }
            $dimension = $this->lockDimension($supplierId, $dimensionId);
            $this->assertDimensionEffective($dimension, $validFrom, $validTo);
            $this->assertNoOverlap(
                $supplierId,
                $employmentId,
                (string) $dimension['dimension_type'],
                $validFrom,
                $validTo,
                null,
            );

            $stmt = $pdo->prepare(
                'INSERT INTO payroll_employment_dimensions
                    (supplier_id, employment_id, dimension_id, valid_from,
                     valid_to, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
            );
            $stmt->execute([
                $supplierId, $employmentId, $dimensionId, $validFrom, $validTo,
                $actorUserId, $actorUserId,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($id <= 0) {
                throw new \RuntimeException('Přiřazení dimenze se nepodařilo založit.');
            }
            $row = $this->find($supplierId, $id)
                ?? throw new \RuntimeException('Založené přiřazení dimenze se nepodařilo načíst.');

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function update(
        int $supplierId,
        int $id,
        int $employmentId,
        int $dimensionId,
        string $validFrom,
        ?string $validTo,
        int $expectedVersion,
        ?int $actorUserId,
    ): ?array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->lockTenant($supplierId);
            $lock = $pdo->prepare(
                'SELECT row_version, employment_id
                   FROM payroll_employment_dimensions
                  WHERE supplier_id = ? AND id = ?
                  FOR UPDATE',
            );
            $lock->execute([$supplierId, $id]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if ($current === false) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return null;
            }
            if ((int) $current['employment_id'] !== $employmentId) {
                throw new \InvalidArgumentException('Přiřazení dimenze nepatří k danému pracovnímu vztahu.');
            }
            $currentVersion = (int) $current['row_version'];
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollEmploymentDimensionConflictException($currentVersion);
            }

            $dimension = $this->lockDimension($supplierId, $dimensionId);
            $this->assertDimensionEffective($dimension, $validFrom, $validTo);
            $this->assertNoOverlap(
                $supplierId,
                $employmentId,
                (string) $dimension['dimension_type'],
                $validFrom,
                $validTo,
                $id,
            );

            $stmt = $pdo->prepare(
                'UPDATE payroll_employment_dimensions
                    SET dimension_id = ?,
                        valid_from = ?,
                        valid_to = ?,
                        updated_by = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?',
            );
            $stmt->execute([
                $dimensionId, $validFrom, $validTo, $actorUserId,
                $supplierId, $id, $expectedVersion,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollEmploymentDimensionConflictException($currentVersion);
            }
            $row = $this->find($supplierId, $id)
                ?? throw new \RuntimeException('Upravené přiřazení dimenze se nepodařilo načíst.');

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $row;
    }

    private function lockTenant(int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM supplier WHERE id = ? FOR UPDATE',
        );
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException('Firma pro přiřazení dimenze neexistuje.');
        }
    }

    /** @return array<string,mixed> */
    private function lockDimension(int $supplierId, int $dimensionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, dimension_type, is_active, valid_from, valid_to
               FROM payroll_dimensions
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE',
        );
        $stmt->execute([$supplierId, $dimensionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \InvalidArgumentException('Přiřazovaná mzdová dimenze neexistuje.');
        }

        return $row;
    }

    /** @param array<string,mixed> $dimension */
    private function assertDimensionEffective(
        array $dimension,
        string $validFrom,
        ?string $validTo,
    ): void {
        if ((int) $dimension['is_active'] !== 1) {
            throw new \InvalidArgumentException('Přiřazovaná mzdová dimenze není aktivní.');
        }
        if ((string) $dimension['valid_from'] > $validFrom) {
            throw new \InvalidArgumentException(
                'Dimenze není účinná po celou dobu přiřazení — začíná až po jeho začátku.',
            );
        }
        $dimensionValidTo = $dimension['valid_to'];
        if ($dimensionValidTo !== null) {
            if ($validTo === null || $validTo > (string) $dimensionValidTo) {
                throw new \InvalidArgumentException(
                    'Dimenze není účinná po celou dobu přiřazení — končí dřív, než přiřazení.',
                );
            }
        }
    }

    private function assertNoOverlap(
        int $supplierId,
        int $employmentId,
        string $dimensionType,
        string $validFrom,
        ?string $validTo,
        ?int $exceptId,
    ): void {
        $sql = 'SELECT ed.id
                  FROM payroll_employment_dimensions ed
                  JOIN payroll_dimensions d
                    ON d.supplier_id = ed.supplier_id AND d.id = ed.dimension_id
                 WHERE ed.supplier_id = ?
                   AND ed.employment_id = ?
                   AND d.dimension_type = ?
                   AND ed.valid_from <= COALESCE(?, "9999-12-31")
                   AND COALESCE(ed.valid_to, "9999-12-31") >= ?';
        $params = [$supplierId, $employmentId, $dimensionType, $validTo, $validFrom];
        if ($exceptId !== null) {
            $sql .= ' AND ed.id <> ?';
            $params[] = $exceptId;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) {
            throw new PayrollEmploymentDimensionOverlapException(
                'Pracovní vztah už má v tomto období přiřazenou jinou dimenzi stejného typu.',
            );
        }
    }

    /** @return array<string,mixed> */
    private static function hydrate(mixed $value): array
    {
        $row = self::databaseRow($value);
        $row['id'] = self::requiredInt($row, 'id');
        $row['supplier_id'] = self::requiredInt($row, 'supplier_id');
        $row['employment_id'] = self::requiredInt($row, 'employment_id');
        $row['dimension_id'] = self::requiredInt($row, 'dimension_id');
        $row['row_version'] = self::requiredInt($row, 'row_version');
        foreach (['created_by', 'updated_by'] as $field) {
            $row[$field] = self::nullableInt($row, $field);
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private static function databaseRow(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Databázový řádek přiřazení dimenze není pole.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Databázový řádek přiřazení dimenze nemá textové klíče.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function requiredInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        throw new \UnexpectedValueException("Pole {$field} přiřazení dimenze není celé číslo.");
    }

    /** @param array<string,mixed> $row */
    private static function nullableInt(array $row, string $field): ?int
    {
        if (($row[$field] ?? null) === null) {
            return null;
        }

        return self::requiredInt($row, $field);
    }
}
