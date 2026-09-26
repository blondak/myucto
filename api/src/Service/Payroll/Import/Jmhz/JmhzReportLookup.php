<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Čtecí dotazy importu měsíčních hlášení. Jen SELECTy — zápisy jdou přes
 * služby a repozitáře domény.
 */
final class JmhzReportLookup
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Všechny verze sjednaných podmínek vztahu, nejnovější první.
     *
     * @return list<array<string,mixed>>
     */
    public function termVersions(int $supplierId, int $employmentId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, effective_from, effective_to, work_place,
                    jmhz_workplace_municipality_code, jmhz_workplace_country_code,
                    jmhz_apz_contribution_status, jmhz_apz_instrument_code,
                    jmhz_functional_benefits_status, jmhz_temporary_assignment_status,
                    weekly_hours, workload_basis_points
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from DESC, id DESC'
        );
        $statement->execute([$supplierId, $employmentId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Nejdřívější mzdový běh MyÚčta v roce (zrušené běhy se nepočítají).
     */
    public function firstRunPeriod(int $supplierId, int $year): ?string
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT MIN(period_start)
               FROM payroll_runs
              WHERE supplier_id = ? AND status <> 'cancelled'
                AND period_start BETWEEN ? AND ?"
        );
        $statement->execute([$supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /** Od kterého období vede mzdy MyÚčto (aktivace plného mzdového modulu). */
    public function moduleStartPeriod(int $supplierId): ?string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT start_period FROM payroll_module_state WHERE supplier_id = ?'
        );
        $statement->execute([$supplierId]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /** @return list<int> */
    public function dependantIdsByBirthNumberHash(int $supplierId, int $employeeId, string $hash): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_dependants
              WHERE supplier_id = ? AND employee_id = ? AND birth_number_hash = ?
              ORDER BY id'
        );
        $statement->execute([$supplierId, $employeeId, $hash]);

        return array_map(intval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Převzaté měsíce vztahu, které už firma má, podle zdroje:
     * `period (YYYY-MM) => list<source>`.
     *
     * @return array<string,list<string>>
     */
    public function takeoverSources(int $supplierId, int $employmentId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT DATE_FORMAT(period_start, \'%Y-%m\') AS period, source
               FROM payroll_migration_reference_totals
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY period_start, source'
        );
        $statement->execute([$supplierId, $employmentId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['period']][] = (string) $row['source'];
        }

        return $result;
    }

    /**
     * Vztahy, ke kterým jde formulář ručně přiřadit.
     *
     * @return list<array{employment_id:int,employee_id:int,label:string,code:string}>
     */
    public function employmentOptions(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT employment.id, employment.employee_id, employment.code,
                    employment.relation_type, employment.start_date, employment.end_date,
                    employee.full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ?
                AND employment.is_legacy_projection = 0
                AND employment.status NOT IN ('archived', 'no_show')
              ORDER BY employee.full_name, employment.start_date, employment.id"
        );
        $statement->execute([$supplierId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'employment_id' => (int) $row['id'],
                'employee_id' => (int) $row['employee_id'],
                'label' => sprintf(
                    '%s · %s · od %s%s',
                    (string) $row['full_name'],
                    (string) $row['relation_type'],
                    (string) ($row['start_date'] ?? '—'),
                    $row['end_date'] === null ? '' : ' do ' . $row['end_date'],
                ),
                'code' => (string) $row['code'],
            ];
        }

        return $result;
    }
}
