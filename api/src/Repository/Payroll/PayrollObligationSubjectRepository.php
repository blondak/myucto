<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Osoby k pracovním vztahům, které jsou předmětem mzdové povinnosti
 * (`subject_reference` tvaru `payroll_employment:{id}` nebo `employment:{id}`).
 */
final class PayrollObligationSubjectRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<int> $employmentIds
     * @return array<int,array{full_name:string,employee_id:int}> `employment_id` → osoba
     */
    public function employmentPeople(int $supplierId, array $employmentIds): array
    {
        $employmentIds = array_values(array_unique(array_filter(
            $employmentIds,
            static fn (int $id): bool => $id > 0,
        )));
        if ($employmentIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($employmentIds), '?'));
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id, employee.id AS employee_id, employee.full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ?
                AND employment.id IN (' . $placeholders . ')',
        );
        $statement->execute([$supplierId, ...$employmentIds]);

        $people = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) $row['full_name']);
            if ($name !== '') {
                $people[(int) $row['id']] = [
                    'full_name' => $name,
                    'employee_id' => (int) $row['employee_id'],
                ];
            }
        }

        return $people;
    }
}
