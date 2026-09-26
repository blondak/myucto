<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;

/**
 * Syntetická firma s jedním zaměstnancem a mzdou, nad kterou se dá pustit
 * celý mzdový běh (uzamčení vstupů → výpočet → validace) s exekucí.
 *
 * Vše běží v transakci izolované firmy; {@see self::tearDownEnforcementRun()}
 * ji vrací, takže v databázi nic nezůstane. Test volá v `setUp()`
 * {@see self::bootEnforcementRun()}.
 */
trait EnforcementRunFixtureTrait
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRunCommandService $runs;
    private PayrollRunRepository $runRepository;
    private PayrollStatutoryAccumulatorRepository $accumulators;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $actorId;
    /** @var array<string,int> */
    private array $componentIds = [];

    private function bootEnforcementRun(int $grossMinor = 3_500_000): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $runs = $container->get(PayrollRunCommandService::class);
        $repository = $container->get(PayrollRunRepository::class);
        $accumulators = $container->get(PayrollStatutoryAccumulatorRepository::class);
        $policies = $container->get(PayrollEmployerPolicyRepository::class);
        if (!$db instanceof Connection
            || !$runs instanceof PayrollRunCommandService
            || !$repository instanceof PayrollRunRepository
            || !$accumulators instanceof PayrollStatutoryAccumulatorRepository
            || !$policies instanceof PayrollEmployerPolicyRepository
        ) {
            throw new \RuntimeException('Služby mzdového běhu nejsou dostupné.');
        }
        $this->db = $db;
        $this->runs = $runs;
        $this->runRepository = $repository;
        $this->accumulators = $accumulators;
        if (!$this->db->hasTable('payroll_runs')
            || !$this->db->hasTable('payroll_enforcement_cases')
        ) {
            self::markTestSkipped('Mzdové migrace neproběhly.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($sourceSupplierId <= 0) {
            self::markTestSkipped('Chybí zdrojová firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->actorId = $this->createRunActor();
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())'
        )->execute([$this->supplierId, $this->actorId]);
        $policies->create($this->supplierId, [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'automatic_posting_enabled' => false,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:enforcement-run-policy',
        ], $this->actorId);

        $this->seedRunEmployee($grossMinor);
        $this->seedRunStatutoryEvidence();
        $this->seedRunZeroOpenings();
        $this->seedRunInput(
            $this->seedRunComponent('MZDA_MESICNI', 'Měsíční mzda', 'base_wage', 'regular', [
                'tax_treatment' => 'included',
                'social_treatment' => 'included',
                'health_treatment' => 'included',
                'average_earning_treatment' => 'included',
            ]),
            $grossMinor,
        );
    }

    private function tearDownEnforcementRun(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /**
     * @return array{
     *     run_id:int,
     *     revision_id:int,
     *     row_version:int,
     *     result:array<string,mixed>,
     *     statutory:array<string,mixed>,
     *     enforcement:array<string,mixed>,
     *     person:array<string,mixed>,
     *     validations:list<array<string,mixed>>,
     *     validation_codes:list<string>
     * }
     */
    private function calculateEnforcementRun(
        string $periodStart = '2026-06-01',
        string $paymentDate = '2026-07-15',
    ): array {
        $key = bin2hex(random_bytes(4));
        $run = $this->runs->createRun(
            $this->supplierId,
            $periodStart,
            $paymentDate,
            null,
            $this->actorId,
        );
        $locked = $this->runs->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            "lock-enforcement-run-{$key}",
            $this->actorId,
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            "calculate-enforcement-run-{$key}",
            $this->actorId,
        );
        $snapshot = $calculated->revision['result_snapshot'];
        $validations = $this->runRepository->validations(
            $this->supplierId,
            (int) $calculated->revision['id'],
        );

        return [
            'run_id' => (int) $run['id'],
            'revision_id' => (int) $calculated->revision['id'],
            'row_version' => (int) $calculated->run['row_version'],
            'result' => $snapshot,
            'statutory' => $snapshot['statutory'],
            'enforcement' => $snapshot['people'][0]['enforcement']['result'],
            'person' => $snapshot['people'][0],
            'validations' => $validations,
            'validation_codes' => array_column($validations, 'code'),
        ];
    }

    private function seedRunCase(
        string $status = 'withhold_and_hold',
        string $caseKind = 'enforcement',
        string $effectiveFrom = '2026-01-01',
    ): int {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status,
                 effective_from, evidence_complete, recipient_verified)
             VALUES (?, ?, ?, ?, ?, ?, 1, 1)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            'case-' . bin2hex(random_bytes(6)),
            $caseKind,
            $status,
            $effectiveFrom,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function seedRunClaim(
        int $caseId,
        string $category = 'non_priority',
        int $outstandingMinor = 10_000_000,
        string $deliveredOn = '2026-01-15',
        ?int $maintenanceWeightMinor = null,
    ): int {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_claims
                (supplier_id, case_id, claim_key, enforcement_order_key,
                 legal_basis, category, outstanding_minor_units,
                 maintenance_weight_minor_units, priority_date,
                 first_payer_delivered_on, order_issued_on, legal_title_verified,
                 order_or_notice_delivered, priority_classification_verified,
                 due_monetary_claim_verified, is_active)
             VALUES (?, ?, ?, ?, "statutory", ?, ?, ?, ?, ?, "2022-01-02",
                     1, 1, 1, 1, 1)'
        )->execute([
            $this->supplierId,
            $caseId,
            'claim-' . bin2hex(random_bytes(6)),
            'order-' . bin2hex(random_bytes(6)),
            $category,
            $outstandingMinor,
            $maintenanceWeightMinor,
            $deliveredOn,
            $deliveredOn,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function seedRunMonthEvidence(
        string $periodStart = '2026-06-01',
        string $insolvencyMode = 'none',
        bool $insolvencyVerified = false,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_person_month_evidence
                (supplier_id, employee_id, period_start,
                 claim_register_evidence_complete, dependants_evidence_complete,
                 spouse_evidence_complete, pension_evidence, insolvency_mode,
                 insolvency_decision_verified, insolvency_recipient_verified)
             VALUES (?, ?, ?, 1, 1, 1, "none", ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $periodStart,
            $insolvencyMode,
            $insolvencyVerified ? 1 : 0,
            $insolvencyVerified ? 1 : 0,
        ]);
    }

    /** @param array<string,string> $treatments */
    private function seedRunComponent(
        string $code,
        string $name,
        string $kind,
        string $frequency,
        array $treatments,
    ): int {
        $row = [
            'code' => $code,
            'name' => $name,
            'component_kind' => $kind,
            'value_kind' => 'monetary',
            'frequency_kind' => $frequency,
            'tax_treatment' => $treatments['tax_treatment'] ?? 'included',
            'social_participation_treatment' => $treatments['social_treatment'] ?? 'included',
            'social_treatment' => $treatments['social_treatment'] ?? 'included',
            'health_participation_treatment' => $treatments['health_treatment'] ?? 'included',
            'health_treatment' => $treatments['health_treatment'] ?? 'included',
            'average_earning_treatment' => $treatments['average_earning_treatment'] ?? 'included',
            'enforcement_treatment' => $treatments['enforcement_treatment'] ?? 'included',
            'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
            'accounting_debit_code' => '521',
            'accounting_credit_code' => '331',
        ];
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "2026-01-01")'
        )->execute([$this->supplierId, ...array_values($row)]);
        $id = (int) $pdo->lastInsertId();
        $this->componentIds[$code] = $id;

        return $id;
    }

    /** @param array<string,mixed> $inputExtra */
    private function seedRunInput(
        int $componentId,
        int $amountMinor,
        string $periodStart = '2026-06-01',
        array $inputExtra = [],
    ): int {
        $pdo = $this->db->pdo();
        $definition = $pdo->prepare(
            'SELECT code, name, component_kind, value_kind, frequency_kind,
                    tax_treatment, social_participation_treatment,
                    social_treatment, health_participation_treatment,
                    health_treatment, average_earning_treatment,
                    enforcement_treatment, jmhz_treatment, statistics_treatment,
                    accounting_debit_code, accounting_credit_code
               FROM payroll_component_definitions
              WHERE supplier_id = ? AND id = ?'
        );
        $definition->execute([$this->supplierId, $componentId]);
        $snapshot = $definition->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($snapshot)) {
            throw new \RuntimeException('Syntetická mzdová složka neexistuje.');
        }
        $snapshot += [
            'annual_limit_minor' => null,
            'component_id' => $componentId,
            'component_row_version' => 1,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ];
        $json = CanonicalJson::encode($snapshot);
        $columns = [
            'supplier_id' => $this->supplierId,
            'employee_id' => $this->employeeId,
            'employment_id' => $this->employmentId,
            'component_id' => $componentId,
            'period_start' => $periodStart,
            'amount_minor' => $amountMinor,
            'source_kind' => 'manual',
            'status' => 'approved',
            'component_snapshot_json' => $json,
            'component_snapshot_hash' => hash('sha256', $json, true),
            'approved_by' => $this->actorId,
        ] + $inputExtra;
        $pdo->prepare(sprintf(
            'INSERT INTO payroll_inputs (%s, approved_at) VALUES (%s, NOW())',
            implode(', ', array_keys($columns)),
            implode(', ', array_fill(0, count($columns), '?')),
        ))->execute(array_values($columns));

        return (int) $pdo->lastInsertId();
    }

    private function seedRunEmployee(int $grossMinor): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický Zaměstnanec", "employee", 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_primary)
             VALUES (?, ?, "SYN-HPP", "employment", "active",
                     "2026-01-01", "2026-01-01", ?, 1)'
        )->execute([$this->supplierId, $this->employeeId, $grossMinor]);
        $this->employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on,
                 actual_start_on, weekly_hours, workload_basis_points,
                 social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 other_withholding_eligibility,
                 tax_declaration_signed, is_primary)
             VALUES (?, ?, "2026-01-01", "2026-01-01", "2026-01-01",
                     40, 10000, "automatic", "automatic", "advance",
                     "unverified", 0, 1)'
        )->execute([$this->supplierId, $this->employmentId]);
    }

    private function seedRunStatutoryEvidence(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from, evidence_reference)
             VALUES (?, ?, "not-signed", "2026-01-01", "document:tax-declaration")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_residences
                (supplier_id, employee_id, residence, country_code,
                 effective_from, evidence_reference)
             VALUES (?, ?, "czech-resident", "CZ", "2026-01-01",
                     "document:tax-residence")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status,
                 insurer_code, insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "111",
                     "document:health-insurer", "2026-01-01")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_social_jurisdictions
                (supplier_id, employee_id, jurisdiction, a1_status, effective_from)
             VALUES (?, ?, "czech_regime_verified", "not_applicable", "2026-01-01")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_social_discount_claims
                (supplier_id, employee_id, status, effective_from, evidence_reference)
             VALUES (?, ?, "not_claimed", "2026-01-01", NULL)'
        )->execute([$this->supplierId, $this->employeeId]);
    }

    private function seedRunZeroOpenings(): void
    {
        $this->accumulators->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'social_insurance',
            ['assessment_base_minor_units' => 0],
            'synthetic:social-opening',
            ['verified_zero' => true],
            'enforcement-run-social-opening-' . $this->employeeId,
            actorUserId: $this->actorId,
        );
        $this->accumulators->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'income_tax',
            [
                'completed_months' => 0,
                'advance_base_minor_units' => 0,
                'withholding_base_minor_units' => 0,
                'advance_tax_minor_units' => 0,
                'withholding_tax_minor_units' => 0,
                'applied_non_refundable_credits_minor_units' => 0,
                'applied_child_credit_minor_units' => 0,
                'tax_bonus_minor_units' => 0,
                'bonus_qualifying_income_minor_units' => 0,
            ],
            'synthetic:tax-opening',
            ['verified_zero' => true],
            'enforcement-run-tax-opening-' . $this->employeeId,
            actorUserId: $this->actorId,
        );
    }

    private function createRunActor(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Synthetic payroll actor", "readonly", "cs", 1)'
        );
        $stmt->execute([
            'enforcement-run-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
