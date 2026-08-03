<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseSource;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use PDO;

final class PayrollRunSnapshotBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly ?PayrollRulesetProvider $rulesets = null,
        private readonly ?EnforcementCaseSource $enforcement = null,
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
        $provider = $this->rulesets ?? CzechPayrollRulesets2026::provider();
        $manifest = $provider->canonicalManifest();
        $manifestJson = CanonicalJson::encode(['rulesets' => $manifest]);

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
                ],
                'term' => $row['term_id'] === null ? null : [
                    'id' => (int) $row['term_id'],
                    'effective_from' => (string) $row['effective_from'],
                    'effective_to' => $row['effective_to'],
                    'weekly_hours' => $row['weekly_hours'] === null
                        ? null
                        : (string) $row['weekly_hours'],
                    'workload_basis_points' => (int) $row['workload_basis_points'],
                    'social_insurance_participation' =>
                        (string) $row['social_insurance_participation'],
                    'health_insurance_participation' =>
                        (string) $row['health_insurance_participation'],
                    'tax_regime' => (string) $row['tax_regime'],
                    'tax_declaration_signed' =>
                        (bool) $row['tax_declaration_signed'],
                    'risky_work' => (bool) $row['risky_work'],
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
            'schema_version' => 'payroll-run-input.v1',
            'supplier_id' => $supplierId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'payment_date' => $paymentDate,
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

    /** @return list<array<string,mixed>> */
    private function employmentRows(
        int $supplierId,
        string $periodStart,
        string $periodEnd,
        ?int $officeId,
    ): array {
        $officeSql = $officeId === null ? '1 = 1' : 'employment.office_id = ?';
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id AS employment_id,
                    employment.employee_id,
                    employment.office_id,
                    employment.code AS employment_code,
                    employment.relation_type,
                    employment.status AS employment_status,
                    employment.start_date,
                    employment.actual_start_date,
                    employment.end_date,
                    employee.full_name,
                    employee.is_active AS employee_active,
                    profile.profile_status,
                    term.id AS term_id,
                    term.effective_from,
                    term.effective_to,
                    term.weekly_hours,
                    term.workload_basis_points,
                    term.social_insurance_participation,
                    term.health_insurance_participation,
                    term.tax_regime,
                    term.tax_declaration_signed,
                    term.risky_work
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               JOIN payroll_employee_profiles profile
                 ON profile.supplier_id = employment.supplier_id
                AND profile.employee_id = employment.employee_id
          LEFT JOIN payroll_employment_terms term
                 ON term.supplier_id = employment.supplier_id
                AND term.employment_id = employment.id
                AND term.effective_from <= ?
                AND (term.effective_to IS NULL OR term.effective_to >= ?)
                AND term.id = (
                    SELECT selected.id
                      FROM payroll_employment_terms selected
                     WHERE selected.supplier_id = employment.supplier_id
                       AND selected.employment_id = employment.id
                       AND selected.effective_from <= ?
                       AND (selected.effective_to IS NULL OR selected.effective_to >= ?)
                     ORDER BY selected.effective_from DESC, selected.id DESC
                     LIMIT 1
                )
              WHERE employment.supplier_id = ?
                AND employment.status NOT IN ("archived", "no_show")
                AND COALESCE(
                    employment.actual_start_date,
                    employment.start_date,
                    "1900-01-01"
                ) <= ?
                AND (employment.end_date IS NULL OR employment.end_date >= ?)
                AND ' . $officeSql . '
              ORDER BY employment.employee_id, employment.id'
        );
        $stmt->execute([
            $periodEnd,
            $periodStart,
            $periodEnd,
            $periodStart,
            $supplierId,
            $periodEnd,
            $periodStart,
            ...($officeId === null ? [] : [$officeId]),
        ]);
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
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
}
