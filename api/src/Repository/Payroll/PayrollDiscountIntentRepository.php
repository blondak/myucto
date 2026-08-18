<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Evidence záměrů uplatňovat slevu na pojistném (§ 23e, e-podání OZUSPOJ).
 *
 * Repozitář vrací HOLÁ FAKTA. Jestli záměr slevu za měsíc zakládá, rozhoduje
 * `OzuspojDiscountEligibility` — pravidlo kontroly 291 se musí dát otestovat
 * bez databáze.
 */
final readonly class PayrollDiscountIntentRepository
{
    public function __construct(private Connection $db) {}

    /** @return array<string,mixed>|null */
    public function find(
        int $supplierId,
        string $environment,
        int $intentId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT intent.*, employee.full_name
               FROM payroll_discount_intents intent
               JOIN payroll_employees employee
                 ON employee.supplier_id = intent.supplier_id
                AND employee.id = intent.employee_id
              WHERE intent.supplier_id = ?
                AND intent.environment = ?
                AND intent.id = ?'
        );
        $statement->execute([$supplierId, $environment, $intentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(
        int $supplierId,
        string $environment,
        ?int $employmentId = null,
    ): array {
        $sql =
            'SELECT intent.*, employee.full_name,
                    employment.code AS employment_code,
                    employment.start_date AS employment_start_date,
                    employment.end_date AS employment_end_date
               FROM payroll_discount_intents intent
               JOIN payroll_employees employee
                 ON employee.supplier_id = intent.supplier_id
                AND employee.id = intent.employee_id
               JOIN payroll_employments employment
                 ON employment.supplier_id = intent.supplier_id
                AND employment.id = intent.employment_id
              WHERE intent.supplier_id = ?
                AND intent.environment = ?';
        $params = [$supplierId, $environment];
        if ($employmentId !== null) {
            $sql .= ' AND intent.employment_id = ?';
            $params[] = $employmentId;
        }
        $sql .= ' ORDER BY intent.intent_from DESC, intent.id DESC';
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Fakta pracovního vztahu, ze kterých se oznámení sestaví.
     *
     * `social_part_time_discount_reason` se čte z podmínek účinných ke dni,
     * od kterého má záměr platit — důvod podle § 7a odst. 1 se v čase mění
     * (zaměstnanci je 21 let, dítěti 10) a evidence podle § 23d odst. 1 písm. b)
     * musí držet ten, který nárok zakládal.
     *
     * @return array<string,mixed>|null
     */
    public function findEmploymentContext(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id AS employment_id,
                    employment.employee_id,
                    employment.relation_type,
                    employment.start_date,
                    employment.actual_start_date,
                    employment.end_date,
                    employment.effective_status,
                    employee.full_name,
                    terms.social_part_time_discount_reason,
                    terms.social_part_time_discount_evidence,
                    supplier.company_name AS employer_name,
                    supplier.ic AS employer_business_id,
                    supplier.cssz_vsdp AS employer_variable_symbol,
                    supplier.cssz_ossz_code AS employer_ossz_code
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               JOIN supplier
                 ON supplier.id = employment.supplier_id
          LEFT JOIN payroll_employment_terms terms
                 ON terms.supplier_id = employment.supplier_id
                AND terms.employment_id = employment.id
                AND terms.effective_from <= ?
                AND (terms.effective_to IS NULL OR terms.effective_to >= ?)
              WHERE employment.supplier_id = ?
                AND employment.id = ?
              ORDER BY terms.effective_from DESC, terms.id DESC
              LIMIT 1'
        );
        $statement->execute([$onDate, $onDate, $supplierId, $employmentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Záměry, které se překrývají s obdobím, ale patří JINÉMU vztahu téže
     * osoby. § 7a odst. 2 věta druhá dovoluje slevu jen z jednoho zaměstnání
     * u téhož zaměstnavatele, takže druhý souběžný záměr za tutéž osobu je
     * chyba, kterou musí aplikace zachytit dřív, než ji vrátí ČSSZ.
     *
     * @return list<array<string,mixed>>
     */
    public function overlappingForEmployee(
        int $supplierId,
        string $environment,
        int $employeeId,
        string $intentFrom,
        ?string $intentTo,
        ?int $excludeIntentId = null,
    ): array {
        $sql =
            'SELECT id, employment_id, intent_from, intent_to, status
               FROM payroll_discount_intents
              WHERE supplier_id = ?
                AND environment = ?
                AND employee_id = ?
                AND status IN ("draft", "submitted", "accepted", "ended")
                AND (intent_to IS NULL OR intent_to >= ?)
                AND (? IS NULL OR intent_from <= ?)';
        $params = [
            $supplierId,
            $environment,
            $employeeId,
            $intentFrom,
            $intentTo,
            $intentTo,
        ];
        if ($excludeIntentId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeIntentId;
        }
        $sql .= ' ORDER BY intent_from, id';
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function insert(
        int $supplierId,
        string $environment,
        int $employeeId,
        int $employmentId,
        string $discountReason,
        string $intentFrom,
        int $osszCode,
        ?string $employeeInformedOn,
        int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_discount_intents
                 (supplier_id, environment, employee_id, employment_id,
                  discount_reason, intent_from, ossz_code,
                  employee_informed_on, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $employeeId,
            $employmentId,
            $discountReason,
            $intentFrom,
            $osszCode,
            $employeeInformedOn,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Optimistický zápis stavu. Bez `row_version` v podmínce by dva souběžné
     * požadavky mohly z odmítnutého záměru udělat přijatý.
     *
     * @param array<string,mixed> $changes
     */
    public function update(
        int $supplierId,
        string $environment,
        int $intentId,
        int $rowVersion,
        array $changes,
    ): bool {
        $assignments = ['row_version = row_version + 1'];
        $params = [];
        foreach ($changes as $column => $value) {
            $assignments[] = $column . ' = ?';
            $params[] = $value;
        }
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_discount_intents
                SET ' . implode(', ', $assignments) . '
              WHERE supplier_id = ?
                AND environment = ?
                AND id = ?
                AND row_version = ?'
        );
        $statement->execute([
            ...$params,
            $supplierId,
            $environment,
            $intentId,
            $rowVersion,
        ]);

        return $statement->rowCount() === 1;
    }
}
