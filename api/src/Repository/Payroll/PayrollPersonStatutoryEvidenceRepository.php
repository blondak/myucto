<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollPersonStatutoryEvidenceValidator;
use PDO;
use UnexpectedValueException;

final class PayrollPersonStatutoryEvidenceRepository
{
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

        return $this->validator->normalize($employeeId, $effectiveOn, [
            'health' => [
                'coverages' => $this->rows(
                    'SELECT id, jurisdiction, foreign_country_code,
                            jurisdiction_evidence_reference, insurer_status,
                            insurer_code, insurer_evidence_reference,
                            effective_from, effective_to, row_version
                       FROM payroll_person_health_coverage_history
                      WHERE supplier_id = ? AND employee_id = ?
                      ORDER BY effective_from, id',
                    $supplierId,
                    $employeeId,
                ),
                'minimum_reductions' => $this->rows(
                    'SELECT id, reason, evidence_reference,
                            effective_from, effective_to, row_version
                       FROM payroll_person_health_minimum_reductions
                      WHERE supplier_id = ? AND employee_id = ?
                      ORDER BY reason, effective_from, id',
                    $supplierId,
                    $employeeId,
                ),
                'month_evidence' => $this->rows(
                    'SELECT id, period_start, top_up_responsibility,
                            top_up_responsibility_evidence_reference,
                            selected_top_up_employer_reference,
                            selected_top_up_employer_evidence_reference,
                            row_version
                       FROM payroll_person_health_month_evidence
                      WHERE supplier_id = ? AND employee_id = ?
                      ORDER BY period_start, id',
                    $supplierId,
                    $employeeId,
                ),
                'other_employer_bases' => $this->rows(
                    'SELECT id, period_start, employer_reference,
                            assessment_base_minor_units, employment_from,
                            employment_to, evidence_reference, row_version
                       FROM payroll_person_health_other_employer_bases
                      WHERE supplier_id = ? AND employee_id = ?
                      ORDER BY period_start, employer_reference, id',
                    $supplierId,
                    $employeeId,
                ),
            ],
            'income_tax' => [
                'declarations' => $this->rows(
                    'SELECT id, status, effective_from, effective_to,
                            evidence_reference, row_version
                       FROM payroll_person_tax_declarations
                      WHERE supplier_id = ? AND employee_id = ?
                      ORDER BY effective_from, id',
                    $supplierId,
                    $employeeId,
                ),
                'residences' => $this->rows(
                    'SELECT id, residence, country_code, effective_from,
                            effective_to, evidence_reference, row_version
                       FROM payroll_person_tax_residences
                      WHERE supplier_id = ? AND employee_id = ?
                      ORDER BY effective_from, id',
                    $supplierId,
                    $employeeId,
                ),
                'credit_claims' => $this->rows(
                    'SELECT id, credit_kind, evidence_status, effective_from,
                            effective_to, evidence_reference, row_version
                       FROM payroll_person_tax_credit_claims
                      WHERE supplier_id = ? AND employee_id = ?
                      ORDER BY credit_kind, effective_from, id',
                    $supplierId,
                    $employeeId,
                ),
                'child_claims' => $this->rows(
                    'SELECT id, child_reference, child_order, ztp_p,
                            evidence_status, shared_household_confirmed,
                            other_claimant_excluded, effective_from, effective_to,
                            evidence_reference, row_version
                       FROM payroll_person_tax_child_claims
                      WHERE supplier_id = ? AND employee_id = ?
                      ORDER BY child_order, child_reference, effective_from, id',
                    $supplierId,
                    $employeeId,
                ),
            ],
            'social' => [
                'jurisdictions' => $this->rows(
                    'SELECT id, jurisdiction, foreign_country_code,
                            jurisdiction_evidence_reference, a1_status,
                            a1_certificate_reference, a1_valid_until,
                            effective_from, effective_to, row_version
                       FROM payroll_person_social_jurisdictions
                      WHERE supplier_id = ? AND employee_id = ?
                      ORDER BY effective_from, id',
                    $supplierId,
                    $employeeId,
                ),
                'discount_claims' => $this->rows(
                    'SELECT id, status, effective_from, effective_to,
                            evidence_reference, row_version
                       FROM payroll_person_social_discount_claims
                      WHERE supplier_id = ? AND employee_id = ?
                      ORDER BY effective_from, id',
                    $supplierId,
                    $employeeId,
                ),
            ],
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $employeeId]);
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
