<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Zdroje pro oznamovací povinnost vůči zdravotní pojišťovně.
 *
 * Repozitář vrací HOLÁ FAKTA, ne rozhodnutí. Co se z nich stane povinností,
 * určuje `HealthNotificationDutyResolver` — jinak by se hraniční případy
 * zúžení od 2026 nedaly otestovat bez databáze.
 */
final readonly class PayrollHealthNotificationRepository
{
    public function __construct(private Connection $db) {}

    /**
     * @return array{
     *   business_id:?string,name:string,street:?string,house_number:?string,
     *   postal_code:?string,city:?string,phone:?string
     * }|null
     */
    public function findEmployerIdentification(int $supplierId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT ic, company_name, street, street_number_pop, zip, city, phone
               FROM supplier
              WHERE id = ?'
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'business_id' => $this->nullableString($row['ic']),
            'name' => (string) $row['company_name'],
            'street' => $this->nullableString($row['street']),
            'house_number' => $this->nullableString($row['street_number_pop']),
            'postal_code' => $this->normalizePostalCode(
                $this->nullableString($row['zip']),
            ),
            'city' => $this->nullableString($row['city']),
            'phone' => $this->nullableString($row['phone']),
        ];
    }

    /**
     * Fakta jednoho pracovního vztahu ke dni `$onDate`.
     *
     * Pojišťovna se čte z časové řady krytí, ne z „aktuálního" údaje: oznámení
     * se váže ke dni skutečnosti a k pojišťovně, která toho dne platila.
     *
     * @return array{
     *   employment_id:int,employee_id:int,relation_type:string,status:string,
     *   participates:bool,insurer_code:?string,start_date:?string,
     *   end_date:?string,full_name:string
     * }|null
     */
    public function findNotificationFacts(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id,
                    employment.employee_id,
                    employment.relation_type,
                    employment.status,
                    employment.start_date,
                    employment.end_date,
                    employee.full_name,
                    terms.health_insurance_participation,
                    coverage.insurer_code
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               LEFT JOIN payroll_employment_terms terms
                 ON terms.supplier_id = employment.supplier_id
                AND terms.employment_id = employment.id
                AND terms.effective_from <= ?
                AND (terms.effective_to IS NULL OR terms.effective_to >= ?)
               LEFT JOIN payroll_person_health_coverage_history coverage
                 ON coverage.supplier_id = employment.supplier_id
                AND coverage.employee_id = employment.employee_id
                AND coverage.effective_from <= ?
                AND (coverage.effective_to IS NULL OR coverage.effective_to >= ?)
              WHERE employment.supplier_id = ?
                AND employment.id = ?
              ORDER BY terms.effective_from DESC,
                       coverage.effective_from DESC
              LIMIT 1'
        );
        $statement->execute([
            $onDate,
            $onDate,
            $onDate,
            $onDate,
            $supplierId,
            $employmentId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'employment_id' => (int) $row['id'],
            'employee_id' => (int) $row['employee_id'],
            'relation_type' => (string) $row['relation_type'],
            'status' => (string) $row['status'],
            'participates' => $this->participates(
                $this->nullableString($row['health_insurance_participation']),
                (string) $row['relation_type'],
            ),
            'insurer_code' => $this->nullableString($row['insurer_code']),
            'start_date' => $this->nullableString($row['start_date']),
            'end_date' => $this->nullableString($row['end_date']),
            'full_name' => (string) $row['full_name'],
        ];
    }

    /**
     * `automatic` znamená „rozhodne výpočet", ne „účastní se". Bez výslovného
     * zahrnutí se proto účast NEPŘEDPOKLÁDÁ — oznámit nástup u vztahu, který
     * účast nezakládá, je stejná vada jako neoznámit ten, který ji zakládá.
     */
    private function participates(
        ?string $participation,
        string $relationType,
    ): bool {
        if ($participation === 'included') {
            return true;
        }
        if ($participation === 'excluded' || $participation === 'foreign') {
            return false;
        }

        return $relationType === 'employment';
    }

    private function normalizePostalCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/\s+/', '', $value);

        return is_string($digits) && $digits !== '' ? $digits : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
