<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Převzatý vztah s důvodem slevy na pojistném, ke kterému chybí přijatý záměr.
 *
 * Nárok na slevu podle § 7a zákona č. 589/1992 Sb. zakládá až záměr doručený
 * ČSSZ (§ 7a odst. 5). Když ho podal předchozí mzdový program, po převodu
 * v evidenci chybí a výpočet slevu (správně) neuplatní — jen to nikde nebylo
 * vidět. Kontrola převodu proto vyjmenuje převzaté pracovní poměry, které mají
 * v roce vyplněný důvod slevy, ale žádný přijatý ani ukončený ostrý záměr.
 * Náprava je převzít přijaté oznámení OZUSPOJ předchozího programu, ne záměr
 * dovozovat z měsíčního hlášení.
 */
final class PayrollTakeoverDiscountIntentCheck
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array{employee_id:int,employee_name:string,employment_id:int,employment_code:string,discount_reason:string}>
     */
    public function missingIntents(int $supplierId, int $year): array
    {
        if ($supplierId <= 0 || $year < 2000 || $year > 2200) {
            throw new \InvalidArgumentException('Firma a rok kontroly převodu nejsou platné.');
        }
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-12-31', $year);
        $statement = $this->db->pdo()->prepare(
            'WITH taken_over AS (
                SELECT DISTINCT totals.employment_id
                  FROM payroll_migration_reference_totals totals
                 WHERE totals.supplier_id = ?
                   AND totals.employment_id IS NOT NULL
                   AND totals.period_start BETWEEN ? AND ?
            ),
            reasons AS (
                SELECT terms.employment_id,
                       terms.social_part_time_discount_reason AS discount_reason,
                       ROW_NUMBER() OVER (
                           PARTITION BY terms.employment_id
                           ORDER BY terms.effective_from DESC, terms.id DESC
                       ) AS position
                  FROM payroll_employment_terms terms
                  JOIN taken_over ON taken_over.employment_id = terms.employment_id
                 WHERE terms.supplier_id = ?
                   AND terms.social_part_time_discount_reason IS NOT NULL
                   AND terms.social_part_time_discount_reason <> "none"
                   AND terms.effective_from <= ?
                   AND (terms.effective_to IS NULL OR terms.effective_to >= ?)
            )
            SELECT employment.id AS employment_id,
                   employment.employee_id,
                   employment.code AS employment_code,
                   employee.full_name,
                   reasons.discount_reason
              FROM reasons
              JOIN payroll_employments employment
                ON employment.supplier_id = ?
               AND employment.id = reasons.employment_id
               AND employment.relation_type = "employment"
              JOIN payroll_employees employee
                ON employee.supplier_id = employment.supplier_id
               AND employee.id = employment.employee_id
             WHERE reasons.position = 1
               AND NOT EXISTS (
                   SELECT 1
                     FROM payroll_discount_intents intent
                    WHERE intent.supplier_id = employment.supplier_id
                      AND intent.environment = "production"
                      AND intent.employment_id = employment.id
                      AND intent.status IN ("accepted", "ended")
               )
             ORDER BY employee.full_name, employment.id'
        );
        $statement->execute([
            $supplierId,
            $yearStart,
            $yearEnd,
            $supplierId,
            $yearEnd,
            $yearStart,
            $supplierId,
        ]);

        return array_map(static fn (array $row): array => [
            'employee_id' => (int) $row['employee_id'],
            'employee_name' => (string) $row['full_name'],
            'employment_id' => (int) $row['employment_id'],
            'employment_code' => (string) $row['employment_code'],
            'discount_reason' => (string) $row['discount_reason'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
