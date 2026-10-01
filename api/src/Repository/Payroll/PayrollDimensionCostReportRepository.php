<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Nákladové alokace účetního můstku mezd pro report „náklady na zaměstnance
 * po dimenzi".
 *
 * Čte CÍLOVÉ alokace účinné dávky každého běhu (poslední zaúčtovaná nebo
 * bezezměnová revize), ne deník: do deníku se po zaměstnanci neúčtuje
 * a alokace nesou pracovní vztah v klíči. Účinnou dávku vybírá stejné
 * pravidlo jako {@see PayrollPostingBatchRepository::latestEffectiveBefore()}.
 *
 * Nákladová alokace se pozná podle NÁKLADOVÉ STRANY (klíč `…:debit`)
 * a účtu třídy 5, ne podle znaménka: záporná složka (oprava minulého měsíce,
 * srážka z hrubé mzdy) nese zápornou částku na téže straně a náklad snižuje.
 * Filtr na kladné částky by ji zahodil a report by náklad nadhodnotil
 * oproti deníku.
 */
final class PayrollDimensionCostReportRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array{
     *   period_start:string,
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   cost_center:?string,
     *   dimensions:?string
     * }>
     */
    public function costAllocations(int $supplierId, int $year): array
    {
        $statement = $this->db->pdo()->prepare(
            'WITH effective AS (
                SELECT batch.id AS batch_id,
                       run.period_start,
                       ROW_NUMBER() OVER (
                           PARTITION BY batch.run_id
                           ORDER BY revision.revision_no DESC
                       ) AS position
                  FROM payroll_posting_batches batch
                  JOIN payroll_run_revisions revision
                    ON revision.supplier_id = batch.supplier_id
                   AND revision.id = batch.revision_id
                  JOIN payroll_runs run
                    ON run.supplier_id = batch.supplier_id
                   AND run.id = batch.run_id
                 WHERE batch.supplier_id = ?
                   AND batch.status IN ("posted", "no_change")
                   AND run.period_start BETWEEN ? AND ?
             )
             SELECT effective.period_start, allocation.allocation_key,
                    allocation.account_code, allocation.signed_minor,
                    allocation.cost_center, allocation.dimensions
               FROM effective
               JOIN payroll_posting_allocations allocation
                 ON allocation.supplier_id = ?
                AND allocation.batch_id = effective.batch_id
              WHERE effective.position = 1
                AND allocation.allocation_key LIKE "%:debit"
                AND allocation.account_code LIKE "5%"
              ORDER BY effective.period_start, allocation.allocation_key'
        );
        $statement->execute([
            $supplierId,
            sprintf('%04d-01-01', $year),
            sprintf('%04d-12-31', $year),
            $supplierId,
        ]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'period_start' => (string) $row['period_start'],
                'allocation_key' => (string) $row['allocation_key'],
                'account_code' => (string) $row['account_code'],
                'signed_minor' => (int) $row['signed_minor'],
                'cost_center' => $row['cost_center'] === null ? null : (string) $row['cost_center'],
                'dimensions' => $row['dimensions'] === null ? null : (string) $row['dimensions'],
            ];
        }

        return $result;
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,array{employee_id:int,employee_name:string,employment_code:?string}>
     */
    public function employments(int $supplierId, array $employmentIds): array
    {
        $employmentIds = array_values(array_unique($employmentIds));
        if ($employmentIds === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.employee_id, employment.code,
                    employee.full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ?
                AND employment.id IN (' . implode(',', array_fill(0, count($employmentIds), '?')) . ')'
        );
        $statement->execute([$supplierId, ...$employmentIds]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['id']] = [
                'employee_id' => (int) $row['employee_id'],
                'employee_name' => (string) $row['full_name'],
                'employment_code' => $row['code'] === null ? null : (string) $row['code'],
            ];
        }

        return $result;
    }

    /**
     * Názvy typů a hodnot firemních dimenzí — jen těch, které firma vidí.
     *
     * @param list<int> $valueIds
     * @return array<int,array{type_id:int,type_name:string,code:string,name:string}>
     */
    public function dimensionValues(int $supplierId, array $valueIds): array
    {
        $valueIds = array_values(array_unique($valueIds));
        if ($valueIds === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT value.id, value.type_id, type.name AS type_name,
                    value.code, value.name
               FROM dimension_values value
               JOIN dimension_types type ON type.id = value.type_id
               JOIN supplier firm ON firm.id = ?
              WHERE value.id IN (' . implode(',', array_fill(0, count($valueIds), '?')) . ')
                AND (value.supplier_id = firm.id
                     OR (value.supplier_group_id IS NOT NULL
                         AND value.supplier_group_id = firm.supplier_group_id))'
        );
        $statement->execute([$supplierId, ...$valueIds]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['id']] = [
                'type_id' => (int) $row['type_id'],
                'type_name' => (string) $row['type_name'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
            ];
        }

        return $result;
    }
}
