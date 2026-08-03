<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollEmploymentAccountingClassifier;
use PDO;

final class PayrollPeopleRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentAccountingClassifier $accounting,
    ) {}

    /** @return list<array<string,mixed>> */
    public function listForTenant(int $supplierId): array
    {
        $stmt = $this->peopleQuery();
        $stmt->execute([$supplierId, $supplierId]);

        return array_map(
            fn (array $row): array => $this->castPerson($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
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

        $person = $this->castPerson($row);
        $employments = $this->db->pdo()->prepare(
            'SELECT id, code, relation_type, status, start_date, end_date,
                    is_legacy_projection, monthly_gross_minor, row_version
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY is_legacy_projection DESC, start_date ASC, id ASC'
        );
        $employments->execute([$supplierId, $employeeId]);
        $person['employments'] = array_map(
            fn (array $employment): array => $this->castEmployment($employment),
            $employments->fetchAll(PDO::FETCH_ASSOC),
        );

        return $person;
    }

    private function peopleQuery(bool $single = false): \PDOStatement
    {
        $sql = <<<'SQL'
            SELECT employee.id,
                   employee.full_name,
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
        $sql .= ' ORDER BY employee.is_active DESC, employee.full_name ASC, employee.id ASC';

        return $this->db->pdo()->prepare($sql);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function castPerson(array $row): array
    {
        $profileStatus = $row['profile_status'] === null
            ? 'missing'
            : (string) $row['profile_status'];
        $employmentCount = (int) $row['employment_count'];
        $relationTypes = $row['relation_types'] === ''
            ? []
            : explode(',', (string) $row['relation_types']);

        return [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'is_active' => (bool) $row['is_active'],
            'profile_status' => $profileStatus,
            'legacy_taxpayer_type' => (string) $row['legacy_taxpayer_type'],
            'legacy_employment_type' => (string) $row['legacy_employment_type'],
            'employment_count' => $employmentCount,
            'relation_types' => $relationTypes,
            'needs_setup' => $profileStatus !== 'ready' || $employmentCount === 0,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function castEmployment(array $row): array
    {
        $relationType = (string) $row['relation_type'];

        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'relation_type' => $relationType,
            'status' => (string) $row['status'],
            'start_date' => $row['start_date'] === null ? null : (string) $row['start_date'],
            'end_date' => $row['end_date'] === null ? null : (string) $row['end_date'],
            'is_legacy_projection' => (bool) $row['is_legacy_projection'],
            'monthly_gross_minor' => $row['monthly_gross_minor'] === null
                ? null
                : (int) $row['monthly_gross_minor'],
            'row_version' => (int) $row['row_version'],
            'accounting' => ($this->accounting)($relationType),
        ];
    }
}
