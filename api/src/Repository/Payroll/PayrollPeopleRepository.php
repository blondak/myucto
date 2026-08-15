<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollPeopleRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmployeeDeletionRepository $deletion,
    ) {}

    /** @return list<array<string,mixed>> */
    public function listForTenant(int $supplierId): array
    {
        $stmt = $this->peopleQuery();
        $stmt->execute([$supplierId, $supplierId]);
        $people = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $person = $this->castPerson($this->normalizeRow($row));
            $people[] = $this->withDeletion($supplierId, $person);
        }

        return $people;
    }

    /** @return array<string,mixed>|null */
    public function findForTenant(int $supplierId, int $employeeId): ?array
    {
        $stmt = $this->peopleQuery(true);
        $stmt->execute([$supplierId, $supplierId, $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $person = $this->withDeletion(
            $supplierId,
            $this->castPerson($this->normalizeRow($row)),
        );
        $person['employments'] = $this->employments->listForEmployee($supplierId, $employeeId);

        return $person;
    }

    /**
     * `can_delete` musí být v seznamu i v detailu — jinak by frontend nabízel akci
     * naslepo a důvod blokace by se dozvěděl až po kliknutí. Cizí tenant sem
     * nedosáhne: rozhodnutí se počítá jen pro osoby vrácené tenantovým dotazem.
     *
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function withDeletion(int $supplierId, array $person): array
    {
        $employeeId = $person['id'];
        $decision = is_int($employeeId)
            ? $this->deletion->canDelete($supplierId, $employeeId)
            : null;
        $person['can_delete'] = $decision !== null && $decision->canDelete;
        $person['delete_blocker'] = $decision?->blockerPayload();
        $person['delete_cascade'] = $decision === null ? [] : $decision->cascade;

        return $person;
    }

    private function peopleQuery(bool $single = false): \PDOStatement
    {
        $sql = <<<'SQL'
            SELECT employee.id,
                   COALESCE(
                       (
                           SELECT identity_history.full_name
                             FROM payroll_person_identity_history identity_history
                            WHERE identity_history.supplier_id = employee.supplier_id
                              AND identity_history.employee_id = employee.id
                              AND identity_history.effective_from <= CURRENT_DATE
                              AND (
                                  identity_history.effective_to IS NULL
                                  OR identity_history.effective_to >= CURRENT_DATE
                              )
                            ORDER BY identity_history.effective_from DESC,
                                     identity_history.id DESC
                            LIMIT 1
                       ),
                       employee.full_name
                   ) AS full_name,
                   employee.is_active,
                   profile.profile_status,
                   employee.taxpayer_type AS legacy_taxpayer_type,
                   employee.employment_type AS legacy_employment_type,
                   COALESCE(relations.employment_count, 0) AS employment_count,
                   COALESCE(relations.relation_types, '') AS relation_types
              FROM payroll_employees employee
              LEFT JOIN payroll_employee_profiles profile
                ON profile.supplier_id = employee.supplier_id
               AND profile.employee_id = employee.id
              LEFT JOIN (
                    SELECT supplier_id,
                           employee_id,
                           COUNT(*) AS employment_count,
                           GROUP_CONCAT(DISTINCT relation_type ORDER BY relation_type SEPARATOR ',') AS relation_types
                      FROM payroll_employments
                     WHERE supplier_id = ?
                     GROUP BY supplier_id, employee_id
              ) relations
                ON relations.supplier_id = employee.supplier_id
               AND relations.employee_id = employee.id
             WHERE employee.supplier_id = ?
            SQL;
        if ($single) {
            $sql .= ' AND employee.id = ?';
        }
        $sql .= ' ORDER BY employee.is_active DESC, full_name ASC, employee.id ASC';

        return $this->db->pdo()->prepare($sql);
    }

    /**
     * @param array<string,string|int|bool|null> $row
     * @return array<string,mixed>
     */
    private function castPerson(array $row): array
    {
        $profileStatus = $row['profile_status'] === null
            ? 'missing'
            : $this->stringValue($row, 'profile_status');
        $employmentCount = $this->intValue($row, 'employment_count');
        $relationTypes = $row['relation_types'] === ''
            ? []
            : explode(',', $this->stringValue($row, 'relation_types'));

        return [
            'id' => $this->intValue($row, 'id'),
            'full_name' => $this->stringValue($row, 'full_name'),
            'is_active' => $this->boolValue($row, 'is_active'),
            'profile_status' => $profileStatus,
            'legacy_taxpayer_type' => $this->stringValue($row, 'legacy_taxpayer_type'),
            'legacy_employment_type' => $this->stringValue($row, 'legacy_employment_type'),
            'employment_count' => $employmentCount,
            'relation_types' => $relationTypes,
            'needs_setup' => $profileStatus !== 'ready' || $employmentCount === 0,
        ];
    }

    /** @return array<string,string|int|bool|null> */
    private function normalizeRow(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Databáze vrátila neplatný řádek zaměstnance.');
        }

        $row = [];
        foreach ($value as $key => $cell) {
            if (!is_string($key)
                || (!is_string($cell) && !is_int($cell) && !is_bool($cell) && $cell !== null)
            ) {
                throw new \UnexpectedValueException('Databáze vrátila neplatnou hodnotu zaměstnance.');
            }
            $row[$key] = $cell;
        }

        return $row;
    }

    /** @param array<string,string|int|bool|null> $row */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_bool($value)) {
            return (string) (int) $value;
        }

        throw new \UnexpectedValueException("Databázové pole {$key} není řetězec.");
    }

    /** @param array<string,string|int|bool|null> $row */
    private function intValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value)) {
            $validated = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($validated)) {
                return $validated;
            }
        }

        throw new \UnexpectedValueException("Databázové pole {$key} není celé číslo.");
    }

    /** @param array<string,string|int|bool|null> $row */
    private function boolValue(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }

        throw new \UnexpectedValueException("Databázové pole {$key} není boolean.");
    }
}
