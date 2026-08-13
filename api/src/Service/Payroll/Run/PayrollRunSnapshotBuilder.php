<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentLifecycleSql;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerSettingsRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorUnavailableException;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseSource;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use PDO;

final class PayrollRunSnapshotBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly ?EnforcementCaseSource $enforcement = null,
        private readonly ?PayrollPersonStatutoryEvidenceRepository $statutoryEvidence = null,
        private readonly ?PayrollStatutoryPeriodResolver $periods = null,
        private readonly ?PayrollStatutoryAccumulatorRepository $statutoryAccumulators = null,
        private readonly ?PayrollEmployerSettingsRepository $employerSettings = null,
        private readonly ?PayrollEmployerPolicyRepository $employerPolicies = null,
    ) {}

    public function build(
        int $supplierId,
        string $periodStart,
        string $paymentDate,
        ?int $officeId = null,
    ): PayrollRunInputSnapshot {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma mzdového běhu není platná.');
        }
        $period = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($period === false
            || $period->format('Y-m-d') !== $periodStart
            || $period->format('d') !== '01'
        ) {
            throw new \InvalidArgumentException(
                'Mzdové období musí být první den měsíce ve formátu YYYY-MM-DD.',
            );
        }
        if ($officeId !== null && $officeId <= 0) {
            throw new \InvalidArgumentException('Mzdová účtárna není platná.');
        }
        $payment = \DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
        if ($payment === false
            || $payment->format('Y-m-d') !== $paymentDate
            || $payment < $period
        ) {
            throw new \InvalidArgumentException(
                'Datum výplaty mzdového běhu není platné.',
            );
        }
        $periodEnd = $period->modify('last day of this month')->format('Y-m-d');
        $statutoryPeriod = ($this->periods ?? new PayrollStatutoryPeriodResolver())
            ->resolve($periodStart, $paymentDate);
        $manifest = $this->rulesets->canonicalManifest();
        $manifestJson = CanonicalJson::encode(['rulesets' => $manifest]);
        $employerPolicy = $this->employerPolicySnapshot(
            $supplierId,
            $periodStart,
        );
        $employer = $this->employerSnapshot($supplierId);

        $employments = $this->employmentRows(
            $supplierId,
            $periodStart,
            $periodEnd,
            $officeId,
        );
        $validations = [];
        if ($employments === []) {
            $validations[] = new PayrollRunValidation(
                'blocker',
                'run_without_employments',
                'run',
                null,
                'Pro období nebyl nalezen žádný zpracovatelný pracovní vztah.',
                '/payroll/employees',
            );
        }

        /** @var array<int,array{employee:array<string,mixed>,employments:list<array<string,mixed>>}> $people */
        $people = [];
        foreach ($employments as $row) {
            $employmentId = (int) $row['employment_id'];
            $employeeId = (int) $row['employee_id'];
            if ($row['term_id'] === null) {
                $validations[] = new PayrollRunValidation(
                    'blocker',
                    'missing_effective_employment_term',
                    'employment',
                    $employmentId,
                    'Pracovní vztah nemá pro mzdové období účinné smluvní podmínky.',
                    "/payroll/employees/{$employeeId}",
                );
            }
            $timeMonth = $this->timeMonth($supplierId, $employmentId, $periodStart);
            if ($timeMonth !== null && $timeMonth['status'] !== 'approved') {
                $validations[] = new PayrollRunValidation(
                    'blocker',
                    'time_month_not_approved',
                    'employment',
                    $employmentId,
                    'Docházka pracovního vztahu není schválena.',
                    '/payroll/time',
                );
            }
            $draftCount = $this->draftInputCount(
                $supplierId,
                $employmentId,
                $periodStart,
            );
            if ($draftCount > 0) {
                $validations[] = new PayrollRunValidation(
                    'blocker',
                    'draft_inputs_present',
                    'employment',
                    $employmentId,
                    'Pracovní vztah obsahuje neschválené mzdové vstupy.',
                    '/payroll/components',
                );
            }
            $inputs = $this->inputs($supplierId, $employmentId, $periodStart);
            if ($inputs === []) {
                $validations[] = new PayrollRunValidation(
                    'warning',
                    'employment_without_inputs',
                    'employment',
                    $employmentId,
                    'Pracovní vztah nemá v období žádnou schválenou mzdovou složku.',
                    '/payroll/components',
                    true,
                );
            }
            $absences = $this->absences(
                $supplierId,
                $employmentId,
                $periodStart,
                $periodEnd,
            );

            $people[$employeeId] ??= [
                'employee' => [
                    'id' => $employeeId,
                    'full_name' => (string) $row['full_name'],
                    'profile_status' => (string) $row['profile_status'],
                    'is_active' => (bool) $row['employee_active'],
                ],
                'enforcement_evidence' => $this->enforcement === null
                    ? null
                    : $this->enforcement->evidenceFor(
                        $supplierId,
                        $employeeId,
                        $period->format('Y-m'),
                        $paymentDate,
                    )->toCanonicalArray(),
                'statutory_evidence' => $this->statutoryEvidence?->snapshot(
                    $supplierId,
                    $employeeId,
                    $statutoryPeriod->taxCalculationDate,
                ),
                'statutory_accumulators' => $this->statutoryAccumulatorSnapshot(
                    $supplierId,
                    $employeeId,
                    (int) $period->format('Y'),
                    $periodStart,
                ),
                'deduction_agreements' => $this->deductionAgreements(
                    $supplierId,
                    $employeeId,
                    $periodEnd,
                ),
                'payout_rules' => $this->payoutRules($supplierId, $employeeId),
                'payout_accounts' => $this->payoutAccounts(
                    $supplierId,
                    $employeeId,
                    $paymentDate,
                ),
                'employments' => [],
            ];
            $people[$employeeId]['employments'][] = [
                'employment' => [
                    'id' => $employmentId,
                    'employee_id' => $employeeId,
                    'office_id' => $row['office_id'] === null
                        ? null
                        : (int) $row['office_id'],
                    'code' => (string) $row['employment_code'],
                    'relation_type' => (string) $row['relation_type'],
                    'status' => (string) $row['employment_status'],
                    'start_date' => $row['start_date'],
                    'actual_start_date' => $row['actual_start_date'],
                    'end_date' => $row['end_date'],
                    'monthly_gross_minor' => $row['monthly_gross_minor'] === null
                        ? null
                        : (int) $row['monthly_gross_minor'],
                ],
                'term' => $row['term_id'] === null ? null : [
                    'id' => (int) $row['term_id'],
                    'row_version' => (int) $row['term_row_version'],
                    'effective_from' => (string) $row['effective_from'],
                    'effective_to' => $row['effective_to'],
                    'weekly_hours' => $row['weekly_hours'] === null
                        ? null
                        : (string) $row['weekly_hours'],
                    'workload_basis_points' => (int) $row['workload_basis_points'],
                    'work_place' => $row['work_place'],
                    'jmhz_workplace_municipality_code' =>
                        $row['jmhz_workplace_municipality_code'],
                    'jmhz_workplace_country_code' =>
                        $row['jmhz_workplace_country_code'],
                    'jmhz_apz_contribution_status' =>
                        (string) $row['jmhz_apz_contribution_status'],
                    'jmhz_apz_instrument_code' => $row['jmhz_apz_instrument_code'],
                    'jmhz_functional_benefits_status' =>
                        (string) $row['jmhz_functional_benefits_status'],
                    'jmhz_temporary_assignment_status' =>
                        (string) $row['jmhz_temporary_assignment_status'],
                    'social_insurance_participation' =>
                        (string) $row['social_insurance_participation'],
                    'health_insurance_participation' =>
                        (string) $row['health_insurance_participation'],
                    'tax_regime' => (string) $row['tax_regime'],
                    'tax_declaration_signed' =>
                        (bool) $row['tax_declaration_signed'],
                    'risky_work' => (bool) $row['risky_work'],
                    'foreign_legislation_country_code' =>
                        $row['foreign_legislation_country_code'],
                    'a1_certificate_until' => $row['a1_certificate_until'],
                ],
                'time_month' => $timeMonth,
                'absences' => $absences,
                'inputs' => $inputs,
            ];
        }
        ksort($people, SORT_NUMERIC);
        foreach ($people as &$person) {
            usort(
                $person['employments'],
                static fn (array $left, array $right): int =>
                    (int) $left['employment']['id']
                    <=> (int) $right['employment']['id'],
            );
        }
        unset($person);

        $data = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $supplierId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'payment_date' => $paymentDate,
            'statutory_period' => $statutoryPeriod->toSnapshot(),
            'employer_policy' => $employerPolicy,
            'employer' => $employer,
            'office_id' => $officeId,
            'ruleset_manifest' => $manifest,
            'people' => array_values($people),
        ];
        $json = CanonicalJson::encode($data);

        return new PayrollRunInputSnapshot(
            $data,
            $json,
            hash('sha256', $json),
            hash('sha256', $manifestJson),
            $validations,
        );
    }

    /**
     * @return array{
     *   id:int,
     *   row_version:int,
     *   automatic_posting_enabled:bool
     * }
     */
    private function employerPolicySnapshot(
        int $supplierId,
        string $periodStart,
    ): array {
        $policy = ($this->employerPolicies
            ?? new PayrollEmployerPolicyRepository($this->db))
            ->findEffective($supplierId, $periodStart);
        if ($policy === null) {
            throw new \DomainException(
                'Pro mzdové období chybí účinná zaměstnavatelská politika.',
            );
        }
        $id = $policy['id'] ?? null;
        $rowVersion = $policy['row_version'] ?? null;
        $automaticPosting = $policy['automatic_posting_enabled'] ?? null;
        if (!is_int($id)
            || $id <= 0
            || !is_int($rowVersion)
            || $rowVersion <= 0
            || !is_bool($automaticPosting)
        ) {
            throw new \UnexpectedValueException(
                'Účinná zaměstnavatelská politika nemá platná data pro mzdový snapshot.',
            );
        }

        return [
            'id' => $id,
            'row_version' => $rowVersion,
            'automatic_posting_enabled' => $automaticPosting,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function employmentRows(
        int $supplierId,
        string $periodStart,
        string $periodEnd,
        ?int $officeId,
    ): array {
        $officeSql = $officeId === null ? '1 = 1' : 'employment.office_id = ?';
        $stmt = $this->db->pdo()->prepare(
            'WITH effective_employment AS (
                    SELECT employment.*,
                           ' . PayrollEmploymentLifecycleSql::effectiveStatusAtPlaceholder() . '
                               AS effective_status
                      FROM payroll_employments employment
                     WHERE employment.supplier_id = ?
                 )
             SELECT employment.id AS employment_id,
                    employment.employee_id,
                    employment.office_id,
                    employment.code AS employment_code,
                    employment.relation_type,
                    employment.effective_status AS employment_status,
                    employment.start_date,
                    employment.actual_start_date,
                    employment.end_date,
                    employment.monthly_gross_minor,
                    employee.full_name,
                    employee.is_active AS employee_active,
                    profile.profile_status,
                    term.id AS term_id,
                    term.row_version AS term_row_version,
                    term.effective_from,
                    term.effective_to,
                    term.weekly_hours,
                    term.workload_basis_points,
                    term.work_place,
                    term.jmhz_workplace_municipality_code,
                    term.jmhz_workplace_country_code,
                    term.jmhz_apz_contribution_status,
                    term.jmhz_apz_instrument_code,
                    term.jmhz_functional_benefits_status,
                    term.jmhz_temporary_assignment_status,
                    term.social_insurance_participation,
                    term.health_insurance_participation,
                    term.tax_regime,
                    term.tax_declaration_signed,
                    term.risky_work,
                    term.foreign_legislation_country_code,
                    term.a1_certificate_until
               FROM effective_employment employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               JOIN payroll_employee_profiles profile
                 ON profile.supplier_id = employment.supplier_id
                AND profile.employee_id = employment.employee_id
          LEFT JOIN payroll_employment_terms term
                 ON term.supplier_id = employment.supplier_id
                AND term.employment_id = employment.id
                AND term.effective_from <= LEAST(
                    ?,
                    COALESCE(employment.end_date, ?)
                )
                AND (
                    term.effective_to IS NULL
                    OR term.effective_to >= LEAST(
                        ?,
                        COALESCE(employment.end_date, ?)
                    )
                )
                AND term.id = (
                    SELECT selected.id
                      FROM payroll_employment_terms selected
                     WHERE selected.supplier_id = employment.supplier_id
                       AND selected.employment_id = employment.id
                       AND selected.effective_from <= LEAST(
                           ?,
                           COALESCE(employment.end_date, ?)
                       )
                       AND (
                           selected.effective_to IS NULL
                           OR selected.effective_to >= LEAST(
                               ?,
                               COALESCE(employment.end_date, ?)
                           )
                       )
                     ORDER BY selected.effective_from DESC, selected.id DESC
                     LIMIT 1
                )
              WHERE employment.effective_status IS NOT NULL
                AND employment.effective_status NOT IN ("archived", "no_show")
                AND COALESCE(
                    employment.actual_start_date,
                    employment.start_date,
                    "1900-01-01"
                ) <= ?
                AND (
                    employment.end_date IS NULL
                    OR employment.end_date >= ?
                    OR EXISTS (
                        SELECT 1
                          FROM payroll_inputs post_termination_input
                         WHERE post_termination_input.supplier_id =
                               employment.supplier_id
                           AND post_termination_input.employment_id =
                               employment.id
                           AND post_termination_input.period_start = ?
                           AND post_termination_input.status <> "cancelled"
                    )
                )
                AND ' . $officeSql . '
              ORDER BY employment.employee_id, employment.id'
        );
        $stmt->execute([
            $periodEnd,
            $supplierId,
            $periodEnd,
            $periodEnd,
            $periodEnd,
            $periodEnd,
            $periodEnd,
            $periodEnd,
            $periodEnd,
            $periodEnd,
            $periodEnd,
            $periodStart,
            $periodStart,
            ...($officeId === null ? [] : [$officeId]),
        ]);
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    private function statutoryAccumulatorSnapshot(
        int $supplierId,
        int $employeeId,
        int $year,
        string $periodStart,
    ): array {
        $result = [
            'schema_version' => 'payroll-person-statutory-accumulators.v1',
        ];
        foreach ([
            'social_insurance' => 'social_insurance',
            'income_tax' => 'income_tax',
        ] as $key => $calculationKind) {
            if ($this->statutoryAccumulators === null) {
                $result[$key] = [
                    'status' => 'unverified',
                    'issue_code' => 'annual_accumulator_missing',
                    'state' => null,
                ];
                continue;
            }
            try {
                $result[$key] = [
                    'status' => 'verified',
                    'issue_code' => null,
                    'state' => $this->statutoryAccumulators->stateBeforePeriod(
                        $supplierId,
                        $employeeId,
                        $year,
                        $periodStart,
                        $calculationKind,
                    ),
                ];
            } catch (PayrollStatutoryAccumulatorUnavailableException) {
                $result[$key] = [
                    'status' => 'unverified',
                    'issue_code' => 'annual_accumulator_missing',
                    'state' => null,
                ];
            }
        }

        return $result;
    }

    /**
     * @return array{
     *   name:string,
     *   identification_number:string,
     *   accounting_accounts:array<string,string>
     * }
     */
    private function employerSnapshot(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COALESCE(NULLIF(display_name, ""), company_name) AS name, ic
               FROM supplier
              WHERE id = ?'
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || !is_string($row['name'] ?? null)
            || trim($row['name']) === ''
            || !is_string($row['ic'] ?? null)
            || trim($row['ic']) === ''
        ) {
            throw new \DomainException(
                'Firma nemá úplnou identitu zaměstnavatele pro výplatní pásky.',
            );
        }
        $settings = ($this->employerSettings
            ?? new PayrollEmployerSettingsRepository($this->db))
            ->get($supplierId);
        $accounts = $settings['accounts'] ?? null;
        if (!is_array($accounts) || array_is_list($accounts)) {
            throw new \DomainException(
                'Firma nemá úplné účetní předkontace pro výplatní pásky.',
            );
        }
        $accountSnapshot = [];
        foreach ($accounts as $key => $account) {
            if (!is_string($key)
                || !is_string($account)
                || preg_match('/^[0-9]{3}[.A-Z0-9]{0,13}$/D', $account) !== 1
            ) {
                throw new \DomainException(
                    'Firma nemá platné účetní předkontace pro výplatní pásky.',
                );
            }
            $accountSnapshot[$key] = $account;
        }
        ksort($accountSnapshot, SORT_STRING);

        return [
            'name' => trim($row['name']),
            'identification_number' => trim($row['ic']),
            'accounting_accounts' => $accountSnapshot,
        ];
    }

    /** @return array<string,mixed>|null */
    private function timeMonth(
        int $supplierId,
        int $employmentId,
        string $periodStart,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, status, row_version, approved_at
               FROM payroll_time_months
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'row_version' => (int) $row['row_version'],
            'approved_at' => $row['approved_at'],
        ];
    }

    private function draftInputCount(
        int $supplierId,
        int $employmentId,
        string $periodStart,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND status = "draft"'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function inputs(
        int $supplierId,
        int $employmentId,
        string $periodStart,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, amount_minor, quantity_milliunits, source_kind,
                    source_period_start, component_snapshot_json,
                    HEX(component_snapshot_hash) AS component_snapshot_hash
               FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND status IN ("approved", "locked")
              ORDER BY id'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $component = json_decode(
                (string) $row['component_snapshot_json'],
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            if (!is_array($component)) {
                throw new \UnexpectedValueException('Snapshot mzdové složky není objekt.');
            }
            $result[] = [
                'id' => (int) $row['id'],
                'amount_minor' => (int) $row['amount_minor'],
                'quantity_milliunits' => $row['quantity_milliunits'] === null
                    ? null
                    : (int) $row['quantity_milliunits'],
                'source_kind' => (string) $row['source_kind'],
                'source_period_start' => $row['source_period_start'],
                'component_snapshot_hash' =>
                    strtolower((string) $row['component_snapshot_hash']),
                'component' => $component,
            ];
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function absences(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, absence_type, date_from, date_to,
                    partial_first_minutes, partial_last_minutes, timezone_name,
                    compensation_policy, average_snapshot_id, decided_at
               FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ?
                AND status = "approved"
                AND date_from <= ?
                AND date_to >= ?
              ORDER BY date_from, id'
        );
        $stmt->execute([$supplierId, $employmentId, $periodEnd, $periodStart]);
        return array_values(array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'absence_type' => (string) $row['absence_type'],
                'date_from' => (string) $row['date_from'],
                'date_to' => (string) $row['date_to'],
                'partial_first_minutes' => $row['partial_first_minutes'] === null
                    ? null
                    : (int) $row['partial_first_minutes'],
                'partial_last_minutes' => $row['partial_last_minutes'] === null
                    ? null
                    : (int) $row['partial_last_minutes'],
                'timezone_name' => (string) $row['timezone_name'],
                'compensation_policy' => (string) $row['compensation_policy'],
                'average_snapshot_id' => $row['average_snapshot_id'] === null
                    ? null
                    : (int) $row['average_snapshot_id'],
                'decided_at' => $row['decided_at'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return list<array<string,mixed>> */
    private function deductionAgreements(
        int $supplierId,
        int $employeeId,
        string $effectiveOn,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, agreement_reference, title, deduction_kind, priority_no,
                    requested_minor, total_limit_minor, withheld_total_minor,
                    valid_from, valid_to, row_version
               FROM payroll_deduction_agreements
              WHERE supplier_id = ? AND employee_id = ?
                AND status = "active"
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY priority_no, id'
        );
        $stmt->execute([$supplierId, $employeeId, $effectiveOn, $effectiveOn]);
        return array_values(array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'agreement_reference' => (string) $row['agreement_reference'],
                'title' => (string) $row['title'],
                'deduction_kind' => (string) $row['deduction_kind'],
                'priority_no' => (int) $row['priority_no'],
                'requested_minor' => (int) $row['requested_minor'],
                'total_limit_minor' => $row['total_limit_minor'] === null
                    ? null
                    : (int) $row['total_limit_minor'],
                'withheld_total_minor' => (int) $row['withheld_total_minor'],
                'valid_from' => (string) $row['valid_from'],
                'valid_to' => $row['valid_to'],
                'row_version' => (int) $row['row_version'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return list<array<string,mixed>> */
    private function payoutRules(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, allocation_reference, destination_kind,
                    destination_reference, allocation_kind, amount_minor,
                    basis_points, priority_no, row_version
               FROM payroll_payout_rules
              WHERE supplier_id = ? AND employee_id = ? AND is_active = 1
              ORDER BY priority_no, id
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employeeId]);
        return array_values(array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'allocation_reference' => (string) $row['allocation_reference'],
                'destination_kind' => (string) $row['destination_kind'],
                'destination_reference' => $row['destination_reference'],
                'allocation_kind' => (string) $row['allocation_kind'],
                'amount_minor' => $row['amount_minor'] === null
                    ? null
                    : (int) $row['amount_minor'],
                'basis_points' => $row['basis_points'] === null
                    ? null
                    : (int) $row['basis_points'],
                'priority_no' => (int) $row['priority_no'],
                'row_version' => (int) $row['row_version'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return list<array<string,mixed>> */
    private function payoutAccounts(
        int $supplierId,
        int $employeeId,
        string $paymentDate,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, label, LOWER(HEX(bank_account_hash)) AS bank_account_hash,
                    bank_account_masked, allocation_basis_points,
                    effective_from, effective_to, row_version,
                    verification_source, verified_on, verified_by
               FROM payroll_person_accounts
              WHERE supplier_id = ?
                AND employee_id = ?
                AND is_active = 1
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY id
              FOR UPDATE'
        );
        $stmt->execute([
            $supplierId,
            $employeeId,
            $paymentDate,
            $paymentDate,
        ]);

        return array_values(array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'bank_account_hash' => (string) $row['bank_account_hash'],
                'bank_account_masked' => (string) $row['bank_account_masked'],
                'allocation_basis_points' =>
                    (int) $row['allocation_basis_points'],
                'effective_from' => (string) $row['effective_from'],
                'effective_to' => $row['effective_to'],
                'row_version' => (int) $row['row_version'],
                'verification_source' => $row['verification_source'],
                'verified_on' => $row['verified_on'],
                'verified_by' => $row['verified_by'] === null
                    ? null
                    : (int) $row['verified_by'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }
}
