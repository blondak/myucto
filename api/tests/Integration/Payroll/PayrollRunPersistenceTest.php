<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRunsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollDeductionAgreementRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollNetRepository;
use MyInvoice\Repository\Payroll\PayrollRunConflictException;
use MyInvoice\Repository\Payroll\PayrollRunIdempotencyException;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupEncodedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupOptionalSecretProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupProtectedSecretProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretSelection;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlOptionalSecretSource;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlProtectedSecretSource;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlRowSource;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableSchemaReader;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\Document\ApprovedRevisionPayslipBatchService;
use MyInvoice\Service\Payroll\Net\DeductionAgreementStatus;
use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculationPipeline;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculator;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Run\PayrollRunGarnishmentProcessor;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Travel\BusinessTripMaterializer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\JmhzSpecPackageFixtureTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollRunPersistenceTest extends TestCase
{
    use IsolatedSupplierTrait;
    use JmhzSpecPackageFixtureTrait;

    private Connection $db;
    private ContainerInterface $container;
    private PayrollRunsAction $action;
    private PayrollRunCommandService $service;
    private PayrollRunCommandService $productionService;
    private PayrollRunRepository $runs;
    private PayrollStatutoryAccumulatorRepository $statutoryAccumulators;
    private PayrollEmployerPolicyRepository $policies;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employerPolicyId;
    private int $employeeId;
    private int $employmentId;
    private int $inputId;
    /** @var list<int> */
    private array $actors;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->container = $container;
        $db = $container->get(Connection::class);
        $action = $container->get(PayrollRunsAction::class);
        $runs = $container->get(PayrollRunRepository::class);
        $productionService = $container->get(PayrollRunCommandService::class);
        $approvedPosting = $this->createStub(
            PayrollApprovedRevisionPostingService::class,
        );
        $approvedPosting->method('post')->willReturn([]);
        $service = new PayrollRunCommandService(
            $db,
            $runs,
            $container->get(PayrollRunSnapshotBuilder::class),
            new PayrollRunCalculationPipeline(
                $container->get(PayrollRunCalculator::class),
                $container->get(PayrollRunGarnishmentProcessor::class),
            ),
            $container->get(PayrollRunWorkflow::class),
            $container->get(PayrollPeriodOwnershipService::class),
            $approvedPosting,
        );
        $statutoryAccumulators = $container->get(
            PayrollStatutoryAccumulatorRepository::class,
        );
        $policies = $container->get(PayrollEmployerPolicyRepository::class);
        if (!$db instanceof Connection
            || !$action instanceof PayrollRunsAction
            || !$service instanceof PayrollRunCommandService
            || !$productionService instanceof PayrollRunCommandService
            || !$runs instanceof PayrollRunRepository
            || !$statutoryAccumulators instanceof PayrollStatutoryAccumulatorRepository
            || !$policies instanceof PayrollEmployerPolicyRepository
        ) {
            throw new \RuntimeException('Služby mzdového běhu nejsou dostupné.');
        }
        $this->db = $db;
        $this->action = $action;
        $this->service = $service;
        $this->productionService = $productionService;
        $this->runs = $runs;
        $this->statutoryAccumulators = $statutoryAccumulators;
        $this->policies = $policies;
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_run_commands',
            'payroll_run_events',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Migrace MZ-09 neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)'
        )->execute([$this->supplierId, $this->otherSupplierId]);
        $this->actors = [
            $this->createActor('calculator'),
            $this->createActor('reviewer'),
            $this->createActor('approver'),
        ];
        // Firma zrovna dokončila nastavení a jde počítat první mzdu. Do
        // `active` se modul překlopí sám (setup-check / první schválení),
        // takže ho sem ručně vkládat nemusíme — mzdové běhy jedou i v `setup`.
        foreach ([$this->supplierId, $this->otherSupplierId] as $supplierId) {
            $pdo->prepare(
                'INSERT INTO payroll_module_state
                    (supplier_id, status, start_period, activated_by, activated_at)
                 VALUES (?, "setup", "2026-01-01", ?, NOW())'
            )->execute([$supplierId, $this->actors[0]]);
        }
        $policy = $this->policies->create(
            $this->supplierId,
            $this->employerPolicyInput(),
            $this->actors[0],
        );
        $this->employerPolicyId = (int) $policy['id'];
        [$this->employeeId, $this->employmentId] = $this->employment();
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_person_month_evidence
                (supplier_id, employee_id, period_start,
                 claim_register_evidence_complete,
                 dependants_evidence_complete, spouse_evidence_complete,
                 pension_evidence, updated_by)
             VALUES (?, ?, "2026-06-01", 1, 1, 1, "none", ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->actors[0],
        ]);
        $this->inputId = $this->approvedInput(120_000, 'BASE', 'manual');
    }

    public function testSessionApiCreatesListsAndLocksRunIdempotently(): void
    {
        $role = new EffectiveRole(
            90,
            'Syntetická mzdová účetní',
            'staff',
            true,
            [
                'payroll' => AccessLevel::READ->value,
                'payroll.inputs.write' => AccessLevel::WRITE->value,
                'payroll.calculate' => AccessLevel::WRITE->value,
                'payroll.review' => AccessLevel::WRITE->value,
                'payroll.approve' => AccessLevel::WRITE->value,
                'payroll.reopen' => AccessLevel::WRITE->value,
            ],
        );
        $createdResponse = $this->action->create(
            $this->apiRequest('POST', '/api/payroll/runs', $role)
                ->withParsedBody([
                    'period_start' => '2026-06-01',
                    'payment_date' => '2026-07-15',
                ]),
            new Response(),
        );
        self::assertSame(201, $createdResponse->getStatusCode());
        $created = $this->json($createdResponse)['run'];
        self::assertSame('2026-07-15', $created['payment_date']);
        self::assertSame('draft', $created['status']);

        $listResponse = $this->action->list(
            $this->apiRequest('GET', '/api/payroll/runs?period=2026-06', $role)
                ->withQueryParams(['period' => '2026-06']),
            new Response(),
        );
        self::assertSame(200, $listResponse->getStatusCode());
        $runs = $this->json($listResponse)['runs'];
        self::assertCount(1, $runs);
        self::assertSame('2026-07-15', $runs[0]['payment_date']);
        self::assertContains('lock_inputs', $runs[0]['available_commands']);

        $request = $this->apiRequest(
            'POST',
            "/api/payroll/runs/{$created['id']}/commands/lock_inputs",
            $role,
        )->withHeader('Idempotency-Key', 'api-lock-synthetic-run')
            ->withParsedBody(['row_version' => $created['row_version']]);
        $lockedResponse = $this->action->command(
            $request,
            new Response(),
            ['id' => (string) $created['id'], 'command' => 'lock_inputs'],
        );
        self::assertSame(200, $lockedResponse->getStatusCode());
        $locked = $this->json($lockedResponse);
        self::assertSame('inputs_locked', $locked['run']['status']);
        self::assertFalse($locked['idempotent_replay']);

        $replayResponse = $this->action->command(
            $request,
            new Response(),
            ['id' => (string) $created['id'], 'command' => 'lock_inputs'],
        );
        self::assertSame(200, $replayResponse->getStatusCode());
        self::assertTrue($this->json($replayResponse)['idempotent_replay']);

        $bearerResponse = $this->action->list(
            $this->apiRequest(
                'GET',
                '/api/payroll/runs',
                $role,
                'bearer',
            ),
            new Response(),
        );
        self::assertSame(403, $bearerResponse->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($bearerResponse)['error']['code'],
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testPaymentDateIsRequiredAtDatabaseBoundary(): void
    {
        $this->db->pdo()->exec('SET SESSION check_constraint_checks = 1');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, "2032-01-01", NULL)'
        );

        $this->expectException(PDOException::class);
        $stmt->execute([$this->supplierId]);
    }

    public function testInputSnapshotFreezesStatutoryPeriodAndPersonEvidence(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from,
                 evidence_reference)
             VALUES (?, ?, "signed", "2026-01-01",
                     "document:synthetic-tax-declaration")'
        )->execute([$this->supplierId, $this->employeeId]);

        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-statutory-evidence',
            $this->actors[0],
        );
        $snapshot = $locked->revision['input_snapshot'];

        self::assertSame('payroll-run-input.v2', $snapshot['schema_version']);
        self::assertSame('2026-06-30', $snapshot['statutory_period']['tax_calculation_date']);
        self::assertSame('2026-06-30', $snapshot['statutory_period']['social_calculation_date']);
        self::assertSame('2026-06-30', $snapshot['statutory_period']['health_calculation_date']);
        self::assertSame('2026-07-15', $snapshot['statutory_period']['payment_date']);
        self::assertSame(
            1,
            $snapshot['people'][0]['employments'][0]['term']['row_version'],
        );
        self::assertSame(
            'signed',
            $snapshot['people'][0]['statutory_evidence']['income_tax']['declaration']['status'],
        );

        $this->db->pdo()->prepare(
            'UPDATE payroll_person_tax_declarations
                SET status = "not-signed",
                    evidence_reference = "document:synthetic-revocation"
              WHERE supplier_id = ? AND employee_id = ?'
        )->execute([$this->supplierId, $this->employeeId]);

        self::assertSame(
            'signed',
            $this->runs->revision(
                $this->supplierId,
                (int) $locked->revision['id'],
            )['input_snapshot']['people'][0]['statutory_evidence']
                ['income_tax']['declaration']['status'],
        );
    }

    public function testInputSnapshotFreezesVerifiedAnnualAccumulatorStates(): void
    {
        $socialOpeningId = $this->statutoryAccumulators->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'social_insurance',
            ['assessment_base_minor_units' => 0],
            'synthetic:social-opening',
            ['verified_zero' => true],
            'snapshot-social-opening',
            actorUserId: $this->actors[0],
        );
        $this->statutoryAccumulators->appendOpeningBalance(
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
            'snapshot-tax-opening',
            actorUserId: $this->actors[0],
        );

        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-statutory-accumulators',
            $this->actors[0],
        );
        $accumulators = $locked->revision['input_snapshot']['people'][0]
            ['statutory_accumulators'];

        self::assertSame(
            'payroll-person-statutory-accumulators.v1',
            $accumulators['schema_version'],
        );
        self::assertSame('verified', $accumulators['social_insurance']['status']);
        self::assertSame(
            0,
            $accumulators['social_insurance']['state']['totals']
                ['assessment_base_minor_units'],
        );
        self::assertSame('verified', $accumulators['income_tax']['status']);
        self::assertSame(
            0,
            $accumulators['income_tax']['state']['totals']['completed_months'],
        );

        $this->statutoryAccumulators->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'social_insurance',
            ['assessment_base_minor_units' => 100],
            'synthetic:social-opening-correction',
            ['verified_zero' => false],
            'snapshot-social-opening-correction',
            $socialOpeningId,
            $this->actors[0],
        );

        self::assertSame(
            0,
            $this->runs->revision(
                $this->supplierId,
                (int) $locked->revision['id'],
            )['input_snapshot']['people'][0]['statutory_accumulators']
                ['social_insurance']['state']['totals']
                ['assessment_base_minor_units'],
        );
    }

    public function testInputSnapshotFreezesDeductionsAndPayoutRules(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_deduction_agreements
                (supplier_id, employee_id, agreement_reference, title,
                 deduction_kind, status, priority_no, requested_minor,
                 total_limit_minor, withheld_total_minor, valid_from,
                 created_by, updated_by)
             VALUES (?, ?, "SYNTHETIC-MEAL", "Syntetická srážka",
                     "meal", "active", 20, 2500, 10000, 3000,
                     "2026-01-01", ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->actors[0],
            $this->actors[0],
        ]);
        $agreementId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext,
                 bank_account_hash, bank_account_masked,
                 allocation_basis_points, effective_from, is_active,
                 verification_source, verified_on, verified_by)
             VALUES (?, ?, "Syntetický účet", "enc:v2:synthetic-account",
                     ?, "••••0005/0100", 10000, "2026-01-01", 1,
                     "user_verified", "2026-05-01", ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            hash('sha256', 'synthetic-account', true),
            $this->actors[0],
        ]);
        $accountId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payout_rules
                (supplier_id, employee_id, allocation_reference,
                 destination_kind, destination_reference, allocation_kind,
                 priority_no, is_active)
             VALUES (?, ?, "SYNTHETIC-REMAINDER", "bank",
                     ?, "remainder", 100, 1)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            "account:{$accountId}",
        ]);
        $payoutRuleId = (int) $this->db->pdo()->lastInsertId();

        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-deductions-and-payout-rules',
            $this->actors[0],
        );
        $person = $locked->revision['input_snapshot']['people'][0];

        self::assertSame($agreementId, $person['deduction_agreements'][0]['id']);
        self::assertSame(3000, $person['deduction_agreements'][0]['withheld_total_minor']);
        self::assertSame($payoutRuleId, $person['payout_rules'][0]['id']);
        self::assertSame('remainder', $person['payout_rules'][0]['allocation_kind']);
        self::assertSame([
            'allocation_basis_points' => 10000,
            'bank_account_hash' => hash('sha256', 'synthetic-account'),
            'bank_account_masked' => '••••0005/0100',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'id' => $accountId,
            'label' => 'Syntetický účet',
            'row_version' => 1,
            'verification_source' => 'user_verified',
            'verified_by' => $this->actors[0],
            'verified_on' => '2026-05-01',
        ], $person['payout_accounts'][0]);

        $this->db->pdo()->prepare(
            'UPDATE payroll_deduction_agreements
                SET withheld_total_minor = 4000
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $agreementId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_payout_rules SET is_active = 0
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $payoutRuleId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_accounts
                SET bank_account_ciphertext = "enc:v2:changed-account",
                    bank_account_hash = ?,
                    bank_account_masked = "••••1116/0100",
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([
            hash('sha256', 'changed-account', true),
            $this->supplierId,
            $accountId,
        ]);

        $persisted = $this->runs->revision(
            $this->supplierId,
            (int) $locked->revision['id'],
        )['input_snapshot']['people'][0];
        self::assertSame(
            3000,
            $persisted['deduction_agreements'][0]['withheld_total_minor'],
        );
        self::assertSame($payoutRuleId, $persisted['payout_rules'][0]['id']);
        self::assertSame(
            hash('sha256', 'synthetic-account'),
            $persisted['payout_accounts'][0]['bank_account_hash'],
        );
        self::assertSame(
            '2026-05-01',
            $persisted['payout_accounts'][0]['verified_on'],
        );
    }

    public function testInputSnapshotFreezesEmployerPostingPolicy(): void
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-employer-posting-policy',
            $this->actors[0],
        );

        self::assertSame([
            'automatic_posting_enabled' => true,
            'id' => $this->employerPolicyId,
            'row_version' => 1,
        ], $locked->revision['input_snapshot']['employer_policy']);

        $this->policies->update(
            $this->supplierId,
            $this->employerPolicyId,
            $this->employerPolicyInput(false),
            1,
            $this->actors[0],
        );

        self::assertSame([
            'automatic_posting_enabled' => true,
            'id' => $this->employerPolicyId,
            'row_version' => 1,
        ], $this->runs->revision(
            $this->supplierId,
            (int) $locked->revision['id'],
        )['input_snapshot']['employer_policy']);
    }

    public function testMissingEffectiveEmployerPolicyFailsClosed(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'chybí účinná zaměstnavatelská politika',
        );
        $this->container->get(PayrollRunSnapshotBuilder::class)->build(
            $this->otherSupplierId,
            '2026-06-01',
            '2026-07-15',
        );
    }

    public function testInputSnapshotKeepsEmploymentArchivedAfterPayrollPeriod(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_events
                (supplier_id, employment_id, event_type, from_status,
                 to_status, effective_on, created_by)
             VALUES
                (?, ?, "created", NULL, "active", "2026-01-01", ?),
                (?, ?, "status_changed", "active", "archived", "2026-07-01", ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $this->actors[0],
            $this->supplierId,
            $this->employmentId,
            $this->actors[0],
        ]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET status = "archived"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $snapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build(
                $this->supplierId,
                '2026-06-01',
                '2026-07-15',
            );

        self::assertSame(
            $this->employmentId,
            $snapshot->data['people'][0]['employments'][0]['employment']['id'],
        );
        self::assertSame(
            'active',
            $snapshot->data['people'][0]['employments'][0]['employment']['status'],
        );
    }

    public function testInputSnapshotPinsEffectiveJmhzEmploymentEvidence(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET work_place = "Hlavní město Praha",
                    jmhz_workplace_municipality_code = "554782",
                    jmhz_workplace_country_code = "CZ",
                    jmhz_external_codebook_overlay_key = ?,
                    jmhz_external_codebook_manifest_sha256 = ?,
                    jmhz_apz_contribution_status = "yes",
                    jmhz_apz_instrument_code = "4",
                    jmhz_functional_benefits_status = "no",
                    jmhz_temporary_assignment_status = "unverified",
                    jmhz_orchard_discount_eligible = 0,
                    jmhz_specific_legal_fact_applies = 0,
                    jmhz_ozp_employment_support_applies = 0,
                    jmhz_deep_mining_work_applies = 1
                    , activity_code = "1"
                    , jmhz_relationship_detail_code = "1"
              WHERE supplier_id = ? AND employment_id = ?'
        )->execute([
            JmhzExternalCodebookCatalog::HISTORICAL_OVERLAY_KEY,
            JmhzExternalCodebookCatalog::HISTORICAL_MANIFEST_SHA256,
            $this->supplierId,
            $this->employmentId,
        ]);

        $snapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, '2026-06-01', '2026-07-15');
        $term = $snapshot->data['people'][0]['employments'][0]['term'];

        self::assertSame('Hlavní město Praha', $term['work_place']);
        self::assertSame('554782', $term['jmhz_workplace_municipality_code']);
        self::assertSame('CZ', $term['jmhz_workplace_country_code']);
        self::assertSame(
            JmhzExternalCodebookCatalog::HISTORICAL_MANIFEST_SHA256,
            $term['jmhz_external_codebook_manifest_sha256'],
        );
        self::assertTrue($term['jmhz_external_codebooks_verified_for_period']);
        self::assertSame(
            JmhzExternalCodebookCatalog::AUGUST_2026_OVERLAY_KEY,
            $term['jmhz_validation_external_codebook_overlay_key'],
        );
        self::assertSame('yes', $term['jmhz_apz_contribution_status']);
        self::assertSame('4', $term['jmhz_apz_instrument_code']);
        self::assertSame('no', $term['jmhz_functional_benefits_status']);
        self::assertSame('unverified', $term['jmhz_temporary_assignment_status']);
        self::assertSame('1', $term['activity_code']);
        self::assertSame('1', $term['jmhz_relationship_detail_code']);
        self::assertSame([
            'source_term_id' => $term['id'],
            'source_term_row_version' => $term['row_version'],
            'orchard_discount_eligible' => false,
            'specific_legal_fact_applies' => false,
            'ozp_employment_support_applies' => false,
            'deep_mining_work_applies' => true,
        ], $snapshot->data['people'][0]['employments'][0]['ordinary_evidence_profile']);

        $augustPaidInSeptemberSnapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, '2026-08-01', '2026-09-15');
        $augustPaidInSeptemberTerm =
            $augustPaidInSeptemberSnapshot->data['people'][0]['employments'][0]['term'];
        self::assertTrue($augustPaidInSeptemberTerm['jmhz_external_codebooks_verified_for_period']);
        self::assertSame(
            JmhzExternalCodebookCatalog::AUGUST_2026_OVERLAY_KEY,
            $augustPaidInSeptemberTerm['jmhz_validation_external_codebook_overlay_key'],
        );

        $septemberSnapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, '2026-09-01', '2026-09-30');
        $septemberTerm = $septemberSnapshot->data['people'][0]['employments'][0]['term'];
        self::assertTrue($septemberTerm['jmhz_external_codebooks_verified_for_period']);
        self::assertSame(
            JmhzExternalCodebookCatalog::DEFAULT_OVERLAY_KEY,
            $septemberTerm['jmhz_validation_external_codebook_overlay_key'],
        );
    }

    public function testInputSnapshotPinsEffectivePrimaryEmploymentAndApprovedAverageEarning(): void
    {
        $inputTrace = CanonicalJson::encode(['synthetic' => true]);
        $inputHash = hash('sha256', $inputTrace);
        $rulesetHash = str_repeat('a', 64);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_average_earning_snapshots
                (supplier_id, employment_id, applicable_year,
                 applicable_quarter, revision_no, source_kind,
                 decisive_from, decisive_to, gross_earnings_minor,
                 longer_period_allocated_minor, worked_minutes, worked_days,
                 average_hourly_minor, rationale, support_status, status,
                 ruleset_id, ruleset_hash, input_hash, input_trace,
                 created_by, approved_by, approved_at)
             VALUES (?, ?, 2026, 2, 1, "probable",
                     "2026-01-01", "2026-03-31", 0, 0, 0, 0,
                     27550, "Syntetický pravděpodobný výdělek", "supported",
                     "approved", "synthetic-average-v1", ?, UNHEX(?), ?,
                     ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $rulesetHash,
            $inputHash,
            $inputTrace,
            $this->actors[0],
            $this->actors[0],
        ]);
        $averageId = (int) $this->db->pdo()->lastInsertId();

        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET effective_to = "2026-08-31"
              WHERE supplier_id = ? AND employment_id = ?
                AND effective_from = "2026-01-01"'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on,
                 actual_start_on, weekly_hours, workload_basis_points,
                 social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 tax_declaration_signed, is_primary)
             VALUES (?, ?, "2026-09-01", "2026-01-01", "2026-01-01",
                     40, 10000, "automatic", "automatic", "advance", 1, 0)'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET is_primary = 0
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $snapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, '2026-06-01', '2026-07-15');
        $entry = $snapshot->data['people'][0]['employments'][0];

        self::assertTrue($entry['employment']['is_primary']);
        self::assertSame([
            'id' => $averageId,
            'row_version' => 1,
            'applicable_year' => 2026,
            'applicable_quarter' => 2,
            'revision_no' => 1,
            'source_kind' => 'probable',
            'average_hourly_minor' => 27550,
            'support_status' => 'supported',
            'status' => 'approved',
            'ruleset_id' => 'synthetic-average-v1',
            'ruleset_hash' => $rulesetHash,
            'input_hash' => $inputHash,
        ], $entry['average_earning']);

        $future = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, '2026-09-01', '2026-10-15');
        self::assertFalse(
            $future->data['people'][0]['employments'][0]['employment']['is_primary'],
        );
    }

    public function testInputSnapshotPinsImmutableJmhzWorkMonthCoreRevision(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, revision_no,
                 row_version, last_changed_by, approved_by, approved_at)
             VALUES (?, ?, "2026-06-01", "approved", 1, 2, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $this->actors[0],
            $this->actors[0],
        ]);
        $timeMonthId = (int) $this->db->pdo()->lastInsertId();
        $sourceJson = CanonicalJson::encode([
            'schema_version' => 'jmhz-work-month-core.v1',
            'synthetic_source' => true,
        ]);
        $sourceHash = hash('sha256', $sourceJson);
        $specification = [
            'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
            'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
            'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
        ];
        $specPackageId = $this->installDefaultJmhzSpecPackage($this->db);
        $values = [
            'standard_fund_millihours' => 168000,
            'agreed_fund_millihours' => 168000,
            'weekly_work_centihours' => 4000,
            'evidence_days' => 30,
            'worked_millihours' => 160000,
        ];
        $provenance = [
            'decimal_policy' => 'exact_user_confirmed_value_without_rounding',
        ];
        $confirmationNote = 'Potvrzeno syntetickým integračním testem.';
        $summaryPayload = [
            'derivation_version' => 'jmhz-work-month-core.v1',
            'specification' => $specification,
            'source_snapshot_sha256' => $sourceHash,
            'values' => $values,
            'provenance' => $provenance,
            'confirmation_note' => $confirmationNote,
        ];
        $summaryHash = hash('sha256', CanonicalJson::encode($summaryPayload));
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_work_month_revisions
                (supplier_id, employment_id, time_month_id, time_month_revision_no,
                 period_start, spec_package_id, spec_manifest_sha256,
                 scenario_catalog_key, scenario_manifest_sha256,
                 derivation_version, source_snapshot_json,
                 source_snapshot_sha256, standard_fund_millihours,
                 agreed_fund_millihours, weekly_work_centihours, evidence_days,
                 worked_millihours, confirmation_note, provenance_json,
                 summary_sha256, approved_by, approved_at)
             VALUES (?, ?, ?, 1, "2026-06-01", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $timeMonthId,
            $specPackageId,
            $specification['spec_manifest_sha256'],
            $specification['scenario_catalog_key'],
            $specification['scenario_manifest_sha256'],
            'jmhz-work-month-core.v1',
            $sourceJson,
            $sourceHash,
            ...array_values($values),
            $confirmationNote,
            CanonicalJson::encode($provenance),
            $summaryHash,
            $this->actors[0],
        ]);

        $snapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, '2026-06-01', '2026-07-15');
        $timeMonth = $snapshot->data['people'][0]['employments'][0]['time_month'];

        self::assertSame('frozen_core', $timeMonth['jmhz_work_summary_status']);
        self::assertSame($summaryHash, $timeMonth['jmhz_work_summary']['summary_sha256']);
        self::assertSame($sourceHash, $timeMonth['jmhz_work_summary']['source_snapshot_sha256']);
        self::assertSame($values, $timeMonth['jmhz_work_summary']['values']);
    }

    public function testInputSnapshotPinsConditionalJmhzWorkMonthRevision(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, revision_no,
                 row_version, last_changed_by, approved_by, approved_at)
             VALUES (?, ?, "2026-06-01", "approved", 1, 2, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $this->actors[0],
            $this->actors[0],
        ]);
        $timeMonthId = (int) $this->db->pdo()->lastInsertId();
        $sourceJson = CanonicalJson::encode([
            'schema_version' => 'jmhz-work-month.v2',
            'synthetic_source' => true,
        ]);
        $sourceHash = hash('sha256', $sourceJson);
        $specification = [
            'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
            'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
            'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
            'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
            'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
        ];
        $specPackageId = $this->installDefaultJmhzSpecPackage($this->db);
        $values = [
            'standard_fund_millihours' => 168000,
            'agreed_fund_millihours' => 168000,
            'weekly_work_centihours' => 4000,
            'evidence_days' => 30,
            'worked_millihours' => 80000,
            'unworked_total_millihours' => 80000,
            'unworked_paid_millihours' => 0,
            'dpn_without_employer_compensation_millihours' => null,
            'dpn_with_employer_compensation_millihours' => 80000,
            'vacation_millihours' => null,
            'care_millihours' => null,
            'employee_obstacle_paid_millihours' => 80000,
            'employer_obstacle_millihours' => null,
        ];
        $interactions = ['IN07' => true, 'IN08' => true];
        $provenance = [
            'decimal_policy' => 'exact_user_confirmed_value_without_rounding',
            'validated_controls' => [23, 144, 145, 286],
        ];
        $confirmationNote = 'Potvrzeno syntetickým integračním testem.';
        $summaryPayload = [
            'derivation_version' => 'jmhz-work-month.v2',
            'specification' => $specification,
            'source_snapshot_sha256' => $sourceHash,
            'conditional_blocks_confirmed' => true,
            'interactions' => $interactions,
            'values' => $values,
            'provenance' => $provenance,
            'confirmation_note' => $confirmationNote,
        ];
        $summaryHash = hash('sha256', CanonicalJson::encode($summaryPayload));
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_work_month_revisions
                (supplier_id, employment_id, time_month_id, time_month_revision_no,
                 period_start, spec_package_id, spec_manifest_sha256,
                 scenario_catalog_key, scenario_manifest_sha256,
                 control_catalog_key, control_manifest_sha256,
                 derivation_version, source_snapshot_json, source_snapshot_sha256,
                 standard_fund_millihours, agreed_fund_millihours,
                 weekly_work_centihours, evidence_days, worked_millihours,
                 conditional_blocks_confirmed, unworked_hours_occurred,
                 work_obstacles_occurred, unworked_total_millihours,
                 unworked_paid_millihours,
                 dpn_without_employer_compensation_millihours,
                 dpn_with_employer_compensation_millihours, vacation_millihours,
                 care_millihours, employee_obstacle_paid_millihours,
                 employer_obstacle_millihours, confirmation_note, provenance_json,
                 summary_sha256, approved_by, approved_at)
             VALUES (?, ?, ?, 1, "2026-06-01", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     1, 1, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $timeMonthId,
            $specPackageId,
            $specification['spec_manifest_sha256'],
            $specification['scenario_catalog_key'],
            $specification['scenario_manifest_sha256'],
            $specification['control_catalog_key'],
            $specification['control_manifest_sha256'],
            'jmhz-work-month.v2',
            $sourceJson,
            $sourceHash,
            ...array_values(array_slice($values, 0, 5, true)),
            ...array_values(array_slice($values, 5, null, true)),
            $confirmationNote,
            CanonicalJson::encode($provenance),
            $summaryHash,
            $this->actors[0],
        ]);

        $snapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, '2026-06-01', '2026-07-15');
        $timeMonth = $snapshot->data['people'][0]['employments'][0]['time_month'];

        self::assertSame('frozen_work_summary', $timeMonth['jmhz_work_summary_status']);
        self::assertSame($summaryHash, $timeMonth['jmhz_work_summary']['summary_sha256']);
        self::assertSame($interactions, $timeMonth['jmhz_work_summary']['interactions']);
        self::assertSame($values, $timeMonth['jmhz_work_summary']['values']);
        self::assertSame(
            JmhzControlSourceCatalog::MANIFEST_SHA256,
            $timeMonth['jmhz_work_summary']['specification']['control_manifest_sha256'],
        );
    }

    public function testPostTerminationInputKeepsEndedRelationshipInSnapshot(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET status = "ended", end_date = "2026-05-31"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-post-termination-income',
            $this->actors[0],
        );
        $employment = $locked->revision['input_snapshot']['people'][0]
            ['employments'][0]['employment'];

        self::assertSame($this->employmentId, $employment['id']);
        self::assertSame('2026-05-31', $employment['end_date']);
        self::assertSame(
            $this->inputId,
            $locked->revision['input_snapshot']['people'][0]
                ['employments'][0]['inputs'][0]['id'],
        );
    }

    public function testSnapshotRemainsStableAndFourEyeWorkflowIsAudited(): void
    {
        $approvedPosting = $this->createMock(
            PayrollApprovedRevisionPostingService::class,
        );
        $this->service = new PayrollRunCommandService(
            $this->db,
            $this->runs,
            $this->container->get(PayrollRunSnapshotBuilder::class),
            new PayrollRunCalculationPipeline(
                $this->container->get(PayrollRunCalculator::class),
                $this->container->get(PayrollRunGarnishmentProcessor::class),
            ),
            $this->container->get(PayrollRunWorkflow::class),
            $this->container->get(PayrollPeriodOwnershipService::class),
            $approvedPosting,
        );
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-stable-snapshot',
            $this->actors[0],
        );
        self::assertSame('inputs_locked', $locked->run['status']);
        self::assertSame('snapshot', $locked->revision['status']);
        self::assertSame(
            'locked',
            $this->scalar(
                'SELECT status FROM payroll_inputs WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, $this->inputId],
            ),
        );
        $inputHash = $locked->revision['input_snapshot_hash'];

        $this->db->pdo()->prepare(
            'UPDATE payroll_employees SET full_name = "Changed after lock"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employeeId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_inputs SET amount_minor = 999999
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->inputId]);

        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-stable-snapshot',
            $this->actors[0],
        );
        self::assertSame('calculated', $calculated->run['status']);
        self::assertSame(
            120_000,
            $calculated->revision['result_snapshot']['totals']['source_amount_minor'],
        );
        self::assertSame($inputHash, $calculated->revision['input_snapshot_hash']);

        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-four-eyes',
            $this->actors[1],
        );
        $approvedPosting->expects(self::once())
            ->method('post')
            ->with(
                $this->supplierId,
                (int) $reviewed->revision['id'],
                $reviewed->revision['input_snapshot'],
                $reviewed->revision['result_snapshot'],
                $this->actors[2],
            )
            ->willReturn([]);
        $approved = $this->service->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'approve-four-eyes',
            $this->actors[2],
        );
        self::assertSame('approved', $approved->run['status']);
        self::assertSame($this->actors[0], $approved->revision['calculated_by']);
        self::assertSame($this->actors[1], $approved->revision['reviewed_by']);
        self::assertSame($this->actors[2], $approved->revision['approved_by']);

        $events = $this->runs->events($this->supplierId, (int) $run['id']);
        self::assertSame(
            ['created', 'lock_inputs', 'calculate', 'review', 'approve'],
            array_column($events, 'event_type'),
        );
        self::assertCount(4, array_filter(
            $events,
            static fn (array $event): bool =>
                isset($event['metadata']['idempotency_key_hash']),
        ));
        self::assertStringNotContainsString(
            'approve-four-eyes',
            CanonicalJson::encode(['events' => $events]),
        );

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_run_revisions SET input_snapshot_hash = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([
            str_repeat('0', 64),
            $this->supplierId,
            (int) $approved->revision['id'],
        ]);
    }

    public function testCompanyBackupStreamsComponentDefinitionWithAccountCodes(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_component_definitions',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        $componentId = (int) $this->scalar(
            'SELECT component_id
               FROM payroll_inputs
              WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $this->inputId],
        );
        self::assertSame($componentId, (int) $row['id']);
        self::assertSame('BASE', $row['code']);
        self::assertSame('Synthetic BASE', $row['name']);
        self::assertSame('521', $row['accounting_debit_code']);
        self::assertSame('331', $row['accounting_credit_code']);
        self::assertSame('2026-01-01', $row['valid_from']);
        self::assertNull($row['valid_to']);
        self::assertSame(1, (int) $row['is_active']);
    }

    public function testCompanyBackupStreamsOnlySelectedSupplierAiSalt(): void
    {
        $salt = hash('sha256', 'synthetic-company-backup-salt', true);
        $foreignSalt = hash('sha256', 'synthetic-foreign-backup-salt', true);
        $this->db->pdo()->prepare(
            'UPDATE supplier SET ai_pseudo_salt = ? WHERE id = ?'
        )->execute([$salt, $this->supplierId]);
        $this->db->pdo()->prepare(
            'UPDATE supplier SET ai_pseudo_salt = ? WHERE id = ?'
        )->execute([$foreignSalt, $this->otherSupplierId]);

        $definition = TenantDataRegistryFactory::draftV1()->definition(
            'table:supplier',
        );
        self::assertNotNull($definition);
        $encryption = $this->container->get(SecretEncryption::class);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        $values = iterator_to_array(
            (new CompanyBackupSqlProtectedSecretSource($encryption))->values(
                $this->db->pdo(),
                $this->supplierId,
                CompanyBackupProtectedSecretProjection::fromDefinition(
                    $definition,
                ),
            ),
            false,
        );

        self::assertCount(1, $values);
        self::assertSame('table:supplier', $values[0]->registryKey);
        self::assertSame('ai_pseudo_salt', $values[0]->name);
        self::assertSame(['id' => $this->supplierId], $values[0]->primaryKey);
        self::assertSame($salt, $values[0]->plaintext());
        self::assertNotSame($foreignSalt, $values[0]->plaintext());
    }

    public function testCompanyBackupStreamsOnlyExplicitSupplierCredential(): void
    {
        $encryption = $this->container->get(SecretEncryption::class);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        $plaintext = 'synthetic-selected-api-key-company-a';
        $foreignPlaintext = 'synthetic-selected-api-key-company-b';
        $this->db->pdo()->prepare(
            'UPDATE supplier SET openai_api_key_enc = ? WHERE id = ?'
        )->execute([$encryption->encrypt($plaintext), $this->supplierId]);
        $this->db->pdo()->prepare(
            'UPDATE supplier SET openai_api_key_enc = ? WHERE id = ?'
        )->execute([
            $encryption->encrypt($foreignPlaintext),
            $this->otherSupplierId,
        ]);

        $draft = TenantDataRegistryFactory::draftV1();
        $definition = $draft->definition('table:supplier');
        self::assertNotNull($definition);
        $registry = TenantDataRegistrySnapshot::fromRegistry(
            new TenantDataRegistry(
                $draft->version,
                [$definition],
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            ),
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        );
        $selection = CompanyBackupSecretSelection::fromArray([
            'registry_fingerprint' => $registry->fingerprint,
            'entries' => [[
                'registry_key' => $definition->key,
                'scope' => 'column',
                'name' => 'openai_api_key_enc',
                'primary_key' => ['id' => $this->supplierId],
            ]],
        ], $registry);
        $source = new CompanyBackupSqlOptionalSecretSource($encryption);
        $values = iterator_to_array($source->values(
            $this->db->pdo(),
            $this->supplierId,
            CompanyBackupOptionalSecretProjection::fromSelection(
                $definition,
                $selection->entries(),
            ),
        ), false);

        self::assertCount(1, $values);
        self::assertSame('table:supplier', $values[0]->registryKey);
        self::assertSame('openai_api_key_enc', $values[0]->name);
        self::assertSame(['id' => $this->supplierId], $values[0]->primaryKey);
        self::assertSame($plaintext, $values[0]->plaintext());
        self::assertNotSame($foreignPlaintext, $values[0]->plaintext());

        $foreignSelection = CompanyBackupSecretSelection::fromArray([
            'registry_fingerprint' => $registry->fingerprint,
            'entries' => [[
                'registry_key' => $definition->key,
                'scope' => 'column',
                'name' => 'openai_api_key_enc',
                'primary_key' => ['id' => $this->otherSupplierId],
            ]],
        ], $registry);
        try {
            iterator_to_array($source->values(
                $this->db->pdo(),
                $this->supplierId,
                CompanyBackupOptionalSecretProjection::fromSelection(
                    $definition,
                    $foreignSelection->entries(),
                ),
            ));
            self::fail('Credential jiné firmy nesmí projít tenantovým guardem.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('secret_selection_tenant_mismatch', $e->errorCode);
            self::assertSame('openai_api_key_enc', $e->column);
        }
    }

    public function testCompanyBackupStreamsAverageEarningSnapshotWithBinaryInputHash(): void
    {
        $input = CanonicalJson::encode([
            'allocated_minor' => 125_000,
            'decisive_from' => '2026-01-01',
            'decisive_to' => '2026-03-31',
            'gross_minor' => 1_200_000,
            'rationale' => null,
            'worked_days' => 60,
            'worked_minutes' => 9_600,
        ]);
        $trace = CanonicalJson::encode([
            'average_hourly_minor' => 8_281,
            'rule' => 'gross-earnings-divided-by-worked-time',
            'rounding' => 'half-up-to-minor-unit',
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_average_earning_snapshots
                (supplier_id, employment_id, applicable_year,
                 applicable_quarter, revision_no, source_kind,
                 decisive_from, decisive_to, gross_earnings_minor,
                 longer_period_allocated_minor, worked_minutes, worked_days,
                 average_hourly_minor, support_status, status, ruleset_id,
                 ruleset_hash, input_hash, input_trace, created_by,
                 approved_by, approved_at)
             VALUES (?, ?, 2026, 2, 3, "actual", "2026-01-01",
                     "2026-03-31", 1200000, 125000, 9600, 60, 8281,
                     "supported", "approved", "synthetic-average-v1", ?, ?,
                     ?, ?, ?, "2026-04-02 10:00:00")'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            str_repeat('a', 64),
            hash('sha256', $input, true),
            $trace,
            $this->actors[0],
            $this->actors[1],
        ]);
        $snapshotId = (int) $this->db->pdo()->lastInsertId();
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_average_earning_snapshots',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($snapshotId, (int) $row['id']);
        self::assertSame($this->employmentId, (int) $row['employment_id']);
        self::assertSame(2026, (int) $row['applicable_year']);
        self::assertSame(2, (int) $row['applicable_quarter']);
        self::assertSame(3, (int) $row['revision_no']);
        self::assertSame('actual', $row['source_kind']);
        self::assertSame('synthetic-average-v1', $row['ruleset_id']);
        self::assertSame(str_repeat('a', 64), $row['ruleset_hash']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            (string) $row['input_hash'],
        );
        self::assertSame(hash('sha256', $input), $row['input_hash']);
        self::assertSame($trace, $row['input_trace']);
        self::assertSame($this->actors[0], (int) $row['created_by']);
        self::assertSame($this->actors[1], (int) $row['approved_by']);
        self::assertSame('2026-04-02 10:00:00', $row['approved_at']);
    }

    public function testCompanyBackupStreamsApprovedAbsenceWithAverageReference(): void
    {
        $fixture = $this->approvedAbsenceWithAverage();
        $averageId = $fixture['average_id'];
        $absenceId = $fixture['absence_id'];
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_absences');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($absenceId, (int) $row['id']);
        self::assertSame($this->employmentId, (int) $row['employment_id']);
        self::assertSame('vacation', $row['absence_type']);
        self::assertSame('2026-06-15', $row['date_from']);
        self::assertSame('2026-06-16', $row['date_to']);
        self::assertSame(240, (int) $row['partial_first_minutes']);
        self::assertSame(180, (int) $row['partial_last_minutes']);
        self::assertSame('average_100', $row['compensation_policy']);
        self::assertSame(10_000, (int) $row['compensation_rate_basis_points']);
        self::assertSame($averageId, (int) $row['average_snapshot_id']);
        self::assertSame('supported', $row['support_status']);
        self::assertSame('approved', $row['status']);
        self::assertSame(0, (int) $row['correction_pending']);
        self::assertSame($this->actors[0], (int) $row['requested_by']);
        self::assertSame($this->actors[1], (int) $row['decided_by']);
        self::assertSame('2026-06-01 09:00:00', $row['decided_at']);
    }

    public function testCompanyBackupStreamsSicknessEventWithRulesetTrace(): void
    {
        $fixture = $this->sicknessEvent();
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_sickness_events');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($fixture['event_id'], (int) $row['id']);
        self::assertSame($fixture['absence_id'], (int) $row['absence_id']);
        self::assertSame(
            $fixture['average_id'],
            (int) $row['average_snapshot_id'],
        );
        self::assertSame(0, (int) $row['first_day_fully_worked']);
        self::assertSame(1, (int) $row['insurance_eligibility_confirmed']);
        self::assertSame(1, (int) $row['conflicting_benefit_excluded']);
        self::assertSame('2026-06-15', $row['compensation_window_from']);
        self::assertSame('2026-06-16', $row['compensation_window_to']);
        self::assertSame(4_500, (int) $row['reduced_hourly_minor']);
        self::assertSame(10_800, (int) $row['compensation_minor']);
        self::assertSame('supported', $row['support_status']);
        self::assertSame('synthetic-sickness-v1', $row['ruleset_id']);
        self::assertSame(str_repeat('c', 64), $row['ruleset_hash']);
        self::assertSame($fixture['trace'], $row['calculation_trace']);
        self::assertSame($this->actors[1], (int) $row['calculated_by']);
    }

    public function testCompanyBackupStreamsSicknessSegmentWithRemappableShiftTrace(): void
    {
        $sickness = $this->sicknessEvent();
        $shift = $this->versionedPublishedShift();
        $trace = CanonicalJson::encode([
            'compensation_minor' => 10_800,
            'eligible_minutes' => 240,
            'hourly_average_minor' => 7_500,
            'local_date' => '2026-06-15',
            'planned_minutes' => 480,
            'reduced_hourly_minor' => 4_500,
            'rounding' => 'half-up-to-minor-unit',
            'shift_id' => $shift['published_id'],
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_sickness_compensation_segments
                (supplier_id, sickness_event_id, shift_id, local_date,
                 planned_minutes, eligible_minutes, hourly_average_minor,
                 reduced_hourly_minor, compensation_minor, trace)
             VALUES (?, ?, ?, "2026-06-15", 480, 240, 7500, 4500, 10800, ?)'
        )->execute([
            $this->supplierId,
            $sickness['event_id'],
            $shift['published_id'],
            $trace,
        ]);
        $segmentId = (int) $this->db->pdo()->lastInsertId();
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_sickness_compensation_segments',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->embeddedReferences->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($segmentId, (int) $row['id']);
        self::assertSame($sickness['event_id'], (int) $row['sickness_event_id']);
        self::assertSame($shift['published_id'], (int) $row['shift_id']);
        self::assertSame('2026-06-15', $row['local_date']);
        self::assertSame(480, (int) $row['planned_minutes']);
        self::assertSame(240, (int) $row['eligible_minutes']);
        self::assertSame(7_500, (int) $row['hourly_average_minor']);
        self::assertSame(4_500, (int) $row['reduced_hourly_minor']);
        self::assertSame(10_800, (int) $row['compensation_minor']);
        self::assertSame($trace, $row['trace']);

        $restored = $projection->remapEmbeddedReferences(
            $row,
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ): int => $reference->target === 'table:payroll_shifts'
                ? (int) $value + 1_000
                : throw new \LogicException(
                    'Test zachytil neočekávanou referenci.',
                ),
        );
        $restoredTrace = json_decode(
            (string) $restored['trace'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame($shift['published_id'] + 1_000, $restoredTrace['shift_id']);
    }

    public function testCompanyBackupStreamsSealedBusinessTrip(): void
    {
        $fixture = $this->approvedBusinessTrip();
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_business_trips');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($fixture['trip_id'], (int) $row['id']);
        self::assertSame($this->employeeId, (int) $row['employee_id']);
        self::assertSame($this->employmentId, (int) $row['employment_id']);
        self::assertSame('CZ', $row['country_code']);
        self::assertSame('Europe/Prague', $row['timezone_name']);
        self::assertSame('2026-06-18 05:30:00', $row['departure_at_utc']);
        self::assertSame('2026-06-18 16:00:00', $row['arrival_at_utc']);
        self::assertSame('public_transport', $row['transport_mode']);
        self::assertSame(14_900, (int) $row['meal_rate_band_1_minor']);
        self::assertSame(5_000, (int) $row['advance_minor']);
        self::assertSame('approved', $row['status']);
        self::assertSame(23_100, (int) $row['entitlement_total_minor']);
        self::assertSame(23_100, (int) $row['exempt_total_minor']);
        self::assertSame(0, (int) $row['taxable_total_minor']);
        self::assertSame('synthetic-travel-v1', $row['ruleset_id']);
        self::assertSame($fixture['calculation'], $row['calculation_json']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            (string) $row['calculation_hash'],
        );
        self::assertSame(
            hash('sha256', $fixture['calculation']),
            $row['calculation_hash'],
        );
        self::assertSame(2, (int) $row['row_version']);
        self::assertSame($this->actors[0], (int) $row['created_by']);
        self::assertSame($this->actors[1], (int) $row['approved_by']);
        self::assertSame('2026-06-19 09:00:00', $row['approved_at']);
    }

    public function testCompanyBackupStreamsBusinessTripExpenseItems(): void
    {
        $trip = $this->approvedBusinessTrip();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_business_trip_items
                (supplier_id, trip_id, item_kind, spent_on, description,
                 amount_minor, is_documented, document_reference, sort_order)
             VALUES (?, ?, "transport", "2026-06-18",
                     "Synthetic rail ticket", 12900, 1, "SYNTH-001", 0)'
        )->execute([$this->supplierId, $trip['trip_id']]);
        $transportId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_business_trip_items
                (supplier_id, trip_id, item_kind, spent_on, description,
                 amount_minor, is_documented, document_reference,
                 vehicle_kind, distance_m, consumption_ml_per_100km,
                 fuel_kind, documented_fuel_price_minor, sort_order)
             VALUES (?, ?, "private_vehicle", "2026-06-18",
                     "Synthetic private vehicle", NULL, 0, NULL,
                     "car", 250000, 6500, "petrol_95", 3890, 1)'
        )->execute([$this->supplierId, $trip['trip_id']]);
        $vehicleId = (int) $this->db->pdo()->lastInsertId();
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_business_trip_items',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        self::assertSame($transportId, (int) $rows[0]['id']);
        self::assertSame($trip['trip_id'], (int) $rows[0]['trip_id']);
        self::assertSame('transport', $rows[0]['item_kind']);
        self::assertSame(12_900, (int) $rows[0]['amount_minor']);
        self::assertSame(1, (int) $rows[0]['is_documented']);
        self::assertSame('SYNTH-001', $rows[0]['document_reference']);
        self::assertSame($vehicleId, (int) $rows[1]['id']);
        self::assertSame('private_vehicle', $rows[1]['item_kind']);
        self::assertNull($rows[1]['amount_minor']);
        self::assertSame('car', $rows[1]['vehicle_kind']);
        self::assertSame(250_000, (int) $rows[1]['distance_m']);
        self::assertSame(6_500, (int) $rows[1]['consumption_ml_per_100km']);
        self::assertSame('petrol_95', $rows[1]['fuel_kind']);
        self::assertSame(3_890, (int) $rows[1]['documented_fuel_price_minor']);
        self::assertSame(1, (int) $rows[1]['sort_order']);
    }

    public function testCompanyBackupStreamsBusinessTripFreeMeal(): void
    {
        $trip = $this->approvedBusinessTrip();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_business_trip_free_meals
                (supplier_id, trip_id, meal_date, meal_count)
             VALUES (?, ?, "2026-06-18", 1)'
        )->execute([$this->supplierId, $trip['trip_id']]);
        $mealId = (int) $this->db->pdo()->lastInsertId();
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_business_trip_free_meals',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($mealId, (int) $row['id']);
        self::assertSame($trip['trip_id'], (int) $row['trip_id']);
        self::assertSame('2026-06-18', $row['meal_date']);
        self::assertSame(1, (int) $row['meal_count']);
    }

    public function testCompanyBackupStreamsInputImportWithBinaryHash(): void
    {
        $content = 'synthetic payroll input import';
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_input_imports
                (supplier_id, period_start, source_kind, source_name,
                 content_hash, status, row_count, accepted_count,
                 rejected_count, duplicate_count, created_by, accepted_at)
             VALUES (?, "2026-06-01", "csv", "synthetic-payroll.csv", ?,
                     "accepted", 4, 2, 1, 1, ?, "2026-06-02 10:00:00")'
        )->execute([
            $this->supplierId,
            hash('sha256', $content, true),
            $this->actors[0],
        ]);
        $importId = (int) $this->db->pdo()->lastInsertId();
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_input_imports');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($importId, (int) $row['id']);
        self::assertSame('2026-06-01', $row['period_start']);
        self::assertSame('csv', $row['source_kind']);
        self::assertSame('synthetic-payroll.csv', $row['source_name']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            (string) $row['content_hash'],
        );
        self::assertSame(hash('sha256', $content), $row['content_hash']);
        self::assertSame('accepted', $row['status']);
        self::assertSame(4, (int) $row['row_count']);
        self::assertSame(2, (int) $row['accepted_count']);
        self::assertSame(1, (int) $row['rejected_count']);
        self::assertSame(1, (int) $row['duplicate_count']);
        self::assertSame($this->actors[0], (int) $row['created_by']);
        self::assertSame('2026-06-02 10:00:00', $row['accepted_at']);
    }

    public function testCompanyBackupStreamsApprovedInputWithResealableSnapshot(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_inputs');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->embeddedReferences->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($this->inputId, (int) $row['id']);
        self::assertSame($this->employeeId, (int) $row['employee_id']);
        self::assertSame($this->employmentId, (int) $row['employment_id']);
        self::assertSame('manual', $row['source_kind']);
        self::assertSame('approved', $row['status']);
        self::assertNull($row['external_id']);
        self::assertNull($row['import_id']);
        self::assertNull($row['recurring_component_id']);
        self::assertNull($row['source_snapshot_json']);
        self::assertNull($row['source_snapshot_hash']);
        self::assertArrayNotHasKey('external_dedupe_key', $row);
        self::assertSame($this->actors[0], (int) $row['approved_by']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            (string) $row['component_snapshot_hash'],
        );
        self::assertSame(
            hash('sha256', (string) $row['component_snapshot_json']),
            $row['component_snapshot_hash'],
        );
        $component = json_decode(
            (string) $row['component_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame((int) $row['component_id'], $component['component_id']);
        self::assertSame('BASE', $component['code']);

        $restored = $projection->remapEmbeddedReferences(
            $row,
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ): int => $reference->target === 'table:payroll_component_definitions'
                ? (int) $value + 1_000
                : throw new \LogicException(
                    'Test zachytil neočekávanou referenci.',
                ),
        );
        $restoredComponent = json_decode(
            (string) $restored['component_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            (int) $row['component_id'] + 1_000,
            $restoredComponent['component_id'],
        );
        self::assertSame(
            hash('sha256', (string) $restored['component_snapshot_json']),
            $restored['component_snapshot_hash'],
        );
    }

    public function testCompanyBackupStreamsTravelInputWithSynchronizedIdentityAndSnapshot(): void
    {
        $trip = $this->approvedBusinessTrip();
        $materializer = $this->container->get(BusinessTripMaterializer::class);
        self::assertInstanceOf(BusinessTripMaterializer::class, $materializer);
        $result = $materializer->materialize(
            $this->supplierId,
            $trip['trip_id'],
            $this->actors[0],
        );
        self::assertSame('materialized', $result['status']);
        self::assertSame(1, $result['created_count']);

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_inputs');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $projection->encodedReferences->assertRegistryTargets($registry);
        $projection->embeddedReferences->assertRegistryTargets($registry);

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        $travelRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['source_kind'] === 'travel',
        ));
        self::assertCount(1, $travelRows);
        $row = $travelRows[0];
        self::assertSame(
            'travel:' . $trip['trip_id'] . ':exempt',
            $row['external_id'],
        );
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            (string) $row['source_snapshot_hash'],
        );
        self::assertSame(
            hash('sha256', (string) $row['source_snapshot_json']),
            $row['source_snapshot_hash'],
        );
        $source = json_decode(
            (string) $row['source_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame($trip['trip_id'], $source['business_trip_id']);
        self::assertSame('exempt', $source['classification']);

        $restored = $projection->remapPayloadReferences(
            $row,
            static fn (
                CompanyBackupEncodedReference|CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ): int => $reference->target === 'table:payroll_business_trips'
                ? (int) $value + 1_000
                : throw new \LogicException(
                    'Test zachytil neočekávanou referenci.',
                ),
        );
        self::assertSame(
            'travel:' . ($trip['trip_id'] + 1_000) . ':exempt',
            $restored['external_id'],
        );
        $restoredSource = json_decode(
            (string) $restored['source_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            $trip['trip_id'] + 1_000,
            $restoredSource['business_trip_id'],
        );
        self::assertSame(
            hash('sha256', (string) $restored['source_snapshot_json']),
            $restored['source_snapshot_hash'],
        );
    }

    public function testCompanyBackupStreamsTravelCompensationLinkWithSynchronizedIdentity(): void
    {
        $trip = $this->approvedBusinessTrip();
        $materializer = $this->container->get(BusinessTripMaterializer::class);
        self::assertInstanceOf(BusinessTripMaterializer::class, $materializer);
        $result = $materializer->materialize(
            $this->supplierId,
            $trip['trip_id'],
            $this->actors[0],
        );
        self::assertSame('materialized', $result['status']);
        self::assertSame(1, $result['created_count']);
        $created = $result['created'];
        self::assertIsArray($created);
        self::assertCount(1, $created);
        self::assertIsArray($created[0]);
        $inputId = (int) ($created[0]['input_id'] ?? 0);
        self::assertGreaterThan(0, $inputId);

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_travel_compensation_links',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->encodedReferences->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($inputId, (int) $row['input_id']);
        self::assertSame($trip['trip_id'], (int) $row['trip_id']);
        self::assertSame('payroll_business_trip', $row['source_system']);
        self::assertSame(
            'trip:' . $trip['trip_id'] . ':exempt',
            $row['source_reference'],
        );
        self::assertSame('classified', $row['classification_status']);

        $restored = $projection->remapPayloadReferences(
            [
                ...$row,
                'input_id' => $inputId + 2_000,
                'trip_id' => $trip['trip_id'] + 1_000,
            ],
            static fn (
                CompanyBackupEncodedReference|CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ): int => $reference->target === 'table:payroll_business_trips'
                ? (int) $value + 1_000
                : throw new \LogicException(
                    'Test zachytil neočekávanou referenci.',
                ),
        );
        self::assertSame($inputId + 2_000, $restored['input_id']);
        self::assertSame($trip['trip_id'] + 1_000, $restored['trip_id']);
        self::assertSame(
            'trip:' . ($trip['trip_id'] + 1_000) . ':exempt',
            $restored['source_reference'],
        );
    }

    public function testCompanyBackupStreamsRecurringComponentDisabledForRestore(): void
    {
        $componentId = (int) $this->scalar(
            'SELECT component_id
               FROM payroll_inputs
              WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $this->inputId],
        );
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_recurring_components
                (supplier_id, employment_id, component_id, calculation_kind,
                 amount_minor, valid_from, allocation_rule,
                 maximum_amount_minor, note, is_active, created_by, updated_by)
             VALUES (?, ?, ?, "fixed_amount", 42000, "2026-01-01",
                     "working_days", 50000, "Synthetic recurring wage", 1,
                     ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $componentId,
            $this->actors[0],
            $this->actors[1],
        ]);
        $recurringId = (int) $this->db->pdo()->lastInsertId();
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_recurring_components',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($recurringId, (int) $row['id']);
        self::assertSame($this->employmentId, (int) $row['employment_id']);
        self::assertSame($componentId, (int) $row['component_id']);
        self::assertSame('fixed_amount', $row['calculation_kind']);
        self::assertSame(42_000, (int) $row['amount_minor']);
        self::assertSame('2026-01-01', $row['valid_from']);
        self::assertSame('working_days', $row['allocation_rule']);
        self::assertSame(50_000, (int) $row['maximum_amount_minor']);
        self::assertSame('Synthetic recurring wage', $row['note']);
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame($this->actors[0], (int) $row['created_by']);
        self::assertSame($this->actors[1], (int) $row['updated_by']);

        $restored = $projection->restoreOverrides->apply($row);
        self::assertSame(0, $restored['is_active']);
        self::assertSame(42_000, (int) $restored['amount_minor']);
    }

    public function testCompanyBackupStreamsVersionedDeductionAgreement(): void
    {
        $updated = $this->createVersionedDeductionAgreement();

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_deduction_agreements',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame((int) $updated['id'], (int) $row['id']);
        self::assertSame($this->supplierId, (int) $row['supplier_id']);
        self::assertSame($this->employeeId, (int) $row['employee_id']);
        self::assertSame(
            'SYNTHETIC-BACKUP-DEDUCTION',
            $row['agreement_reference'],
        );
        self::assertSame(
            'Syntetická procentní srážka po kontrole',
            $row['title'],
        );
        self::assertSame('contribution', $row['deduction_kind']);
        self::assertSame('active', $row['status']);
        self::assertSame(40, (int) $row['priority_no']);
        self::assertSame(3_750, (int) $row['requested_minor']);
        self::assertSame(1_250, (int) $row['basis_points']);
        self::assertSame(30_000, (int) $row['basis_amount_minor']);
        self::assertSame(20_000, (int) $row['total_limit_minor']);
        self::assertSame(0, (int) $row['withheld_total_minor']);
        self::assertSame('2026-01-01', $row['valid_from']);
        self::assertNull($row['valid_to']);
        self::assertSame('SYNTHETIC-RECIPIENT', $row['recipient_reference']);
        self::assertSame('Syntetická dohoda po kontrole', $row['note']);
        self::assertSame(2, (int) $row['row_version']);
        self::assertSame(2, (int) $row['version_no']);
        self::assertSame($this->actors[0], (int) $row['created_by']);
        self::assertSame($this->actors[1], (int) $row['updated_by']);
        self::assertNotSame('', (string) $row['created_at']);
        self::assertNotSame('', (string) $row['updated_at']);

        $restored = $projection->restoreOverrides->apply($row);
        self::assertSame('active', $restored['status']);
        self::assertSame(2, (int) $restored['version_no']);
    }

    public function testCompanyBackupStreamsImmutableDeductionAgreementVersions(): void
    {
        $agreement = $this->createVersionedDeductionAgreement();
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_deduction_agreement_versions',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $created = $rows[0];
        $updated = $rows[1];
        self::assertSame(
            (int) $agreement['versions'][0]['id'],
            (int) $created['id'],
        );
        self::assertSame($this->supplierId, (int) $created['supplier_id']);
        self::assertSame((int) $agreement['id'], (int) $created['agreement_id']);
        self::assertSame($this->employeeId, (int) $created['employee_id']);
        self::assertSame(1, (int) $created['version_no']);
        self::assertSame('created', $created['change_kind']);
        self::assertSame('Syntetická procentní srážka', $created['title']);
        self::assertSame('contribution', $created['deduction_kind']);
        self::assertSame('active', $created['status']);
        self::assertSame(40, (int) $created['priority_no']);
        self::assertSame(3_750, (int) $created['requested_minor']);
        self::assertSame(1_250, (int) $created['basis_points']);
        self::assertSame(30_000, (int) $created['basis_amount_minor']);
        self::assertSame(20_000, (int) $created['total_limit_minor']);
        self::assertSame(0, (int) $created['withheld_total_minor']);
        self::assertSame('2026-01-01', $created['valid_from']);
        self::assertNull($created['valid_to']);
        self::assertSame('SYNTHETIC-RECIPIENT', $created['recipient_reference']);
        self::assertSame('Syntetická dohoda pro zálohu', $created['note']);
        self::assertSame('2026-01-01', $created['effective_from']);
        self::assertNull($created['reason']);
        self::assertSame($this->actors[0], (int) $created['actor_user_id']);
        self::assertNotSame('', (string) $created['created_at']);

        self::assertSame(
            (int) $agreement['versions'][1]['id'],
            (int) $updated['id'],
        );
        self::assertSame((int) $agreement['id'], (int) $updated['agreement_id']);
        self::assertSame(2, (int) $updated['version_no']);
        self::assertSame('updated', $updated['change_kind']);
        self::assertSame(
            'Syntetická procentní srážka po kontrole',
            $updated['title'],
        );
        self::assertSame('active', $updated['status']);
        self::assertSame('Syntetická dohoda po kontrole', $updated['note']);
        self::assertSame('2026-02-01', $updated['effective_from']);
        self::assertSame('Syntetická kontrola dohody', $updated['reason']);
        self::assertSame($this->actors[1], (int) $updated['actor_user_id']);
        self::assertNotSame('', (string) $updated['created_at']);
    }

    public function testCompanyBackupStreamsAppendOnlyDeductionLedger(): void
    {
        $agreement = $this->createVersionedDeductionAgreement();
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET company_name = "Syntetický zaměstnavatel",
                    display_name = "Syntetický zaměstnavatel",
                    ic = "00000000"
              WHERE id = ?'
        )->execute([$this->supplierId]);
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'backup-deduction-ledger-lock',
            $this->actors[0],
        );
        $revisionId = (int) $locked->revision['id'];
        $agreementId = (int) $agreement['id'];
        $repository = $this->container->get(PayrollNetRepository::class);
        self::assertInstanceOf(PayrollNetRepository::class, $repository);
        $withholdingKey = 'payroll-run-deduction:v1:revision:' . $revisionId
            . ':agreement:' . $agreementId . ':withheld';
        $withholdingMetadata = [
            'current_target_minor' => 2_500,
            'delta_minor' => 2_500,
            'previous_target_minor' => 0,
            'source' => 'approved_payroll_revision',
        ];
        $withholdingId = $repository->appendLedgerMovement(
            $this->supplierId,
            $agreementId,
            $revisionId,
            $this->employeeId,
            'withheld',
            2_500,
            $withholdingKey,
            null,
            $withholdingMetadata,
            $this->actors[0],
        );
        $reversalKey = 'payroll-run-deduction:v1:revision:' . $revisionId
            . ':agreement:' . $agreementId . ':source:' . $withholdingId
            . ':reversed';
        $reversalMetadata = [
            'current_target_minor' => 2_000,
            'delta_minor' => -500,
            'previous_target_minor' => 2_500,
            'source' => 'approved_payroll_correction',
        ];
        $reversalId = $repository->appendLedgerMovement(
            $this->supplierId,
            $agreementId,
            $revisionId,
            $this->employeeId,
            'reversed',
            -500,
            $reversalKey,
            $withholdingId,
            $reversalMetadata,
            $this->actors[1],
        );
        self::assertSame(
            2_000,
            (int) $this->scalar(
                'SELECT withheld_total_minor
                   FROM payroll_deduction_agreements
                  WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, $agreementId],
            ),
        );

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_deduction_ledger');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $withholding = $rows[0];
        $reversal = $rows[1];
        self::assertSame($withholdingId, (int) $withholding['id']);
        self::assertSame($this->supplierId, (int) $withholding['supplier_id']);
        self::assertSame($agreementId, (int) $withholding['agreement_id']);
        self::assertSame($revisionId, (int) $withholding['revision_id']);
        self::assertSame($this->employeeId, (int) $withholding['employee_id']);
        self::assertSame('withheld', $withholding['event_kind']);
        self::assertSame(2_500, (int) $withholding['amount_minor']);
        self::assertSame(
            hash('sha256', $withholdingKey),
            $withholding['event_key_hash'],
        );
        self::assertNull($withholding['source_ledger_id']);
        self::assertSame(
            CanonicalJson::encode($withholdingMetadata),
            $withholding['metadata_json'],
        );
        self::assertSame($this->actors[0], (int) $withholding['actor_user_id']);
        self::assertNotSame('', (string) $withholding['created_at']);

        self::assertSame($reversalId, (int) $reversal['id']);
        self::assertSame($agreementId, (int) $reversal['agreement_id']);
        self::assertSame($revisionId, (int) $reversal['revision_id']);
        self::assertSame('reversed', $reversal['event_kind']);
        self::assertSame(-500, (int) $reversal['amount_minor']);
        self::assertSame(
            hash('sha256', $reversalKey),
            $reversal['event_key_hash'],
        );
        self::assertSame($withholdingId, (int) $reversal['source_ledger_id']);
        self::assertSame(
            CanonicalJson::encode($reversalMetadata),
            $reversal['metadata_json'],
        );
        self::assertSame($this->actors[1], (int) $reversal['actor_user_id']);
        self::assertNotSame('', (string) $reversal['created_at']);
    }

    public function testCompanyBackupStreamsPersonIdentityHistory(): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 title_prefix, title_suffix, birth_surname, birth_date,
                 birth_place, birth_country_code, citizenship_country_code,
                 sex, effective_from, effective_to, row_version, created_at,
                 updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'Ing. Syntetická Původní, Ph.D.',
            'Syntetická',
            'Původní',
            'Ing.',
            'Ph.D.',
            'Syntetická Rodná',
            '1990-04-05',
            'Testov',
            'CZ',
            'CZ',
            'female',
            '2026-01-01',
            '2026-05-31',
            4,
            '2026-01-02 08:30:00',
            '2026-05-31 18:30:00',
        ]);
        $previousId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'Syntetická Nová',
            'Syntetická',
            'Nová',
            null,
            null,
            null,
            '1990-04-05',
            null,
            null,
            'CZ',
            null,
            '2026-06-01',
            null,
            1,
            '2026-06-01 09:00:00',
            '2026-06-01 09:00:00',
        ]);
        $currentId = (int) $this->db->pdo()->lastInsertId();

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Synthetic Foreign Identity Person", "employee", 1)'
        )->execute([$this->otherSupplierId]);
        $otherEmployeeId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->otherSupplierId,
            $otherEmployeeId,
            'Cizí Syntetická Osoba',
            'Cizí',
            'Osoba',
            null,
            null,
            null,
            '1985-02-03',
            null,
            'SK',
            'SK',
            'unspecified',
            '2026-01-01',
            null,
            1,
            '2026-01-02 08:30:00',
            '2026-01-02 08:30:00',
        ]);

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_identity_history',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $previous = $rows[0];
        $current = $rows[1];
        self::assertSame($previousId, (int) $previous['id']);
        self::assertSame($this->supplierId, (int) $previous['supplier_id']);
        self::assertSame($this->employeeId, (int) $previous['employee_id']);
        self::assertSame('Ing. Syntetická Původní, Ph.D.', $previous['full_name']);
        self::assertSame('Syntetická', $previous['first_name']);
        self::assertSame('Původní', $previous['last_name']);
        self::assertSame('Ing.', $previous['title_prefix']);
        self::assertSame('Ph.D.', $previous['title_suffix']);
        self::assertSame('Syntetická Rodná', $previous['birth_surname']);
        self::assertSame('1990-04-05', $previous['birth_date']);
        self::assertSame('Testov', $previous['birth_place']);
        self::assertSame('CZ', $previous['birth_country_code']);
        self::assertSame('CZ', $previous['citizenship_country_code']);
        self::assertSame('female', $previous['sex']);
        self::assertSame('2026-01-01', $previous['effective_from']);
        self::assertSame('2026-05-31', $previous['effective_to']);
        self::assertSame(4, (int) $previous['row_version']);
        self::assertSame('2026-01-02 08:30:00', $previous['created_at']);
        self::assertSame('2026-05-31 18:30:00', $previous['updated_at']);

        self::assertSame($currentId, (int) $current['id']);
        self::assertSame('Syntetická Nová', $current['full_name']);
        self::assertSame('Syntetická', $current['first_name']);
        self::assertSame('Nová', $current['last_name']);
        self::assertNull($current['title_prefix']);
        self::assertNull($current['title_suffix']);
        self::assertNull($current['birth_surname']);
        self::assertSame('1990-04-05', $current['birth_date']);
        self::assertNull($current['birth_place']);
        self::assertNull($current['birth_country_code']);
        self::assertSame('CZ', $current['citizenship_country_code']);
        self::assertNull($current['sex']);
        self::assertSame('2026-06-01', $current['effective_from']);
        self::assertNull($current['effective_to']);
        self::assertSame(1, (int) $current['row_version']);
        self::assertSame('2026-06-01 09:00:00', $current['created_at']);
        self::assertSame('2026-06-01 09:00:00', $current['updated_at']);
    }

    public function testCompanyBackupStreamsTaxDeclarationHistory(): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from, effective_to,
                 evidence_reference, evidence_note, created_by, updated_by,
                 row_version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'signed',
            '2026-01-01',
            '2026-05-31',
            'document:synthetic-tax-declaration',
            'Syntetické podepsané prohlášení',
            $this->actors[0],
            $this->actors[1],
            3,
            '2026-01-02 08:00:00',
            '2026-05-31 16:30:00',
        ]);
        $signedId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'not-signed',
            '2026-06-01',
            null,
            'document:synthetic-tax-declaration-revocation',
            'Syntetické odvolání prohlášení',
            $this->actors[1],
            null,
            1,
            '2026-06-01 09:00:00',
            '2026-06-01 09:00:00',
        ]);
        $revokedId = (int) $this->db->pdo()->lastInsertId();

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Synthetic Foreign Payroll Person", "employee", 1)'
        )->execute([$this->otherSupplierId]);
        $otherEmployeeId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->otherSupplierId,
            $otherEmployeeId,
            'signed',
            '2026-01-01',
            null,
            'document:foreign-synthetic-tax-declaration',
            null,
            $this->actors[0],
            null,
            1,
            '2026-01-02 08:00:00',
            '2026-01-02 08:00:00',
        ]);

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_tax_declarations',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $signed = $rows[0];
        $revoked = $rows[1];
        self::assertSame($signedId, (int) $signed['id']);
        self::assertSame($this->supplierId, (int) $signed['supplier_id']);
        self::assertSame($this->employeeId, (int) $signed['employee_id']);
        self::assertSame('signed', $signed['status']);
        self::assertSame('2026-01-01', $signed['effective_from']);
        self::assertSame('2026-05-31', $signed['effective_to']);
        self::assertSame(
            'document:synthetic-tax-declaration',
            $signed['evidence_reference'],
        );
        self::assertSame(
            'Syntetické podepsané prohlášení',
            $signed['evidence_note'],
        );
        self::assertSame($this->actors[0], (int) $signed['created_by']);
        self::assertSame($this->actors[1], (int) $signed['updated_by']);
        self::assertSame(3, (int) $signed['row_version']);
        self::assertSame('2026-01-02 08:00:00', $signed['created_at']);
        self::assertSame('2026-05-31 16:30:00', $signed['updated_at']);

        self::assertSame($revokedId, (int) $revoked['id']);
        self::assertSame('not-signed', $revoked['status']);
        self::assertSame('2026-06-01', $revoked['effective_from']);
        self::assertNull($revoked['effective_to']);
        self::assertSame(
            'document:synthetic-tax-declaration-revocation',
            $revoked['evidence_reference'],
        );
        self::assertSame(
            'Syntetické odvolání prohlášení',
            $revoked['evidence_note'],
        );
        self::assertSame($this->actors[1], (int) $revoked['created_by']);
        self::assertNull($revoked['updated_by']);
        self::assertSame(1, (int) $revoked['row_version']);
        self::assertSame('2026-06-01 09:00:00', $revoked['created_at']);
        self::assertSame('2026-06-01 09:00:00', $revoked['updated_at']);
    }

    public function testCompanyBackupStreamsTaxResidenceHistory(): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_residences
                (supplier_id, employee_id, residence, country_code,
                 effective_from, effective_to, evidence_reference,
                 evidence_note, created_by, updated_by, row_version,
                 created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'czech-resident',
            'CZ',
            '2026-01-01',
            '2026-05-31',
            'document:synthetic-czech-tax-residence',
            'Syntetické české daňové rezidentství',
            $this->actors[0],
            $this->actors[1],
            4,
            '2026-01-03 08:00:00',
            '2026-05-31 17:00:00',
        ]);
        $czechId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'non-resident',
            'SK',
            '2026-06-01',
            null,
            'document:synthetic-slovak-tax-residence',
            'Syntetické slovenské daňové rezidentství',
            $this->actors[1],
            null,
            1,
            '2026-06-01 10:00:00',
            '2026-06-01 10:00:00',
        ]);
        $foreignId = (int) $this->db->pdo()->lastInsertId();

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Synthetic Foreign Tax Resident", "employee", 1)'
        )->execute([$this->otherSupplierId]);
        $otherEmployeeId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->otherSupplierId,
            $otherEmployeeId,
            'unverified',
            null,
            '2026-01-01',
            null,
            null,
            null,
            $this->actors[0],
            null,
            1,
            '2026-01-03 08:00:00',
            '2026-01-03 08:00:00',
        ]);

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_tax_residences',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $czech = $rows[0];
        $foreign = $rows[1];
        self::assertSame($czechId, (int) $czech['id']);
        self::assertSame($this->supplierId, (int) $czech['supplier_id']);
        self::assertSame($this->employeeId, (int) $czech['employee_id']);
        self::assertSame('czech-resident', $czech['residence']);
        self::assertSame('CZ', $czech['country_code']);
        self::assertSame('2026-01-01', $czech['effective_from']);
        self::assertSame('2026-05-31', $czech['effective_to']);
        self::assertSame(
            'document:synthetic-czech-tax-residence',
            $czech['evidence_reference'],
        );
        self::assertSame(
            'Syntetické české daňové rezidentství',
            $czech['evidence_note'],
        );
        self::assertSame($this->actors[0], (int) $czech['created_by']);
        self::assertSame($this->actors[1], (int) $czech['updated_by']);
        self::assertSame(4, (int) $czech['row_version']);
        self::assertSame('2026-01-03 08:00:00', $czech['created_at']);
        self::assertSame('2026-05-31 17:00:00', $czech['updated_at']);

        self::assertSame($foreignId, (int) $foreign['id']);
        self::assertSame('non-resident', $foreign['residence']);
        self::assertSame('SK', $foreign['country_code']);
        self::assertSame('2026-06-01', $foreign['effective_from']);
        self::assertNull($foreign['effective_to']);
        self::assertSame(
            'document:synthetic-slovak-tax-residence',
            $foreign['evidence_reference'],
        );
        self::assertSame(
            'Syntetické slovenské daňové rezidentství',
            $foreign['evidence_note'],
        );
        self::assertSame($this->actors[1], (int) $foreign['created_by']);
        self::assertNull($foreign['updated_by']);
        self::assertSame(1, (int) $foreign['row_version']);
        self::assertSame('2026-06-01 10:00:00', $foreign['created_at']);
        self::assertSame('2026-06-01 10:00:00', $foreign['updated_at']);
    }

    public function testCompanyBackupStreamsTaxCreditClaimHistory(): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_credit_claims
                (supplier_id, employee_id, credit_kind, evidence_status,
                 effective_from, effective_to, evidence_reference,
                 evidence_note, created_by, updated_by, row_version,
                 created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'disability-basic',
            'verified',
            '2026-01-01',
            '2026-05-31',
            'document:synthetic-disability-credit',
            'Synteticky ověřený nárok na slevu',
            $this->actors[0],
            $this->actors[1],
            5,
            '2026-01-04 08:00:00',
            '2026-05-31 17:30:00',
        ]);
        $verifiedId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'disability-basic',
            'unverified',
            '2026-06-01',
            null,
            null,
            'Synteticky neověřený navazující nárok',
            $this->actors[1],
            null,
            1,
            '2026-06-01 11:00:00',
            '2026-06-01 11:00:00',
        ]);
        $unverifiedId = (int) $this->db->pdo()->lastInsertId();

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Synthetic Foreign Tax Credit Person", "employee", 1)'
        )->execute([$this->otherSupplierId]);
        $otherEmployeeId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->otherSupplierId,
            $otherEmployeeId,
            'taxpayer',
            'verified',
            '2026-01-01',
            null,
            'document:foreign-synthetic-tax-credit',
            null,
            $this->actors[0],
            null,
            1,
            '2026-01-04 08:00:00',
            '2026-01-04 08:00:00',
        ]);

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_tax_credit_claims',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $verified = $rows[0];
        $unverified = $rows[1];
        self::assertSame($verifiedId, (int) $verified['id']);
        self::assertSame($this->supplierId, (int) $verified['supplier_id']);
        self::assertSame($this->employeeId, (int) $verified['employee_id']);
        self::assertSame('disability-basic', $verified['credit_kind']);
        self::assertSame('verified', $verified['evidence_status']);
        self::assertSame('2026-01-01', $verified['effective_from']);
        self::assertSame('2026-05-31', $verified['effective_to']);
        self::assertSame(
            'document:synthetic-disability-credit',
            $verified['evidence_reference'],
        );
        self::assertSame(
            'Synteticky ověřený nárok na slevu',
            $verified['evidence_note'],
        );
        self::assertSame($this->actors[0], (int) $verified['created_by']);
        self::assertSame($this->actors[1], (int) $verified['updated_by']);
        self::assertSame(5, (int) $verified['row_version']);
        self::assertSame('2026-01-04 08:00:00', $verified['created_at']);
        self::assertSame('2026-05-31 17:30:00', $verified['updated_at']);

        self::assertSame($unverifiedId, (int) $unverified['id']);
        self::assertSame('disability-basic', $unverified['credit_kind']);
        self::assertSame('unverified', $unverified['evidence_status']);
        self::assertSame('2026-06-01', $unverified['effective_from']);
        self::assertNull($unverified['effective_to']);
        self::assertNull($unverified['evidence_reference']);
        self::assertSame(
            'Synteticky neověřený navazující nárok',
            $unverified['evidence_note'],
        );
        self::assertSame($this->actors[1], (int) $unverified['created_by']);
        self::assertNull($unverified['updated_by']);
        self::assertSame(1, (int) $unverified['row_version']);
        self::assertSame('2026-06-01 11:00:00', $unverified['created_at']);
        self::assertSame('2026-06-01 11:00:00', $unverified['updated_at']);
    }

    public function testCompanyBackupStreamsSocialJurisdictionHistory(): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_social_jurisdictions
                (supplier_id, employee_id, jurisdiction,
                 foreign_country_code, jurisdiction_evidence_reference,
                 a1_status, a1_certificate_reference, a1_valid_until,
                 effective_from, effective_to, evidence_note, created_by,
                 updated_by, row_version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'czech_regime_verified',
            null,
            null,
            'not_applicable',
            null,
            null,
            '2026-01-01',
            '2026-05-31',
            'Syntetická česká sociální jurisdikce',
            $this->actors[0],
            $this->actors[1],
            6,
            '2026-01-05 08:00:00',
            '2026-05-31 18:00:00',
        ]);
        $czechId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'foreign_regime_verified',
            'SK',
            'document:synthetic-social-jurisdiction',
            'verified',
            'document:synthetic-a1-certificate',
            '2026-12-31',
            '2026-06-01',
            null,
            'Syntetická zahraniční sociální jurisdikce',
            $this->actors[1],
            null,
            1,
            '2026-06-01 12:00:00',
            '2026-06-01 12:00:00',
        ]);
        $foreignId = (int) $this->db->pdo()->lastInsertId();

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Synthetic Foreign Social Person", "employee", 1)'
        )->execute([$this->otherSupplierId]);
        $otherEmployeeId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->otherSupplierId,
            $otherEmployeeId,
            'unverified',
            null,
            null,
            'unverified',
            null,
            null,
            '2026-01-01',
            null,
            null,
            $this->actors[0],
            null,
            1,
            '2026-01-05 08:00:00',
            '2026-01-05 08:00:00',
        ]);

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_social_jurisdictions',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $czech = $rows[0];
        $foreign = $rows[1];
        self::assertSame($czechId, (int) $czech['id']);
        self::assertSame($this->supplierId, (int) $czech['supplier_id']);
        self::assertSame($this->employeeId, (int) $czech['employee_id']);
        self::assertSame('czech_regime_verified', $czech['jurisdiction']);
        self::assertNull($czech['foreign_country_code']);
        self::assertNull($czech['jurisdiction_evidence_reference']);
        self::assertSame('not_applicable', $czech['a1_status']);
        self::assertNull($czech['a1_certificate_reference']);
        self::assertNull($czech['a1_valid_until']);
        self::assertSame('2026-01-01', $czech['effective_from']);
        self::assertSame('2026-05-31', $czech['effective_to']);
        self::assertSame(
            'Syntetická česká sociální jurisdikce',
            $czech['evidence_note'],
        );
        self::assertSame($this->actors[0], (int) $czech['created_by']);
        self::assertSame($this->actors[1], (int) $czech['updated_by']);
        self::assertSame(6, (int) $czech['row_version']);
        self::assertSame('2026-01-05 08:00:00', $czech['created_at']);
        self::assertSame('2026-05-31 18:00:00', $czech['updated_at']);

        self::assertSame($foreignId, (int) $foreign['id']);
        self::assertSame('foreign_regime_verified', $foreign['jurisdiction']);
        self::assertSame('SK', $foreign['foreign_country_code']);
        self::assertSame(
            'document:synthetic-social-jurisdiction',
            $foreign['jurisdiction_evidence_reference'],
        );
        self::assertSame('verified', $foreign['a1_status']);
        self::assertSame(
            'document:synthetic-a1-certificate',
            $foreign['a1_certificate_reference'],
        );
        self::assertSame('2026-12-31', $foreign['a1_valid_until']);
        self::assertSame('2026-06-01', $foreign['effective_from']);
        self::assertNull($foreign['effective_to']);
        self::assertSame(
            'Syntetická zahraniční sociální jurisdikce',
            $foreign['evidence_note'],
        );
        self::assertSame($this->actors[1], (int) $foreign['created_by']);
        self::assertNull($foreign['updated_by']);
        self::assertSame(1, (int) $foreign['row_version']);
        self::assertSame('2026-06-01 12:00:00', $foreign['created_at']);
        self::assertSame('2026-06-01 12:00:00', $foreign['updated_at']);
    }

    public function testCompanyBackupStreamsSocialDiscountClaimHistory(): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_social_discount_claims
                (supplier_id, employee_id, status, effective_from,
                 effective_to, evidence_reference, evidence_note, created_by,
                 updated_by, row_version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'verified',
            '2026-01-01',
            '2026-05-31',
            'document:synthetic-working-pensioner-discount',
            'Synteticky ověřený nárok na slevu na pojistném',
            $this->actors[0],
            $this->actors[1],
            7,
            '2026-01-06 08:00:00',
            '2026-05-31 18:30:00',
        ]);
        $verifiedId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'not_claimed',
            '2026-06-01',
            null,
            null,
            'Syntetické ukončení uplatňování slevy',
            $this->actors[1],
            null,
            1,
            '2026-06-01 13:00:00',
            '2026-06-01 13:00:00',
        ]);
        $notClaimedId = (int) $this->db->pdo()->lastInsertId();

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Synthetic Foreign Discount Person", "employee", 1)'
        )->execute([$this->otherSupplierId]);
        $otherEmployeeId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->otherSupplierId,
            $otherEmployeeId,
            'unverified',
            '2026-01-01',
            null,
            null,
            null,
            $this->actors[0],
            null,
            1,
            '2026-01-06 08:00:00',
            '2026-01-06 08:00:00',
        ]);

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_social_discount_claims',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $verified = $rows[0];
        $notClaimed = $rows[1];
        self::assertSame($verifiedId, (int) $verified['id']);
        self::assertSame($this->supplierId, (int) $verified['supplier_id']);
        self::assertSame($this->employeeId, (int) $verified['employee_id']);
        self::assertSame('verified', $verified['status']);
        self::assertSame('2026-01-01', $verified['effective_from']);
        self::assertSame('2026-05-31', $verified['effective_to']);
        self::assertSame(
            'document:synthetic-working-pensioner-discount',
            $verified['evidence_reference'],
        );
        self::assertSame(
            'Synteticky ověřený nárok na slevu na pojistném',
            $verified['evidence_note'],
        );
        self::assertSame($this->actors[0], (int) $verified['created_by']);
        self::assertSame($this->actors[1], (int) $verified['updated_by']);
        self::assertSame(7, (int) $verified['row_version']);
        self::assertSame('2026-01-06 08:00:00', $verified['created_at']);
        self::assertSame('2026-05-31 18:30:00', $verified['updated_at']);

        self::assertSame($notClaimedId, (int) $notClaimed['id']);
        self::assertSame('not_claimed', $notClaimed['status']);
        self::assertSame('2026-06-01', $notClaimed['effective_from']);
        self::assertNull($notClaimed['effective_to']);
        self::assertNull($notClaimed['evidence_reference']);
        self::assertSame(
            'Syntetické ukončení uplatňování slevy',
            $notClaimed['evidence_note'],
        );
        self::assertSame($this->actors[1], (int) $notClaimed['created_by']);
        self::assertNull($notClaimed['updated_by']);
        self::assertSame(1, (int) $notClaimed['row_version']);
        self::assertSame('2026-06-01 13:00:00', $notClaimed['created_at']);
        self::assertSame('2026-06-01 13:00:00', $notClaimed['updated_at']);
    }

    public function testCompanyBackupStreamsHealthCoverageHistory(): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction,
                 foreign_country_code, jurisdiction_evidence_reference,
                 insurer_status, insurer_code, insurer_evidence_reference,
                 effective_from, effective_to, evidence_note, created_by,
                 updated_by, row_version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'czech_regime_verified',
            null,
            null,
            'verified',
            '111',
            'document:synthetic-health-insurer',
            '2026-01-01',
            '2026-05-31',
            'Syntetická česká zdravotní evidence',
            $this->actors[0],
            $this->actors[1],
            8,
            '2026-01-07 08:00:00',
            '2026-05-31 19:00:00',
        ]);
        $czechId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'foreign_regime_verified',
            'SK',
            'document:synthetic-health-jurisdiction',
            'not_applicable',
            null,
            null,
            '2026-06-01',
            null,
            'Syntetická zahraniční zdravotní evidence',
            $this->actors[1],
            null,
            1,
            '2026-06-01 14:00:00',
            '2026-06-01 14:00:00',
        ]);
        $foreignId = (int) $this->db->pdo()->lastInsertId();

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Synthetic Foreign Health Person", "employee", 1)'
        )->execute([$this->otherSupplierId]);
        $otherEmployeeId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->otherSupplierId,
            $otherEmployeeId,
            'unverified',
            null,
            null,
            'unverified',
            null,
            null,
            '2026-01-01',
            null,
            null,
            $this->actors[0],
            null,
            1,
            '2026-01-07 08:00:00',
            '2026-01-07 08:00:00',
        ]);

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_health_coverage_history',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $czech = $rows[0];
        $foreign = $rows[1];
        self::assertSame($czechId, (int) $czech['id']);
        self::assertSame($this->supplierId, (int) $czech['supplier_id']);
        self::assertSame($this->employeeId, (int) $czech['employee_id']);
        self::assertSame('czech_regime_verified', $czech['jurisdiction']);
        self::assertNull($czech['foreign_country_code']);
        self::assertNull($czech['jurisdiction_evidence_reference']);
        self::assertSame('verified', $czech['insurer_status']);
        self::assertSame('111', $czech['insurer_code']);
        self::assertSame(
            'document:synthetic-health-insurer',
            $czech['insurer_evidence_reference'],
        );
        self::assertSame('2026-01-01', $czech['effective_from']);
        self::assertSame('2026-05-31', $czech['effective_to']);
        self::assertSame(
            'Syntetická česká zdravotní evidence',
            $czech['evidence_note'],
        );
        self::assertSame($this->actors[0], (int) $czech['created_by']);
        self::assertSame($this->actors[1], (int) $czech['updated_by']);
        self::assertSame(8, (int) $czech['row_version']);
        self::assertSame('2026-01-07 08:00:00', $czech['created_at']);
        self::assertSame('2026-05-31 19:00:00', $czech['updated_at']);

        self::assertSame($foreignId, (int) $foreign['id']);
        self::assertSame('foreign_regime_verified', $foreign['jurisdiction']);
        self::assertSame('SK', $foreign['foreign_country_code']);
        self::assertSame(
            'document:synthetic-health-jurisdiction',
            $foreign['jurisdiction_evidence_reference'],
        );
        self::assertSame('not_applicable', $foreign['insurer_status']);
        self::assertNull($foreign['insurer_code']);
        self::assertNull($foreign['insurer_evidence_reference']);
        self::assertSame('2026-06-01', $foreign['effective_from']);
        self::assertNull($foreign['effective_to']);
        self::assertSame(
            'Syntetická zahraniční zdravotní evidence',
            $foreign['evidence_note'],
        );
        self::assertSame($this->actors[1], (int) $foreign['created_by']);
        self::assertNull($foreign['updated_by']);
        self::assertSame(1, (int) $foreign['row_version']);
        self::assertSame('2026-06-01 14:00:00', $foreign['created_at']);
        self::assertSame('2026-06-01 14:00:00', $foreign['updated_at']);
    }

    public function testCompanyBackupStreamsHealthMinimumReductionHistory(): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_health_minimum_reductions
                (supplier_id, employee_id, reason, evidence_reference,
                 effective_from, effective_to, evidence_note, created_by,
                 updated_by, row_version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'state_insured',
            'document:synthetic-state-insured',
            '2026-01-01',
            '2026-05-31',
            'Synteticky doložený státní pojištěnec',
            $this->actors[0],
            $this->actors[1],
            9,
            '2026-01-08 08:00:00',
            '2026-05-31 19:30:00',
        ]);
        $verifiedId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            'unverified',
            null,
            '2026-06-01',
            null,
            'Synteticky neověřený důvod snížení minima',
            $this->actors[1],
            null,
            1,
            '2026-06-01 15:00:00',
            '2026-06-01 15:00:00',
        ]);
        $unverifiedId = (int) $this->db->pdo()->lastInsertId();

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Synthetic Foreign Minimum Person", "employee", 1)'
        )->execute([$this->otherSupplierId]);
        $otherEmployeeId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->otherSupplierId,
            $otherEmployeeId,
            'ztp_or_ztp_p',
            'document:foreign-synthetic-minimum-reduction',
            '2026-01-01',
            null,
            null,
            $this->actors[0],
            null,
            1,
            '2026-01-08 08:00:00',
            '2026-01-08 08:00:00',
        ]);

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_health_minimum_reductions',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $verified = $rows[0];
        $unverified = $rows[1];
        self::assertSame($verifiedId, (int) $verified['id']);
        self::assertSame($this->supplierId, (int) $verified['supplier_id']);
        self::assertSame($this->employeeId, (int) $verified['employee_id']);
        self::assertSame('state_insured', $verified['reason']);
        self::assertSame(
            'document:synthetic-state-insured',
            $verified['evidence_reference'],
        );
        self::assertSame('2026-01-01', $verified['effective_from']);
        self::assertSame('2026-05-31', $verified['effective_to']);
        self::assertSame(
            'Synteticky doložený státní pojištěnec',
            $verified['evidence_note'],
        );
        self::assertSame($this->actors[0], (int) $verified['created_by']);
        self::assertSame($this->actors[1], (int) $verified['updated_by']);
        self::assertSame(9, (int) $verified['row_version']);
        self::assertSame('2026-01-08 08:00:00', $verified['created_at']);
        self::assertSame('2026-05-31 19:30:00', $verified['updated_at']);

        self::assertSame($unverifiedId, (int) $unverified['id']);
        self::assertSame('unverified', $unverified['reason']);
        self::assertNull($unverified['evidence_reference']);
        self::assertSame('2026-06-01', $unverified['effective_from']);
        self::assertNull($unverified['effective_to']);
        self::assertSame(
            'Synteticky neověřený důvod snížení minima',
            $unverified['evidence_note'],
        );
        self::assertSame($this->actors[1], (int) $unverified['created_by']);
        self::assertNull($unverified['updated_by']);
        self::assertSame(1, (int) $unverified['row_version']);
        self::assertSame('2026-06-01 15:00:00', $unverified['created_at']);
        self::assertSame('2026-06-01 15:00:00', $unverified['updated_at']);
    }

    public function testCompanyBackupStreamsOtherEmployerHealthBases(): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_health_other_employer_bases
                (supplier_id, employee_id, period_start, employer_reference,
                 assessment_base_minor_units, employment_from, employment_to,
                 evidence_reference, evidence_note, created_by, updated_by,
                 row_version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            '2026-06-01',
            'other-employer:synthetic-alpha',
            3_000_000,
            '2026-01-01',
            null,
            'document:synthetic-other-employer-alpha',
            'Syntetický základ u prvního zaměstnavatele',
            $this->actors[0],
            $this->actors[1],
            10,
            '2026-06-02 08:00:00',
            '2026-06-03 09:00:00',
        ]);
        $alphaId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->supplierId,
            $this->employeeId,
            '2026-06-01',
            'other-employer:synthetic-beta',
            1_500_000,
            '2026-03-01',
            '2026-05-31',
            null,
            'Syntetický základ u druhého zaměstnavatele',
            $this->actors[1],
            null,
            1,
            '2026-06-02 10:00:00',
            '2026-06-02 10:00:00',
        ]);
        $betaId = (int) $this->db->pdo()->lastInsertId();

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Synthetic Foreign Employer Base Person", "employee", 1)'
        )->execute([$this->otherSupplierId]);
        $otherEmployeeId = (int) $this->db->pdo()->lastInsertId();
        $insert->execute([
            $this->otherSupplierId,
            $otherEmployeeId,
            '2026-06-01',
            'other-employer:foreign-synthetic',
            9_000_000,
            '2026-01-01',
            null,
            null,
            null,
            $this->actors[0],
            null,
            1,
            '2026-06-02 08:00:00',
            '2026-06-02 08:00:00',
        ]);

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_health_other_employer_bases',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $alpha = $rows[0];
        $beta = $rows[1];
        self::assertSame($alphaId, (int) $alpha['id']);
        self::assertSame($this->supplierId, (int) $alpha['supplier_id']);
        self::assertSame($this->employeeId, (int) $alpha['employee_id']);
        self::assertSame('2026-06-01', $alpha['period_start']);
        self::assertSame(
            'other-employer:synthetic-alpha',
            $alpha['employer_reference'],
        );
        self::assertSame(3_000_000, (int) $alpha['assessment_base_minor_units']);
        self::assertSame('2026-01-01', $alpha['employment_from']);
        self::assertNull($alpha['employment_to']);
        self::assertSame(
            'document:synthetic-other-employer-alpha',
            $alpha['evidence_reference'],
        );
        self::assertSame(
            'Syntetický základ u prvního zaměstnavatele',
            $alpha['evidence_note'],
        );
        self::assertSame($this->actors[0], (int) $alpha['created_by']);
        self::assertSame($this->actors[1], (int) $alpha['updated_by']);
        self::assertSame(10, (int) $alpha['row_version']);
        self::assertSame('2026-06-02 08:00:00', $alpha['created_at']);
        self::assertSame('2026-06-03 09:00:00', $alpha['updated_at']);

        self::assertSame($betaId, (int) $beta['id']);
        self::assertSame('2026-06-01', $beta['period_start']);
        self::assertSame(
            'other-employer:synthetic-beta',
            $beta['employer_reference'],
        );
        self::assertSame(1_500_000, (int) $beta['assessment_base_minor_units']);
        self::assertSame('2026-03-01', $beta['employment_from']);
        self::assertSame('2026-05-31', $beta['employment_to']);
        self::assertNull($beta['evidence_reference']);
        self::assertSame(
            'Syntetický základ u druhého zaměstnavatele',
            $beta['evidence_note'],
        );
        self::assertSame($this->actors[1], (int) $beta['created_by']);
        self::assertNull($beta['updated_by']);
        self::assertSame(1, (int) $beta['row_version']);
        self::assertSame('2026-06-02 10:00:00', $beta['created_at']);
        self::assertSame('2026-06-02 10:00:00', $beta['updated_at']);
    }

    public function testCompanyBackupStreamsHealthMonthEvidence(): void
    {
        $baseInsert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_health_other_employer_bases
                (supplier_id, employee_id, period_start, employer_reference,
                 assessment_base_minor_units, employment_from, employment_to,
                 evidence_reference, evidence_note, created_by, updated_by,
                 row_version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $baseInsert->execute([
            $this->supplierId,
            $this->employeeId,
            '2026-06-01',
            'other-employer:synthetic-selected',
            2_500_000,
            '2026-01-01',
            null,
            'document:synthetic-selected-employer-base',
            null,
            $this->actors[0],
            null,
            1,
            '2026-06-02 07:30:00',
            '2026-06-02 07:30:00',
        ]);

        $evidenceInsert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_health_month_evidence
                (supplier_id, employee_id, period_start,
                 top_up_responsibility,
                 top_up_responsibility_evidence_reference,
                 selected_top_up_employer_reference,
                 selected_top_up_employer_evidence_reference, evidence_note,
                 created_by, updated_by, row_version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $evidenceInsert->execute([
            $this->supplierId,
            $this->employeeId,
            '2026-06-01',
            'employer_obstacle_verified',
            'document:synthetic-health-top-up-obstacle',
            'other-employer:synthetic-selected',
            'document:synthetic-selected-top-up-employer',
            'Syntetická měsíční evidence doplatku zdravotního minima',
            $this->actors[0],
            $this->actors[1],
            11,
            '2026-06-03 08:00:00',
            '2026-06-04 09:00:00',
        ]);
        $selectedId = (int) $this->db->pdo()->lastInsertId();
        $evidenceInsert->execute([
            $this->supplierId,
            $this->employeeId,
            '2026-07-01',
            'employee',
            null,
            null,
            null,
            null,
            $this->actors[1],
            null,
            1,
            '2026-07-02 10:00:00',
            '2026-07-02 10:00:00',
        ]);
        $employeeId = (int) $this->db->pdo()->lastInsertId();

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Synthetic Foreign Health Month Person", "employee", 1)'
        )->execute([$this->otherSupplierId]);
        $otherEmployeeId = (int) $this->db->pdo()->lastInsertId();
        $baseInsert->execute([
            $this->otherSupplierId,
            $otherEmployeeId,
            '2026-06-01',
            'other-employer:foreign-selected',
            8_500_000,
            '2026-01-01',
            null,
            null,
            null,
            $this->actors[0],
            null,
            1,
            '2026-06-02 07:30:00',
            '2026-06-02 07:30:00',
        ]);
        $evidenceInsert->execute([
            $this->otherSupplierId,
            $otherEmployeeId,
            '2026-06-01',
            'unverified',
            null,
            'other-employer:foreign-selected',
            null,
            null,
            $this->actors[0],
            null,
            1,
            '2026-06-03 08:00:00',
            '2026-06-03 08:00:00',
        ]);

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_health_month_evidence',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $selected = $rows[0];
        $employee = $rows[1];
        self::assertSame($selectedId, (int) $selected['id']);
        self::assertSame($this->supplierId, (int) $selected['supplier_id']);
        self::assertSame($this->employeeId, (int) $selected['employee_id']);
        self::assertSame('2026-06-01', $selected['period_start']);
        self::assertSame(
            'employer_obstacle_verified',
            $selected['top_up_responsibility'],
        );
        self::assertSame(
            'document:synthetic-health-top-up-obstacle',
            $selected['top_up_responsibility_evidence_reference'],
        );
        self::assertSame(
            'other-employer:synthetic-selected',
            $selected['selected_top_up_employer_reference'],
        );
        self::assertSame(
            'document:synthetic-selected-top-up-employer',
            $selected['selected_top_up_employer_evidence_reference'],
        );
        self::assertSame(
            'Syntetická měsíční evidence doplatku zdravotního minima',
            $selected['evidence_note'],
        );
        self::assertSame($this->actors[0], (int) $selected['created_by']);
        self::assertSame($this->actors[1], (int) $selected['updated_by']);
        self::assertSame(11, (int) $selected['row_version']);
        self::assertSame('2026-06-03 08:00:00', $selected['created_at']);
        self::assertSame('2026-06-04 09:00:00', $selected['updated_at']);

        self::assertSame($employeeId, (int) $employee['id']);
        self::assertSame($this->supplierId, (int) $employee['supplier_id']);
        self::assertSame($this->employeeId, (int) $employee['employee_id']);
        self::assertSame('2026-07-01', $employee['period_start']);
        self::assertSame('employee', $employee['top_up_responsibility']);
        self::assertNull($employee['top_up_responsibility_evidence_reference']);
        self::assertNull($employee['selected_top_up_employer_reference']);
        self::assertNull($employee['selected_top_up_employer_evidence_reference']);
        self::assertNull($employee['evidence_note']);
        self::assertSame($this->actors[1], (int) $employee['created_by']);
        self::assertNull($employee['updated_by']);
        self::assertSame(1, (int) $employee['row_version']);
        self::assertSame('2026-07-02 10:00:00', $employee['created_at']);
        self::assertSame('2026-07-02 10:00:00', $employee['updated_at']);
    }

    public function testCompanyBackupStreamsEffectiveEmploymentTerm(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET weekly_hours = 37.50,
                    leave_entitlement_weeks_override = 5,
                    created_by = ?
              WHERE supplier_id = ? AND employment_id = ?'
        )->execute([
            $this->actors[0],
            $this->supplierId,
            $this->employmentId,
        ]);
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_employment_terms');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        $termId = (int) $this->scalar(
            'SELECT id
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $this->employmentId],
        );
        self::assertSame($termId, (int) $row['id']);
        self::assertSame($this->employmentId, (int) $row['employment_id']);
        self::assertGreaterThan(0, (int) $row['office_id']);
        self::assertSame('2026-01-01', $row['effective_from']);
        self::assertNull($row['effective_to']);
        self::assertSame('37.50', $row['weekly_hours']);
        self::assertSame(5, (int) $row['leave_entitlement_weeks_override']);
        self::assertSame('automatic', $row['social_insurance_participation']);
        self::assertSame('ordinary', $row['social_employer_rate_category']);
        self::assertSame($this->actors[0], (int) $row['created_by']);
        self::assertSame(1, (int) $row['is_primary']);
    }

    public function testCompanyBackupStreamsEmploymentWithoutGeneratedOwnerKeys(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_employments');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($this->employmentId, (int) $row['id']);
        self::assertSame($this->employeeId, (int) $row['employee_id']);
        self::assertGreaterThan(0, (int) $row['office_id']);
        self::assertSame('SYN-MZ09', $row['code']);
        self::assertSame('active', $row['status']);
        self::assertSame(1, (int) $row['is_primary']);
        self::assertArrayNotHasKey('legacy_projection_key', $row);
        self::assertArrayNotHasKey('primary_employee_key', $row);
    }

    public function testCompanyBackupStreamsEmployeeWithSafeRestoreState(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employees
                SET monthly_gross = 75000,
                    auto_post = 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employeeId]);
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_employees');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($this->employeeId, (int) $row['id']);
        self::assertSame('Synthetic Payroll Run Person', $row['full_name']);
        self::assertArrayHasKey('birth_number', $row);
        self::assertSame(75_000, (int) $row['monthly_gross']);
        self::assertSame(1, (int) $row['auto_post']);

        $restored = $projection->restoreOverrides->apply($row);
        self::assertSame(0, $restored['auto_post']);
        self::assertSame(1, (int) $restored['is_active']);
        self::assertSame(75_000, (int) $restored['monthly_gross']);
    }

    public function testCompanyBackupStreamsEmployerPolicyInSafeRestoreState(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_employer_policies');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($this->employerPolicyId, (int) $row['id']);
        self::assertSame(1, (int) $row['automatic_calculation_enabled']);
        self::assertSame(1, (int) $row['automatic_posting_enabled']);
        self::assertSame(1, (int) $row['automatic_payments_enabled']);
        self::assertSame($this->actors[0], (int) $row['created_by']);
        self::assertSame($this->actors[0], (int) $row['updated_by']);

        $restored = $projection->restoreOverrides->apply($row);
        self::assertSame(0, $restored['automatic_calculation_enabled']);
        self::assertSame(0, $restored['automatic_posting_enabled']);
        self::assertSame(0, $restored['automatic_payments_enabled']);
        self::assertSame('disabled', $restored['delivery_channel']);
        self::assertNull($restored['delivery_verified_on']);
    }

    public function testCompanyBackupStreamsPayrollOffice(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_offices');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        self::assertSame($this->supplierId, (int) $rows[0]['supplier_id']);
        self::assertSame('MZ09', $rows[0]['code']);
        self::assertSame('Syntetická účtárna', $rows[0]['name']);
        self::assertNull($rows[0]['social_security_variable_symbol']);
    }

    public function testCompanyBackupStreamsEffectiveWorkCalendar(): void
    {
        $fixture = $this->effectiveWorkCalendar();
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_work_calendars');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($fixture['calendar_id'], (int) $row['id']);
        self::assertSame($this->employmentId, (int) $row['employment_id']);
        self::assertSame('Synthetic regular calendar', $row['name']);
        self::assertSame('Europe/Prague', $row['timezone_name']);
        self::assertSame('regular', $row['schedule_type']);
        self::assertSame($fixture['week_pattern'], $row['week_pattern']);
        self::assertSame(2_400, (int) $row['weekly_minutes']);
        self::assertSame('2026-01-01', $row['valid_from']);
        self::assertNull($row['valid_to']);
        self::assertSame($this->actors[0], (int) $row['created_by']);
    }

    public function testCompanyBackupStreamsCalendarDayException(): void
    {
        $calendar = $this->effectiveWorkCalendar();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_calendar_days
                (supplier_id, calendar_id, day_date, day_kind,
                 planned_minutes, holiday_code, holiday_name, note, created_by)
             VALUES (?, ?, "2026-07-05", "holiday", 0, "SYN-HOLIDAY",
                     "Synthetic public holiday", "Synthetic calendar exception", ?)'
        )->execute([
            $this->supplierId,
            $calendar['calendar_id'],
            $this->actors[1],
        ]);
        $dayId = (int) $this->db->pdo()->lastInsertId();
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_calendar_days');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($dayId, (int) $row['id']);
        self::assertSame($calendar['calendar_id'], (int) $row['calendar_id']);
        self::assertSame('2026-07-05', $row['day_date']);
        self::assertSame('holiday', $row['day_kind']);
        self::assertSame(0, (int) $row['planned_minutes']);
        self::assertSame('SYN-HOLIDAY', $row['holiday_code']);
        self::assertSame('Synthetic public holiday', $row['holiday_name']);
        self::assertSame('Synthetic calendar exception', $row['note']);
        self::assertSame($this->actors[1], (int) $row['created_by']);
    }

    public function testCompanyBackupStreamsVersionedPublishedShift(): void
    {
        $fixture = $this->versionedPublishedShift();
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_shifts');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $original = $rows[0];
        $published = $rows[1];
        self::assertSame($fixture['original_id'], (int) $original['id']);
        self::assertSame('superseded', $original['status']);
        self::assertNull($original['supersedes_id']);
        self::assertSame($fixture['published_id'], (int) $published['id']);
        self::assertSame($this->employmentId, (int) $published['employment_id']);
        self::assertSame($fixture['calendar_id'], (int) $published['calendar_id']);
        self::assertSame($fixture['series_key'], $published['series_key']);
        self::assertSame(2, (int) $published['revision_no']);
        self::assertSame($fixture['original_id'], (int) $published['supersedes_id']);
        self::assertSame('2026-06-15 06:00:00', $published['starts_at_utc']);
        self::assertSame('2026-06-15 14:30:00', $published['ends_at_utc']);
        self::assertSame('Europe/Prague', $published['timezone_name']);
        self::assertSame(30, (int) $published['break_minutes']);
        self::assertSame(1, (int) $published['remote_work']);
        self::assertSame(60, (int) $published['standby_minutes']);
        self::assertSame('published', $published['status']);
        self::assertSame($this->actors[0], (int) $published['created_by']);
        self::assertSame($this->actors[1], (int) $published['published_by']);
        self::assertSame('2026-06-01 08:00:00', $published['published_at']);
    }

    public function testCompanyBackupStreamsVersionedApprovedTimeEntry(): void
    {
        $seriesKey = str_repeat('e', 32);
        $originalHash = hash('sha256', 'synthetic-time-entry-original', true);
        $approvedHash = hash('sha256', 'synthetic-time-entry-approved', true);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_entries
                (supplier_id, employment_id, series_key, revision_no,
                 category, starts_at_utc, ends_at_utc, timezone_name,
                 break_minutes, source_kind, source_hash, status, created_by)
             VALUES (?, ?, ?, 1, "regular", "2026-06-16 06:00:00",
                     "2026-06-16 14:00:00", "Europe/Prague", 30,
                     "manual", ?, "superseded", ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $seriesKey,
            $originalHash,
            $this->actors[0],
        ]);
        $originalId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_entries
                (supplier_id, employment_id, series_key, revision_no,
                 supersedes_id, category, starts_at_utc, ends_at_utc,
                 timezone_name, break_minutes, source_kind, source_reference,
                 source_hash, status, created_by, approved_by, approved_at)
             VALUES (?, ?, ?, 2, ?, "overtime", "2026-06-16 06:00:00",
                     "2026-06-16 15:00:00", "Europe/Prague", 30, "import",
                     "synthetic-row-42", ?, "approved", ?, ?,
                     "2026-06-30 12:00:00")'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $seriesKey,
            $originalId,
            $approvedHash,
            $this->actors[0],
            $this->actors[1],
        ]);
        $approvedId = (int) $this->db->pdo()->lastInsertId();

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_time_entries');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $original = $rows[0];
        $approved = $rows[1];
        self::assertSame($originalId, (int) $original['id']);
        self::assertSame('superseded', $original['status']);
        self::assertSame(bin2hex($originalHash), $original['source_hash']);
        self::assertSame($approvedId, (int) $approved['id']);
        self::assertSame($this->employmentId, (int) $approved['employment_id']);
        self::assertSame($seriesKey, $approved['series_key']);
        self::assertSame(2, (int) $approved['revision_no']);
        self::assertSame($originalId, (int) $approved['supersedes_id']);
        self::assertSame('overtime', $approved['category']);
        self::assertSame('2026-06-16 06:00:00', $approved['starts_at_utc']);
        self::assertSame('2026-06-16 15:00:00', $approved['ends_at_utc']);
        self::assertSame('Europe/Prague', $approved['timezone_name']);
        self::assertSame(30, (int) $approved['break_minutes']);
        self::assertSame('import', $approved['source_kind']);
        self::assertSame('synthetic-row-42', $approved['source_reference']);
        self::assertSame(bin2hex($approvedHash), $approved['source_hash']);
        self::assertSame('approved', $approved['status']);
        self::assertSame($this->actors[0], (int) $approved['created_by']);
        self::assertSame($this->actors[1], (int) $approved['approved_by']);
        self::assertSame('2026-06-30 12:00:00', $approved['approved_at']);
    }

    public function testCompanyBackupStreamsApprovedAndReopenedTimeMonths(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, revision_no,
                 row_version, last_changed_by, approved_by, approved_at)
             VALUES (?, ?, "2026-06-01", "approved", 1, 4, ?, ?,
                     "2026-06-30 12:00:00")'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $this->actors[0],
            $this->actors[1],
        ]);
        $approvedId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, revision_no,
                 row_version, last_changed_by, reopened_by, reopened_at,
                 reopen_reason)
             VALUES (?, ?, "2026-07-01", "open", 2, 7, ?, ?,
                     "2026-08-01 09:00:00", "Synthetic correction")'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $this->actors[2],
            $this->actors[2],
        ]);
        $reopenedId = (int) $this->db->pdo()->lastInsertId();

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_time_months');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(2, $rows);
        $approved = $rows[0];
        $reopened = $rows[1];
        self::assertSame($approvedId, (int) $approved['id']);
        self::assertSame($this->employmentId, (int) $approved['employment_id']);
        self::assertSame('2026-06-01', $approved['period_start']);
        self::assertSame('approved', $approved['status']);
        self::assertSame(1, (int) $approved['revision_no']);
        self::assertSame(4, (int) $approved['row_version']);
        self::assertSame($this->actors[0], (int) $approved['last_changed_by']);
        self::assertSame($this->actors[1], (int) $approved['approved_by']);
        self::assertSame('2026-06-30 12:00:00', $approved['approved_at']);
        self::assertNull($approved['reopened_by']);
        self::assertSame($reopenedId, (int) $reopened['id']);
        self::assertSame('2026-07-01', $reopened['period_start']);
        self::assertSame('open', $reopened['status']);
        self::assertSame(2, (int) $reopened['revision_no']);
        self::assertSame(7, (int) $reopened['row_version']);
        self::assertNull($reopened['approved_by']);
        self::assertSame($this->actors[2], (int) $reopened['last_changed_by']);
        self::assertSame($this->actors[2], (int) $reopened['reopened_by']);
        self::assertSame('2026-08-01 09:00:00', $reopened['reopened_at']);
        self::assertSame('Synthetic correction', $reopened['reopen_reason']);
    }

    public function testCompanyBackupStreamsRunWithoutGeneratedOfficeScope(): void
    {
        $run = $this->createRun();
        self::assertArrayHasKey('id', $run);
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_runs');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame((int) $run['id'], (int) $row['id']);
        self::assertSame($this->supplierId, (int) $row['supplier_id']);
        self::assertNull($row['office_id']);
        self::assertArrayNotHasKey('office_scope_id', $row);
        self::assertSame('2026-07-15', $row['payment_date']);
        self::assertSame('draft', $row['status']);
        self::assertSame(0, (int) $row['current_revision_no']);
    }

    public function testCompanyBackupStreamsSealedRunRevisionWithBinaryKey(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET company_name = "Syntetický zaměstnavatel",
                    display_name = "Syntetický zaměstnavatel",
                    ic = "00000000"
              WHERE id = ?'
        )->execute([$this->supplierId]);
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'backup-live-lock',
            $this->actors[0],
        );
        $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'backup-live-calculate',
            $this->actors[0],
        );
        $lockedRevision = $locked->revision;
        self::assertIsArray($lockedRevision);
        self::assertArrayHasKey('id', $lockedRevision);

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_run_revisions');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->embeddedReferences->assertRegistryTargets($registry);
        $projection->embeddedHashReferences->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame((int) $lockedRevision['id'], (int) $row['id']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            (string) $row['idempotency_key_hash'],
        );
        self::assertSame(
            hash('sha256', (string) $row['input_snapshot_json']),
            $row['input_snapshot_hash'],
        );
        self::assertSame(
            hash('sha256', (string) $row['result_snapshot_json']),
            $row['result_snapshot_hash'],
        );
        $result = json_decode(
            (string) $row['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            $row['input_snapshot_hash'],
            $result['source_snapshot_hash'],
        );
    }

    public function testCompanyBackupStreamsSealedRunPerson(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET company_name = "Syntetický zaměstnavatel",
                    display_name = "Syntetický zaměstnavatel",
                    ic = "00000000"
              WHERE id = ?'
        )->execute([$this->supplierId]);
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'backup-live-person-lock',
            $this->actors[0],
        );
        $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'backup-live-person-calculate',
            $this->actors[0],
        );
        $revisionId = (int) $locked->revision['id'];

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_run_persons');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->embeddedReferences->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $this->db->pdo(),
            $this->supplierId,
            $definition,
        ));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($revisionId, (int) $row['revision_id']);
        self::assertSame($this->employeeId, (int) $row['employee_id']);
        self::assertSame('calculated', $row['status']);
        self::assertSame(
            hash('sha256', (string) $row['result_json']),
            $row['result_hash'],
        );
        $result = json_decode(
            (string) $row['result_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame($this->employeeId, $result['employee_id']);
        self::assertSame(
            $this->employmentId,
            $result['employments'][0]['employment_id'],
        );
        self::assertSame(
            $this->inputId,
            $result['employments'][0]['inputs'][0]['input_id'],
        );
    }

    public function testApprovalRollsBackWhenAutomaticPostingFails(): void
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-before-posting-failure',
            $this->actors[0],
        );
        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-before-posting-failure',
            $this->actors[0],
        );
        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-before-posting-failure',
            $this->actors[1],
        );

        $approvedPosting = $this->createMock(
            PayrollApprovedRevisionPostingService::class,
        );
        $approvedPosting->expects(self::once())
            ->method('post')
            ->willThrowException(new \RuntimeException(
                'Synthetic posting failure.',
            ));
        $service = new PayrollRunCommandService(
            $this->db,
            $this->runs,
            $this->container->get(PayrollRunSnapshotBuilder::class),
            new PayrollRunCalculationPipeline(
                $this->container->get(PayrollRunCalculator::class),
                $this->container->get(PayrollRunGarnishmentProcessor::class),
            ),
            $this->container->get(PayrollRunWorkflow::class),
            $this->container->get(PayrollPeriodOwnershipService::class),
            $approvedPosting,
        );

        try {
            $service->approve(
                $this->supplierId,
                (int) $run['id'],
                (int) $reviewed->run['row_version'],
                'approve-with-posting-failure',
                $this->actors[2],
            );
            self::fail('Selhání automatického zaúčtování musí zrušit schválení.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic posting failure.', $e->getMessage());
        }

        $persistedRun = $this->runs->find(
            $this->supplierId,
            (int) $run['id'],
        );
        $persistedRevision = $this->runs->revision(
            $this->supplierId,
            (int) $reviewed->revision['id'],
        );
        self::assertSame('reviewed', $persistedRun['status']);
        self::assertSame(
            (int) $reviewed->run['row_version'],
            (int) $persistedRun['row_version'],
        );
        self::assertSame('reviewed', $persistedRevision['status']);
        self::assertNull($persistedRevision['approved_by']);
        self::assertSame(
            0,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_commands
                  WHERE supplier_id = ? AND run_id = ?
                    AND command_name = "approve"',
                [$this->supplierId, $run['id']],
            ),
        );
        self::assertSame(
            0,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_events
                  WHERE supplier_id = ? AND run_id = ?
                    AND event_type = "approve"',
                [$this->supplierId, $run['id']],
            ),
        );
    }

    public function testApprovalWithPayslipGenerationRejectsOuterTransaction(): void
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-before-nested-approval',
            $this->actors[0],
        );
        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-before-nested-approval',
            $this->actors[0],
        );
        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-before-nested-approval',
            $this->actors[1],
        );

        $approvedPosting = $this->createMock(
            PayrollApprovedRevisionPostingService::class,
        );
        $approvedPosting->expects(self::never())->method('post');
        $approvedPayslips = $this->createMock(
            ApprovedRevisionPayslipBatchService::class,
        );
        $approvedPayslips->expects(self::never())->method('beginStorageScope');
        $approvedPayslips->expects(self::never())->method('generate');
        $approvedPayslips->expects(self::never())
            ->method('commitStorageScope');
        $approvedPayslips->expects(self::never())->method('cleanupStorageScope');
        $service = new PayrollRunCommandService(
            $this->db,
            $this->runs,
            $this->container->get(PayrollRunSnapshotBuilder::class),
            new PayrollRunCalculationPipeline(
                $this->container->get(PayrollRunCalculator::class),
                $this->container->get(PayrollRunGarnishmentProcessor::class),
            ),
            $this->container->get(PayrollRunWorkflow::class),
            $this->container->get(PayrollPeriodOwnershipService::class),
            $approvedPosting,
            $approvedPayslips,
        );

        try {
            $service->approve(
                $this->supplierId,
                (int) $run['id'],
                (int) $reviewed->run['row_version'],
                'approve-in-outer-transaction',
                $this->actors[2],
            );
            self::fail(
                'Generování výplatních pásek nesmí proběhnout v cizí transakci.',
            );
        } catch (\DomainException $e) {
            self::assertStringContainsString(
                'samostatné databázové transakci',
                $e->getMessage(),
            );
        }

        $persistedRun = $this->runs->find(
            $this->supplierId,
            (int) $run['id'],
        );
        $persistedRevision = $this->runs->revision(
            $this->supplierId,
            (int) $reviewed->revision['id'],
        );
        self::assertSame('reviewed', $persistedRun['status']);
        self::assertSame(
            (int) $reviewed->run['row_version'],
            (int) $persistedRun['row_version'],
        );
        self::assertSame('reviewed', $persistedRevision['status']);
        self::assertNull($persistedRevision['approved_by']);
    }

    public function testProductionPipelineBlocksApprovalUntilRulesetIsActive(): void
    {
        $approvedPosting = $this->createStub(
            PayrollApprovedRevisionPostingService::class,
        );
        $approvedPosting->method('post')->willReturn([]);
        $productionService = new PayrollRunCommandService(
            $this->db,
            $this->runs,
            $this->container->get(PayrollRunSnapshotBuilder::class),
            $this->container->get(PayrollRunCalculationPipeline::class),
            $this->container->get(PayrollRunWorkflow::class),
            $this->container->get(PayrollPeriodOwnershipService::class),
            $approvedPosting,
        );
        $run = $productionService->createRun(
            $this->supplierId,
            '2026-06-01',
            '2026-07-15',
            null,
            $this->actors[0],
        );
        $locked = $productionService->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'production-ruleset-lock',
            $this->actors[0],
        );
        $calculated = $productionService->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'production-ruleset-calculate',
            $this->actors[0],
        );

        self::assertSame(
            'manual_review',
            $calculated->revision['result_snapshot']['statutory']['status'],
        );
        self::assertContains(
            'statutory_calculation_manual_review',
            array_column(
                $this->runs->validations(
                    $this->supplierId,
                    (int) $calculated->revision['id'],
                ),
                'code',
            ),
        );

        $reviewed = $productionService->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'production-ruleset-review',
            $this->actors[1],
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('blokující validace');
        $productionService->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'production-ruleset-approve',
            $this->actors[2],
        );
    }

    public function testApprovedRunPersistsFrozenEnforcementAndReducesPayable(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_inputs SET amount_minor = 4000000
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->inputId]);
        $this->approvedInput(
            1_000_000,
            'CESTOVNI_NAHRADA',
            'manual',
            'excluded',
        );
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status,
                 effective_from, evidence_complete, recipient_verified,
                 created_by, updated_by)
             VALUES (?, ?, "synthetic-runtime-case", "enforcement",
                     "withhold_and_hold", "2026-05-01", 1, 1, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->actors[0],
            $this->actors[0],
        ]);
        $caseId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_claims
                (supplier_id, case_id, claim_key, enforcement_order_key,
                 legal_basis, category, outstanding_minor_units,
                 priority_date, order_issued_on, legal_title_verified,
                 order_or_notice_delivered, priority_classification_verified,
                 agreement_verified, due_monetary_claim_verified)
             VALUES (?, ?, "synthetic-runtime-claim", "synthetic-runtime-order",
                     "statutory", "non_priority", 10000000,
                     "2026-05-01", "2026-04-30", 1, 1, 1, 0, 1)'
        )->execute([$this->supplierId, $caseId]);

        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'runtime-enforcement-lock',
            $this->actors[0],
        );
        try {
            $this->db->pdo()->prepare(
                'UPDATE payroll_enforcement_claims
                    SET outstanding_minor_units = 0
                  WHERE supplier_id = ? AND case_id = ?'
            )->execute([$this->supplierId, $caseId]);
            self::fail(
                'Pohledávku použitou ve zmrazeném vstupu nesmí jít změnit.',
            );
        } catch (PDOException $exception) {
            self::assertStringContainsString(
                'retained footprint',
                $exception->getMessage(),
            );
        }
        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'runtime-enforcement-calculate',
            $this->actors[0],
        );
        $person = $calculated->revision['result_snapshot']['people'][0];
        $enforcement = $person['enforcement']['result'];
        $enforcementInput = $person['enforcement']['input'];
        self::assertSame('supported', $enforcement['status']);
        self::assertSame(
            4_000_000,
            $enforcementInput['income']['garnishable_minor_units'],
        );
        self::assertSame(
            1_000_000,
            $enforcementInput['income']['excluded_minor_units'],
        );
        self::assertGreaterThan(0, $enforcement['total_withheld_minor_units']);
        self::assertSame(
            5_000_000 - $enforcement['total_withheld_minor_units'],
            $person['payable_after_enforcement_minor'],
        );
        self::assertSame(
            0,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_enforcement_month_results
                  WHERE supplier_id = ? AND revision_id = ?',
                [$this->supplierId, $calculated->revision['id']],
            ),
        );

        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'runtime-enforcement-review',
            $this->actors[1],
        );
        $approved = $this->service->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'runtime-enforcement-approve',
            $this->actors[2],
        );
        self::assertSame('approved', $approved->run['status']);
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_enforcement_month_results
                  WHERE supplier_id = ? AND revision_id = ?',
                [$this->supplierId, $approved->revision['id']],
            ),
        );
        self::assertSame(
            $enforcement['total_withheld_minor_units'],
            (int) $this->scalar(
                'SELECT COALESCE(SUM(amount_minor_units), 0)
                   FROM payroll_enforcement_ledger
                  WHERE supplier_id = ?
                    AND entry_kind IN ("withheld", "employer_fee")',
                [$this->supplierId],
            ),
        );

        $replay = $this->service->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'runtime-enforcement-approve',
            $this->actors[2],
        );
        self::assertTrue($replay->idempotentReplay);
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_enforcement_month_results
                  WHERE supplier_id = ? AND revision_id = ?',
                [$this->supplierId, $approved->revision['id']],
            ),
        );
    }

    public function testIdempotentReplayTenantIsolationAndOptimisticConflict(): void
    {
        $run = $this->createRun();
        $sameRun = $this->createRun();
        self::assertSame($run['id'], $sameRun['id']);
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_events
                  WHERE supplier_id = ? AND run_id = ? AND event_type = "created"',
                [$this->supplierId, $run['id']],
            ),
        );
        $first = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'same-command-retry',
            $this->actors[0],
        );
        $replay = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'same-command-retry',
            $this->actors[0],
        );

        self::assertFalse($first->idempotentReplay);
        self::assertTrue($replay->idempotentReplay);
        self::assertSame($first->revision['id'], $replay->revision['id']);
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_revisions
                  WHERE supplier_id = ? AND run_id = ?',
                [$this->supplierId, $run['id']],
            ),
        );
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_commands
                  WHERE supplier_id = ? AND run_id = ?',
                [$this->supplierId, $run['id']],
            ),
        );
        self::assertNull($this->runs->find($this->otherSupplierId, (int) $run['id']));

        try {
            $this->service->calculate(
                $this->otherSupplierId,
                (int) $run['id'],
                (int) $first->run['row_version'],
                'foreign-tenant-command',
                $this->actors[0],
            );
            self::fail('Cizí tenant nesmí ovládat běh.');
        } catch (\OutOfBoundsException) {
            self::addToAssertionCount(1);
        }

        try {
            $this->service->calculate(
                $this->supplierId,
                (int) $run['id'],
                (int) $run['row_version'],
                'stale-row-version',
                $this->actors[0],
            );
            self::fail('Stará row_version musí skončit konfliktem.');
        } catch (PayrollRunConflictException $e) {
            self::assertSame((int) $first->run['row_version'], $e->currentVersion);
        }

        $this->expectException(PayrollRunIdempotencyException::class);
        $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $first->run['row_version'],
            'same-command-retry',
            $this->actors[0],
        );
    }

    public function testCommandsStopWhenPayrollModuleIsDisabled(): void
    {
        $run = $this->createRun();
        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?'
        )->execute([$this->supplierId]);

        try {
            $this->service->lockInputs(
                $this->supplierId,
                (int) $run['id'],
                (int) $run['row_version'],
                'disabled-module-command',
                $this->actors[0],
            );
            self::fail('Vypnutý mzdový modul nesmí přijímat stavové příkazy.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('vedení mezd zapnuté', $e->getMessage());
        }

        self::assertSame(
            'draft',
            $this->runs->find($this->supplierId, (int) $run['id'])['status'],
        );
    }

    public function testSuccessfulCommandCanReplayAfterModuleIsDisabled(): void
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'replay-after-module-disabled',
            $this->actors[0],
        );
        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?'
        )->execute([$this->supplierId]);

        $replayed = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'replay-after-module-disabled',
            $this->actors[0],
        );

        self::assertTrue($replayed->idempotentReplay);
        self::assertSame($locked->revision['id'], $replayed->revision['id']);
    }

    public function testCorrectionCreatesNewRevisionAndPreservesApprovedHistory(): void
    {
        $approved = $this->approveInitialRun();
        $runId = (int) $approved->run['id'];
        $originalRevisionId = (int) $approved->revision['id'];
        $originalHash = (string) $approved->revision['result_snapshot_hash'];

        $this->approvedInput(10_000, 'CORRECTION', 'correction');
        $requested = $this->service->requestCorrection(
            $this->supplierId,
            $runId,
            (int) $approved->run['row_version'],
            'request-correction',
            $this->actors[2],
            'Doplatek syntetické prémie.',
        );
        $reopened = $this->service->reopen(
            $this->supplierId,
            $runId,
            (int) $requested->run['row_version'],
            'reopen-correction',
            $this->actors[1],
            'Doplatek syntetické prémie.',
        );

        self::assertSame(2, $reopened->revision['revision_no']);
        self::assertSame('correction', $reopened->revision['revision_kind']);
        self::assertSame(
            $originalRevisionId,
            $reopened->revision['previous_revision_id'],
        );
        $revisions = $this->runs->revisions($this->supplierId, $runId);
        self::assertCount(2, $revisions);
        self::assertSame('approved', $revisions[0]['status']);
        self::assertSame($originalHash, $revisions[0]['result_snapshot_hash']);

        $calculated = $this->service->calculate(
            $this->supplierId,
            $runId,
            (int) $reopened->run['row_version'],
            'calculate-correction',
            $this->actors[0],
        );
        self::assertSame(
            130_000,
            $calculated->revision['result_snapshot']['totals']['source_amount_minor'],
        );
        $events = $this->runs->events($this->supplierId, $runId);
        $correctionEvent = array_values(array_filter(
            $events,
            static fn (array $event): bool =>
                $event['event_type'] === 'request_correction',
        ))[0];
        self::assertSame('Doplatek syntetické prémie.', $correctionEvent['reason']);
    }

    public function testCancelledUnapprovedRunReopensFromCurrentInputsAsRegularRevision(): void
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-before-cancelled-reopen',
            $this->actors[0],
        );
        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-before-cancelled-reopen',
            $this->actors[0],
        );
        $cancelled = $this->service->cancel(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'cancel-before-fresh-reopen',
            $this->actors[0],
            'Po uzamčení byly opraveny mzdové vstupy.',
        );
        $reopened = $this->service->reopen(
            $this->supplierId,
            (int) $run['id'],
            (int) $cancelled->run['row_version'],
            'fresh-reopen-after-cancel',
            $this->actors[1],
            'Zakládám nový snapshot z opravených vstupů.',
        );

        self::assertSame('reopened', $reopened->run['status']);
        self::assertSame(2, $reopened->revision['revision_no']);
        self::assertSame('regular', $reopened->revision['revision_kind']);

        $recalculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $reopened->run['row_version'],
            'calculate-fresh-reopen',
            $this->actors[0],
        );
        self::assertSame(
            120_000,
            $recalculated->revision['result_snapshot']['totals']['source_amount_minor'],
        );
    }

    public function testCancelledCorrectionReopensAgainstApprovedBaselineAsCorrection(): void
    {
        $approved = $this->approveInitialRun();
        $runId = (int) $approved->run['id'];
        $approvedRevisionId = (int) $approved->revision['id'];
        $this->approvedInput(10_000, 'CORRECTION_RETRY', 'correction');

        $requested = $this->service->requestCorrection(
            $this->supplierId,
            $runId,
            (int) $approved->run['row_version'],
            'request-correction-before-cancel',
            $this->actors[2],
            'Oprava podkladů.',
        );
        $firstAttempt = $this->service->reopen(
            $this->supplierId,
            $runId,
            (int) $requested->run['row_version'],
            'first-correction-before-cancel',
            $this->actors[1],
            'První pokus opravy.',
        );
        $calculated = $this->service->calculate(
            $this->supplierId,
            $runId,
            (int) $firstAttempt->run['row_version'],
            'calculate-correction-before-cancel',
            $this->actors[0],
        );
        $cancelled = $this->service->cancel(
            $this->supplierId,
            $runId,
            (int) $calculated->run['row_version'],
            'cancel-correction-attempt',
            $this->actors[0],
            'Podklady korekce je nutné znovu upravit.',
        );

        $retried = $this->service->reopen(
            $this->supplierId,
            $runId,
            (int) $cancelled->run['row_version'],
            'retry-correction-after-cancel',
            $this->actors[1],
            'Opakovaný pokus opravy.',
        );

        self::assertSame('correction', $retried->revision['revision_kind']);
        self::assertSame($approvedRevisionId, $retried->revision['previous_revision_id']);
        self::assertSame(3, $retried->revision['revision_no']);
    }

    public function testSnapshotValidationBlocksApprovalWithoutChangingReviewedRun(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_inputs SET status = "draft",
                    component_snapshot_json = NULL,
                    component_snapshot_hash = NULL,
                    approved_by = NULL,
                    approved_at = NULL
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->inputId]);
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-with-blocker',
            $this->actors[0],
        );
        $validations = $this->runs->validations(
            $this->supplierId,
            (int) $locked->revision['id'],
        );
        self::assertContains('draft_inputs_present', array_column($validations, 'code'));
        self::assertContains('employment_without_inputs', array_column($validations, 'code'));

        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-with-blocker',
            $this->actors[0],
        );
        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-with-blocker',
            $this->actors[1],
        );
        try {
            $this->service->approve(
                $this->supplierId,
                (int) $run['id'],
                (int) $reviewed->run['row_version'],
                'approve-with-blocker',
                $this->actors[2],
            );
            self::fail('Blokující validace nesmí dovolit schválení.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('blokující validace', $e->getMessage());
        }
        self::assertSame(
            'reviewed',
            $this->runs->find($this->supplierId, (int) $run['id'])['status'],
        );
        self::assertSame(
            0,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_commands
                  WHERE supplier_id = ? AND run_id = ? AND command_name = "approve"',
                [$this->supplierId, $run['id']],
            ),
        );
    }

    public function testAuditEventsAreAppendOnlyAtDatabaseBoundary(): void
    {
        $run = $this->createRun();
        $eventId = (int) $this->scalar(
            'SELECT id FROM payroll_run_events
              WHERE supplier_id = ? AND run_id = ? AND event_type = "created"',
            [$this->supplierId, $run['id']],
        );
        self::assertGreaterThan(0, $eventId);

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_run_events SET reason = "tamper"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $eventId]);
    }

    /** @return array{trip_id:int,calculation:string} */
    private function approvedBusinessTrip(): array
    {
        $calculation = CanonicalJson::encode([
            'advance_minor' => 5_000,
            'blockers' => [],
            'entitlement_total_minor' => 23_100,
            'exempt_total_minor' => 23_100,
            'items' => [],
            'meal_days' => [[
                'date' => '2026-06-18',
                'entitlement_minor' => 23_100,
                'exempt_minor' => 23_100,
                'free_meals' => 1,
                'minutes' => 630,
            ]],
            'ruleset_ids' => ['synthetic-travel-v1'],
            'settlement_difference_minor' => 18_100,
            'status' => 'supported',
            'steps' => [],
            'taxable_total_minor' => 0,
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_business_trips
                (supplier_id, employee_id, employment_id, country_code,
                 timezone_name, departure_at_utc, arrival_at_utc,
                 origin_place, destination_place, purpose, transport_mode,
                 meal_rate_band_1_minor, meal_rate_band_2_minor,
                 meal_rate_band_3_minor, advance_minor,
                 settlement_period_start, status, entitlement_total_minor,
                 exempt_total_minor, taxable_total_minor, ruleset_id,
                 calculation_json, calculation_hash, row_version,
                 created_by, approved_by, approved_at)
             VALUES (?, ?, ?, "CZ", "Europe/Prague",
                     "2026-06-18 05:30:00", "2026-06-18 16:00:00",
                     "Praha", "Brno", "Syntetické jednání",
                     "public_transport", 14900, 22500, 35300, 5000,
                     "2026-06-01", "approved", 23100, 23100, 0,
                     "synthetic-travel-v1", ?, ?, 2, ?, ?,
                     "2026-06-19 09:00:00")'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $calculation,
            hash('sha256', $calculation, true),
            $this->actors[0],
            $this->actors[1],
        ]);

        return [
            'trip_id' => (int) $this->db->pdo()->lastInsertId(),
            'calculation' => $calculation,
        ];
    }

    /** @return array{calendar_id:int,week_pattern:string} */
    private function effectiveWorkCalendar(): array
    {
        $weekPattern = json_encode([
            1 => 480,
            2 => 480,
            3 => 480,
            4 => 480,
            5 => 480,
            6 => 0,
            7 => 0,
        ], JSON_THROW_ON_ERROR);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_work_calendars
                (supplier_id, employment_id, name, timezone_name,
                 schedule_type, week_pattern, weekly_minutes, valid_from,
                 created_by)
             VALUES (?, ?, "Synthetic regular calendar", "Europe/Prague",
                     "regular", ?, 2400, "2026-01-01", ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $weekPattern,
            $this->actors[0],
        ]);

        return [
            'calendar_id' => (int) $this->db->pdo()->lastInsertId(),
            'week_pattern' => $weekPattern,
        ];
    }

    /**
     * @return array{
     *   calendar_id:int,
     *   original_id:int,
     *   published_id:int,
     *   series_key:string
     * }
     */
    private function versionedPublishedShift(): array
    {
        $calendar = $this->effectiveWorkCalendar();
        $seriesKey = str_repeat('d', 32);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_shifts
                (supplier_id, employment_id, calendar_id, series_key,
                 revision_no, starts_at_utc, ends_at_utc, timezone_name,
                 break_minutes, remote_work, standby_minutes, status,
                 created_by, published_by, published_at)
             VALUES (?, ?, ?, ?, 1, "2026-06-15 06:00:00",
                     "2026-06-15 14:00:00", "Europe/Prague", 30, 0, 0,
                     "superseded", ?, ?, "2026-05-31 08:00:00")'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $calendar['calendar_id'],
            $seriesKey,
            $this->actors[0],
            $this->actors[1],
        ]);
        $originalId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_shifts
                (supplier_id, employment_id, calendar_id, series_key,
                 revision_no, supersedes_id, starts_at_utc, ends_at_utc,
                 timezone_name, break_minutes, remote_work, standby_minutes,
                 status, created_by, published_by, published_at)
             VALUES (?, ?, ?, ?, 2, ?, "2026-06-15 06:00:00",
                     "2026-06-15 14:30:00", "Europe/Prague", 30, 1, 60,
                     "published", ?, ?, "2026-06-01 08:00:00")'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $calendar['calendar_id'],
            $seriesKey,
            $originalId,
            $this->actors[0],
            $this->actors[1],
        ]);

        return [
            'calendar_id' => $calendar['calendar_id'],
            'original_id' => $originalId,
            'published_id' => (int) $this->db->pdo()->lastInsertId(),
            'series_key' => $seriesKey,
        ];
    }

    /** @return array{average_id:int,absence_id:int} */
    private function approvedAbsenceWithAverage(
        string $absenceType = 'vacation',
    ): array {
        $compensationPolicy = $absenceType === 'dpn' ? 'dpn' : 'average_100';
        $compensationRate = $absenceType === 'dpn' ? 6_000 : 10_000;
        $averageInput = CanonicalJson::encode([
            'allocated_minor' => 0,
            'decisive_from' => '2026-01-01',
            'decisive_to' => '2026-03-31',
            'gross_minor' => 1_200_000,
            'rationale' => null,
            'worked_days' => 60,
            'worked_minutes' => 9_600,
        ]);
        $averageTrace = CanonicalJson::encode([
            'average_hourly_minor' => 7_500,
            'rule' => 'gross-earnings-divided-by-worked-time',
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_average_earning_snapshots
                (supplier_id, employment_id, applicable_year,
                 applicable_quarter, revision_no, source_kind,
                 decisive_from, decisive_to, gross_earnings_minor,
                 longer_period_allocated_minor, worked_minutes, worked_days,
                 average_hourly_minor, support_status, status, ruleset_id,
                 ruleset_hash, input_hash, input_trace, created_by,
                 approved_by, approved_at)
             VALUES (?, ?, 2026, 2, 1, "actual", "2026-01-01",
                     "2026-03-31", 1200000, 0, 9600, 60, 7500,
                     "supported", "approved", "synthetic-average-v1", ?, ?,
                     ?, ?, ?, "2026-04-02 10:00:00")'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            str_repeat('b', 64),
            hash('sha256', $averageInput, true),
            $averageTrace,
            $this->actors[0],
            $this->actors[1],
        ]);
        $averageId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_absences
                (supplier_id, employment_id, absence_type, date_from, date_to,
                 timezone_name, partial_first_minutes, partial_last_minutes,
                 note, compensation_policy, compensation_rate_basis_points,
                 average_snapshot_id, support_status, status, requested_by,
                 decided_by, decided_at)
             VALUES (?, ?, ?, "2026-06-15", "2026-06-16",
                     "Europe/Prague", 240, 180, "Synthetic approved leave",
                     ?, ?, ?, "supported", "approved", ?, ?,
                     "2026-06-01 09:00:00")'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $absenceType,
            $compensationPolicy,
            $compensationRate,
            $averageId,
            $this->actors[0],
            $this->actors[1],
        ]);

        return [
            'average_id' => $averageId,
            'absence_id' => (int) $this->db->pdo()->lastInsertId(),
        ];
    }

    /** @return array{average_id:int,absence_id:int,event_id:int,trace:string} */
    private function sicknessEvent(): array
    {
        $absence = $this->approvedAbsenceWithAverage('dpn');
        $trace = CanonicalJson::encode([
            'average_hourly_minor' => 7_500,
            'compensation_basis_points' => 6_000,
            'compensation_minor' => 10_800,
            'segment_count' => 1,
            'support_status' => 'supported',
            'window_calendar_days' => 14,
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_sickness_events
                (supplier_id, absence_id, first_day_fully_worked,
                 insurance_eligibility_confirmed, conflicting_benefit_excluded,
                 average_snapshot_id, compensation_window_from,
                 compensation_window_to, reduced_hourly_minor,
                 compensation_minor, support_status, ruleset_id, ruleset_hash,
                 calculation_trace, calculated_by)
             VALUES (?, ?, 0, 1, 1, ?, "2026-06-15", "2026-06-16", 4500,
                     10800, "supported", "synthetic-sickness-v1", ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $absence['absence_id'],
            $absence['average_id'],
            str_repeat('c', 64),
            $trace,
            $this->actors[1],
        ]);

        return [
            'average_id' => $absence['average_id'],
            'absence_id' => $absence['absence_id'],
            'event_id' => (int) $this->db->pdo()->lastInsertId(),
            'trace' => $trace,
        ];
    }

    private function createRun(): array
    {
        return $this->service->createRun(
            $this->supplierId,
            '2026-06-01',
            '2026-07-15',
            null,
            $this->actors[0],
        );
    }

    /** @return array<string,mixed> */
    private function createVersionedDeductionAgreement(): array
    {
        $repository = $this->container->get(
            PayrollDeductionAgreementRepository::class,
        );
        self::assertInstanceOf(
            PayrollDeductionAgreementRepository::class,
            $repository,
        );
        $created = $repository->create(
            $this->supplierId,
            $this->employeeId,
            DeductionAgreementTerms::fromRequest([
                'agreement_reference' => 'SYNTHETIC-BACKUP-DEDUCTION',
                'title' => 'Syntetická procentní srážka',
                'deduction_kind' => 'contribution',
                'priority_no' => 40,
                'basis_points' => 1250,
                'basis_amount_minor' => 30_000,
                'total_limit_minor' => 20_000,
                'valid_from' => '2026-01-01',
                'recipient_reference' => 'SYNTHETIC-RECIPIENT',
                'note' => 'Syntetická dohoda pro zálohu',
            ]),
            DeductionAgreementStatus::Active,
            $this->actors[0],
        );

        return $repository->update(
            $this->supplierId,
            (int) $created['id'],
            DeductionAgreementTerms::fromRequest([
                'agreement_reference' => 'SYNTHETIC-BACKUP-DEDUCTION',
                'title' => 'Syntetická procentní srážka po kontrole',
                'deduction_kind' => 'contribution',
                'priority_no' => 40,
                'basis_points' => 1250,
                'basis_amount_minor' => 30_000,
                'total_limit_minor' => 20_000,
                'valid_from' => '2026-01-01',
                'recipient_reference' => 'SYNTHETIC-RECIPIENT',
                'note' => 'Syntetická dohoda po kontrole',
            ]),
            (int) $created['row_version'],
            '2026-02-01',
            'Syntetická kontrola dohody',
            $this->actors[1],
        );
    }

    private function approveInitialRun(): \MyInvoice\Service\Payroll\Run\PayrollRunCommandResult
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-for-correction',
            $this->actors[0],
        );
        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-for-correction',
            $this->actors[0],
        );
        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-for-correction',
            $this->actors[1],
        );
        return $this->service->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'approve-for-correction',
            $this->actors[2],
        );
    }

    private function createActor(string $suffix): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, ?, "readonly", "cs", 1)'
        );
        $stmt->execute([
            "mz09-{$suffix}-" . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
            "Synthetic {$suffix}",
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{int,int} */
    private function employment(): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Synthetic Payroll Run Person", "employee", 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, "MZ09", "Syntetická účtárna", 1)'
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type, status,
                 start_date, actual_start_date, is_primary)
             VALUES (?, ?, ?, "SYN-MZ09", "employment", "active",
                     "2026-01-01", "2026-01-01", 1)'
        )->execute([$this->supplierId, $employeeId, $officeId]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 planned_start_on,
                 actual_start_on, weekly_hours, workload_basis_points,
                 social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 tax_declaration_signed, is_primary)
             VALUES (?, ?, ?, "2026-01-01", "2026-01-01", "2026-01-01",
                     40, 10000, "automatic", "automatic", "advance", 1, 1)'
        )->execute([$this->supplierId, $employmentId, $officeId]);
        return [$employeeId, $employmentId];
    }

    private function approvedInput(
        int $amountMinor,
        string $code,
        string $sourceKind,
        string $enforcementTreatment = 'included',
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment,
                 enforcement_treatment, jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, ?, ?, "base_wage", "monetary", "regular", "included",
                     "included", "included", "included", "included",
                     "included", ?, "included",
                     "included", "521", "331", "2026-01-01")'
        )->execute([
            $this->supplierId,
            $code,
            "Synthetic {$code}",
            $enforcementTreatment,
        ]);
        $componentId = (int) $pdo->lastInsertId();
        $snapshot = [
            'code' => $code,
            'name' => "Synthetic {$code}",
            'component_kind' => 'base_wage',
            'value_kind' => 'monetary',
            'frequency_kind' => 'regular',
            'tax_treatment' => 'included',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'included',
            'health_participation_treatment' => 'included',
            'health_treatment' => 'included',
            'average_earning_treatment' => 'included',
            'enforcement_treatment' => $enforcementTreatment,
            'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
            'accounting_debit_code' => '521',
            'accounting_credit_code' => '331',
            'annual_limit_minor' => null,
            'component_id' => $componentId,
            'component_row_version' => 1,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ];
        $json = CanonicalJson::encode($snapshot);
        $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id,
                 period_start, amount_minor, source_kind, status,
                 component_snapshot_json, component_snapshot_hash,
                 approved_by, approved_at)
             VALUES (?, ?, ?, ?, "2026-06-01", ?, ?, "approved", ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $componentId,
            $amountMinor,
            $sourceKind,
            $json,
            hash('sha256', $json, true),
            $this->actors[0],
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function employerPolicyInput(
        bool $automaticPostingEnabled = true,
    ): array {
        return [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'four_eyes_required' => true,
            'automatic_calculation_enabled' => true,
            'automatic_posting_enabled' => $automaticPostingEnabled,
            'automatic_payments_enabled' => true,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:payroll-run-policy',
        ];
    }

    private function apiRequest(
        string $method,
        string $uri,
        EffectiveRole $role,
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $this->supplierId,
            )
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->actors[0],
                'role' => 'readonly',
            ])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withAttribute('auth.effective_role', $role);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
