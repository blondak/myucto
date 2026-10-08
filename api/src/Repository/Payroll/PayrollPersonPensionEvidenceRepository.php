<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Pension\PayrollPensionStatus;
use PDO;

/**
 * Čtení důchodových údajů osoby (zákonná evidence, sekce Důchod).
 *
 * Zapisuje se výhradně editorem zákonné evidence
 * ({@see PayrollPersonStatutoryEvidenceRepository::save()}); tady jsou jen
 * čtecí cesty pro roční evidenční list a měsíční ELDP řez JMHZ. Obě dostanou
 * řady ověřené a sjednocené týmž {@see PayrollPensionStatus::normalize()}.
 *
 * Do snímku mzdového běhu evidence záměrně nepatří: výpočet mzdy ji nečte
 * a ELDP řez i evidenční list jsou zmrazené vlastním otiskem v okamžiku
 * sestavení. Zmrazené měsíce se změnou evidence nemění.
 */
final class PayrollPersonPensionEvidenceRepository
{
    /**
     * Tabulky a sloupce obou řad. Editor zákonné evidence je čte odsud, aby
     * jméno tabulky ani seznam sloupců nežily na dvou místech.
     *
     * @var array<string,array{table:string,columns:string,order:string}>
     */
    public const COLLECTIONS = [
        'age' => [
            'table' => 'payroll_person_pension_age',
            'columns' => 'id, basis, effective_from, effective_to,
                        evidence_reference, row_version',
            'order' => 'effective_from, id',
        ],
        'pensions' => [
            'table' => 'payroll_person_pensions',
            'columns' => 'id, pension_type_code, early_retirement,
                        reduced_retirement_age, effective_from, effective_to,
                        evidence_reference, row_version',
            'order' => 'pension_type_code, effective_from, id',
        ],
    ];

    private const CHUNK_SIZE = 500;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{age:?array{effective_from:string,basis:string},pensions:list<array<string,mixed>>}
     */
    public function forEmployee(int $supplierId, int $employeeId): array
    {
        return $this->forEmployees($supplierId, [$employeeId])[$employeeId]
            ?? ['age' => null, 'pensions' => []];
    }

    /**
     * Evidence všech osob firmy, které nějaký důchodový údaj mají; ostatní
     * ve výsledku chybí (prázdná evidence = nic se neví).
     *
     * @return array<int,array{age:?array{effective_from:string,basis:string},pensions:list<array<string,mixed>>}>
     */
    public function forSupplier(int $supplierId): array
    {
        $raw = [];
        foreach (self::COLLECTIONS as $key => $collection) {
            $statement = $this->db->pdo()->prepare(sprintf(
                'SELECT employee_id, %s FROM %s WHERE supplier_id = ? ORDER BY employee_id, %s',
                $collection['columns'],
                $collection['table'],
                $collection['order'],
            ));
            $statement->execute([$supplierId]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $raw[(int) $row['employee_id']][$key][] = $row;
            }
        }

        return $this->normalized($raw);
    }

    /**
     * @param list<int> $employeeIds
     * @return array<int,array{age:?array{effective_from:string,basis:string},pensions:list<array<string,mixed>>}>
     */
    public function forEmployees(int $supplierId, array $employeeIds): array
    {
        $raw = [];
        foreach (array_chunk(array_values(array_unique($employeeIds)), self::CHUNK_SIZE) as $chunk) {
            foreach (self::COLLECTIONS as $key => $collection) {
                $statement = $this->db->pdo()->prepare(sprintf(
                    'SELECT employee_id, %s FROM %s
                      WHERE supplier_id = ? AND employee_id IN (%s)
                      ORDER BY employee_id, %s',
                    $collection['columns'],
                    $collection['table'],
                    implode(', ', array_fill(0, count($chunk), '?')),
                    $collection['order'],
                ));
                $statement->execute([$supplierId, ...$chunk]);
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $raw[(int) $row['employee_id']][$key][] = $row;
                }
            }
        }

        return $this->normalized($raw);
    }

    /**
     * Důchodové údaje osoby pracovního vztahu pro interval; `null`, když osoba
     * žádný důchodový údaj v evidenci nemá.
     *
     * @return array{pension_age_reached_on:?string,early_pension_from:?string,full_pension_paid_from:?string}|null
     */
    public function statusForEmployment(int $supplierId, int $employmentId, string $from, string $to): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $employmentId]);
        $employeeId = $statement->fetchColumn();
        if ($employeeId === false) {
            return null;
        }
        $evidence = $this->forEmployee($supplierId, (int) $employeeId);

        return PayrollPensionStatus::isEmpty($evidence)
            ? null
            : PayrollPensionStatus::forInterval($evidence, $from, $to);
    }

    /**
     * @param array<int,array<string,list<array<string,mixed>>>> $raw
     * @return array<int,array{age:?array{effective_from:string,basis:string},pensions:list<array<string,mixed>>}>
     */
    private function normalized(array $raw): array
    {
        $result = [];
        foreach ($raw as $employeeId => $collections) {
            $result[$employeeId] = PayrollPensionStatus::normalize(
                $collections['age'] ?? [],
                $collections['pensions'] ?? [],
            );
        }

        return $result;
    }
}
