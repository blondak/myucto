<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollDimensionRepository
{
    private const COLUMNS = <<<'SQL'
        id, supplier_id, dimension_type, code, name, valid_from, valid_to,
        is_active, default_account_code, created_by, updated_by, row_version,
        created_at, updated_at
        SQL;

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_dimensions
              WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(
        int $supplierId,
        ?string $dimensionType = null,
        bool $includeInactive = true,
    ): array {
        $sql = 'SELECT ' . self::COLUMNS . '
                  FROM payroll_dimensions
                 WHERE supplier_id = ?';
        $params = [$supplierId];
        if ($dimensionType !== null) {
            $sql .= ' AND dimension_type = ?';
            $params[] = $dimensionType;
        }
        if (!$includeInactive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY dimension_type, code, valid_from DESC, id DESC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = self::hydrate($row);
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    public function findEffective(
        int $supplierId,
        string $dimensionType,
        string $code,
        string $effectiveOn,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_dimensions
              WHERE supplier_id = ?
                AND dimension_type = ?
                AND code = ?
                AND is_active = 1
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC, id DESC
              LIMIT 1',
        );
        $stmt->execute([$supplierId, $dimensionType, $code, $effectiveOn, $effectiveOn]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $data, ?int $actorUserId): array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->lockTenant($supplierId);
            $this->assertNoOverlap(
                $supplierId,
                self::requiredString($data, 'dimension_type'),
                self::requiredString($data, 'code'),
                self::requiredString($data, 'valid_from'),
                self::nullableString($data, 'valid_to'),
                null,
            );
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_dimensions
                    (supplier_id, dimension_type, code, name, valid_from,
                     valid_to, is_active, default_account_code, created_by,
                     updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $stmt->execute($this->writeValues($supplierId, $data, $actorUserId, true));
            $id = (int) $pdo->lastInsertId();
            if ($id <= 0) {
                throw new \RuntimeException('Mzdovou dimenzi se nepodařilo založit.');
            }
            $row = $this->find($supplierId, $id)
                ?? throw new \RuntimeException('Založenou mzdovou dimenzi se nepodařilo načíst.');

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

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function update(
        int $supplierId,
        int $id,
        array $data,
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
                'SELECT ' . self::COLUMNS . '
                   FROM payroll_dimensions
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
            $current = self::hydrate($current);
            if ($current['row_version'] !== $expectedVersion) {
                throw new PayrollDimensionConflictException($current['row_version']);
            }

            $newType = self::requiredString($data, 'dimension_type');
            $newCode = self::requiredString($data, 'code');
            $newValidFrom = self::requiredString($data, 'valid_from');
            $historyChanged = $newType !== $current['dimension_type']
                || $newCode !== $current['code']
                || $newValidFrom !== $current['valid_from'];
            if ($historyChanged && $this->isUsedInApprovedRevision($supplierId, $id)) {
                throw new PayrollDimensionHistoryLockedException();
            }

            $this->assertNoOverlap(
                $supplierId,
                $newType,
                $newCode,
                $newValidFrom,
                self::nullableString($data, 'valid_to'),
                $id,
            );

            $stmt = $pdo->prepare(
                'UPDATE payroll_dimensions
                    SET dimension_type = ?,
                        code = ?,
                        name = ?,
                        valid_from = ?,
                        valid_to = ?,
                        is_active = ?,
                        default_account_code = ?,
                        updated_by = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?',
            );
            $values = array_slice(
                $this->writeValues($supplierId, $data, $actorUserId, false),
                1,
            );
            $values[] = $supplierId;
            $values[] = $id;
            $values[] = $expectedVersion;
            $stmt->execute($values);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollDimensionConflictException($current['row_version']);
            }
            $row = $this->find($supplierId, $id)
                ?? throw new \RuntimeException('Upravenou mzdovou dimenzi se nepodařilo načíst.');

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

    public function delete(int $supplierId, int $id): bool
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->lockTenant($supplierId);
            $lock = $pdo->prepare(
                'SELECT id FROM payroll_dimensions
                  WHERE supplier_id = ? AND id = ? FOR UPDATE',
            );
            $lock->execute([$supplierId, $id]);
            if ($lock->fetchColumn() === false) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return false;
            }
            if ($this->isUsedInApprovedRevision($supplierId, $id)) {
                throw new PayrollDimensionInUseException();
            }
            try {
                $stmt = $pdo->prepare(
                    'DELETE FROM payroll_dimensions WHERE supplier_id = ? AND id = ?',
                );
                $stmt->execute([$supplierId, $id]);
            } catch (\PDOException $e) {
                // FK RESTRICT z payroll_employment_dimensions (dimenze má dosud
                // živé přiřazení) nebo trigger delete guard — obojí znamená totéž:
                // dimenze je použitá a nejde smazat, jen ukončit účinnost.
                throw new PayrollDimensionInUseException();
            }
            $deleted = $stmt->rowCount() > 0;

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $deleted;
    }

    public function isUsedInApprovedRevision(int $supplierId, int $dimensionId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_employment_dimensions ed
               JOIN payroll_run_employments rune
                 ON rune.supplier_id = ed.supplier_id
                AND rune.employment_id = ed.employment_id
               JOIN payroll_run_revisions rev
                 ON rev.supplier_id = rune.supplier_id
                AND rev.id = rune.revision_id
                AND rev.status = "approved"
               JOIN payroll_runs run
                 ON run.supplier_id = rev.supplier_id
                AND run.id = rev.run_id
              WHERE ed.supplier_id = ?
                AND ed.dimension_id = ?
                AND ed.valid_from <= run.period_start
                AND COALESCE(ed.valid_to, "9999-12-31") >= run.period_start
              LIMIT 1',
        );
        $stmt->execute([$supplierId, $dimensionId]);

        return $stmt->fetchColumn() !== false;
    }

    private function lockTenant(int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM supplier WHERE id = ? FOR UPDATE',
        );
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException('Firma pro mzdovou dimenzi neexistuje.');
        }
    }

    private function assertNoOverlap(
        int $supplierId,
        string $dimensionType,
        string $code,
        string $validFrom,
        ?string $validTo,
        ?int $exceptId,
    ): void {
        $sql = 'SELECT id
                  FROM payroll_dimensions
                 WHERE supplier_id = ?
                   AND dimension_type = ?
                   AND code = ?
                   AND valid_from <= COALESCE(?, "9999-12-31")
                   AND COALESCE(valid_to, "9999-12-31") >= ?';
        $params = [$supplierId, $dimensionType, $code, $validTo, $validFrom];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) {
            throw new PayrollDimensionOverlapException(
                'Platnost dimenze se s tímto kódem a typem překrývá s jiným záznamem.',
            );
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return list<mixed>
     */
    private function writeValues(
        int $supplierId,
        array $data,
        ?int $actorUserId,
        bool $includeCreatedBy,
    ): array {
        $values = [
            $supplierId,
            self::requiredString($data, 'dimension_type'),
            self::requiredString($data, 'code'),
            self::requiredString($data, 'name'),
            self::requiredString($data, 'valid_from'),
            self::nullableString($data, 'valid_to'),
            (int) self::requiredBool($data, 'is_active'),
            self::nullableString($data, 'default_account_code'),
        ];
        if ($includeCreatedBy) {
            $values[] = $actorUserId;
        }
        $values[] = $actorUserId;

        return $values;
    }

    /** @return array<string,mixed> */
    private static function hydrate(mixed $value): array
    {
        $row = self::databaseRow($value);
        $row['id'] = self::requiredInt($row, 'id');
        $row['supplier_id'] = self::requiredInt($row, 'supplier_id');
        $row['is_active'] = self::requiredBool($row, 'is_active');
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
            throw new \UnexpectedValueException('Databázový řádek mzdové dimenze není pole.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Databázový řádek mzdové dimenze nemá textové klíče.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Pole {$field} mzdové dimenze není text.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Pole {$field} mzdové dimenze není text.");
        }

        return $value;
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

        throw new \UnexpectedValueException("Pole {$field} mzdové dimenze není celé číslo.");
    }

    /** @param array<string,mixed> $row */
    private static function nullableInt(array $row, string $field): ?int
    {
        if (($row[$field] ?? null) === null) {
            return null;
        }

        return self::requiredInt($row, $field);
    }

    /** @param array<string,mixed> $row */
    private static function requiredBool(array $row, string $field): bool
    {
        $value = $row[$field] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return $value === 1 || $value === '1';
        }

        throw new \UnexpectedValueException("Pole {$field} mzdové dimenze není boolean.");
    }
}
