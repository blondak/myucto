<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollPersonStatutoryEvidenceValidator;
use PDO;
use UnexpectedValueException;

final class PayrollPersonStatutoryEvidenceRepository
{
    /**
     * Jediný popis kolekcí zákonné evidence osoby.
     *
     * Dotaz za jednu osobu i dávkový dotaz nad celou množinou se z něj GENERUJÍ,
     * takže nemůžou dostat jiné sloupce ani jiné řazení. Kdyby to byly dva SQL
     * literály, rozejdou se — a snapshot mzdového běhu by tiše změnil obsah.
     *
     * @var array<string, array<string, array{table:string, columns:string, order:string}>>
     */
    private const COLLECTIONS = [
        'health' => [
            'coverages' => [
                'table' => 'payroll_person_health_coverage_history',
                'columns' => 'id, jurisdiction, foreign_country_code,
                            jurisdiction_evidence_reference, insurer_status,
                            insurer_code, insurer_evidence_reference,
                            effective_from, effective_to, row_version',
                'order' => 'effective_from, id',
            ],
            'minimum_reductions' => [
                'table' => 'payroll_person_health_minimum_reductions',
                'columns' => 'id, reason, evidence_reference,
                            effective_from, effective_to, row_version',
                'order' => 'reason, effective_from, id',
            ],
            'month_evidence' => [
                'table' => 'payroll_person_health_month_evidence',
                'columns' => 'id, period_start, top_up_responsibility,
                            top_up_responsibility_evidence_reference,
                            selected_top_up_employer_reference,
                            selected_top_up_employer_evidence_reference,
                            row_version',
                'order' => 'period_start, id',
            ],
            'other_employer_bases' => [
                'table' => 'payroll_person_health_other_employer_bases',
                'columns' => 'id, period_start, employer_reference,
                            assessment_base_minor_units, employment_from,
                            employment_to, evidence_reference, row_version',
                'order' => 'period_start, employer_reference, id',
            ],
        ],
        'income_tax' => [
            'declarations' => [
                'table' => 'payroll_person_tax_declarations',
                'columns' => 'id, status, effective_from, effective_to,
                            evidence_reference, row_version',
                'order' => 'effective_from, id',
            ],
            'residences' => [
                'table' => 'payroll_person_tax_residences',
                'columns' => 'id, residence, country_code, effective_from,
                            effective_to, evidence_reference, row_version',
                'order' => 'effective_from, id',
            ],
            'credit_claims' => [
                'table' => 'payroll_person_tax_credit_claims',
                'columns' => 'id, credit_kind, evidence_status, effective_from,
                            effective_to, evidence_reference, row_version',
                'order' => 'credit_kind, effective_from, id',
            ],
            'child_claims' => [
                'table' => 'payroll_person_tax_child_claims',
                'columns' => 'id, child_reference, child_order, ztp_p,
                            evidence_status, shared_household_confirmed,
                            other_claimant_excluded, effective_from, effective_to,
                            evidence_reference, row_version',
                'order' => 'child_order, child_reference, effective_from, id',
            ],
        ],
        'social' => [
            'jurisdictions' => [
                'table' => 'payroll_person_social_jurisdictions',
                'columns' => 'id, jurisdiction, foreign_country_code,
                            jurisdiction_evidence_reference, a1_status,
                            a1_certificate_reference, a1_valid_until,
                            effective_from, effective_to, row_version',
                'order' => 'effective_from, id',
            ],
            'discount_claims' => [
                'table' => 'payroll_person_social_discount_claims',
                'columns' => 'id, status, effective_from, effective_to,
                            evidence_reference, row_version',
                'order' => 'effective_from, id',
            ],
        ],
    ];

    /** Alias skupinového klíče; po seskupení se z řádku zase odstraní. */
    private const GROUP_KEY = 'snapshot_group_employee_id';

    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollPersonStatutoryEvidenceValidator $validator,
    ) {}

    /** @return array<string,mixed>|null */
    public function snapshot(
        int $supplierId,
        int $employeeId,
        string $effectiveOn,
    ): ?array {
        $exists = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?'
        );
        $exists->execute([$supplierId, $employeeId]);
        if ($exists->fetchColumn() === false) {
            return null;
        }

        $raw = [];
        foreach (self::COLLECTIONS as $section => $collections) {
            foreach ($collections as $key => $collection) {
                $raw[$section][$key] = $this->rows(
                    sprintf(
                        'SELECT %s FROM %s WHERE supplier_id = ? AND employee_id = ?
                          ORDER BY %s',
                        $collection['columns'],
                        $collection['table'],
                        $collection['order'],
                    ),
                    [$supplierId, $employeeId],
                );
            }
        }

        return $this->validator->normalize($employeeId, $effectiveOn, $raw);
    }

    /**
     * Dávkový protějšek snapshot(): jedenáct dotazů na CELOU množinu osob místo
     * jedenácti na každou z nich.
     *
     * Výsledek je pro každou osobu bajtově shodný se samostatným snapshot():
     * seskupovací sloupec jde do SELECTu pod vlastním aliasem a po seskupení se
     * z řádku odstraní, `ORDER BY` zůstává beze změny — podposloupnost globálně
     * seřazeného výsledku pro jednu osobu má totéž pořadí jako dotaz za ni samotnou.
     *
     * @param list<int> $employeeIds
     * @return array<int,array<string,mixed>> klíčováno ID osoby; osoby, které
     *     firmě nepatří, ve výsledku chybí (jako null z snapshot())
     */
    public function snapshotMany(
        int $supplierId,
        array $employeeIds,
        string $effectiveOn,
    ): array {
        $known = $this->existingEmployeeIds($supplierId, $employeeIds);
        if ($known === []) {
            return [];
        }

        $grouped = [];
        foreach (self::COLLECTIONS as $section => $collections) {
            foreach ($collections as $key => $collection) {
                $grouped[$section][$key] = $this->groupedRows($collection, $supplierId, $known);
            }
        }

        $result = [];
        foreach ($known as $employeeId) {
            $raw = [];
            foreach ($grouped as $section => $collections) {
                foreach ($collections as $key => $rows) {
                    $raw[$section][$key] = $rows[$employeeId] ?? [];
                }
            }
            $result[$employeeId] = $this->validator->normalize(
                $employeeId,
                $effectiveOn,
                $raw,
            );
        }

        return $result;
    }

    /**
     * @param list<int> $employeeIds
     * @return list<int> podmnožina patřící firmě, v pořadí vstupu
     */
    private function existingEmployeeIds(int $supplierId, array $employeeIds): array
    {
        $unique = array_values(array_unique($employeeIds));
        if ($unique === []) {
            return [];
        }
        $found = [];
        foreach (array_chunk($unique, self::CHUNK_SIZE) as $chunk) {
            $stmt = $this->db->pdo()->prepare(sprintf(
                'SELECT id FROM payroll_employees
                  WHERE supplier_id = ? AND id IN (%s)',
                implode(', ', array_fill(0, count($chunk), '?')),
            ));
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $found[(int) $row['id']] = true;
            }
        }

        return array_values(array_filter(
            $unique,
            static fn (int $id): bool => isset($found[$id]),
        ));
    }

    /**
     * @param array{table:string, columns:string, order:string} $collection
     * @param list<int> $employeeIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function groupedRows(
        array $collection,
        int $supplierId,
        array $employeeIds,
    ): array {
        $grouped = [];
        foreach (array_chunk($employeeIds, self::CHUNK_SIZE) as $chunk) {
            $rows = $this->rows(
                sprintf(
                    'SELECT %s, employee_id AS %s FROM %s
                      WHERE supplier_id = ? AND employee_id IN (%s)
                      ORDER BY %s',
                    $collection['columns'],
                    self::GROUP_KEY,
                    $collection['table'],
                    implode(', ', array_fill(0, count($chunk), '?')),
                    $collection['order'],
                ),
                [$supplierId, ...$chunk],
            );
            foreach ($rows as $row) {
                $key = (int) $row[self::GROUP_KEY];
                unset($row[self::GROUP_KEY]);
                $grouped[$key][] = $row;
            }
        }

        return $grouped;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            if (!is_array($fetched)) {
                throw new UnexpectedValueException(
                    'Databáze vrátila neplatný řádek zákonné evidence osoby.',
                );
            }
            $row = [];
            foreach ($fetched as $key => $value) {
                if (!is_string($key)
                    || (!is_string($value) && !is_int($value)
                        && !is_bool($value) && $value !== null)
                ) {
                    throw new UnexpectedValueException(
                        'Databáze vrátila neplatnou hodnotu zákonné evidence osoby.',
                    );
                }
                $row[$key] = $value;
            }
            $result[] = $row;
        }

        return $result;
    }
}
