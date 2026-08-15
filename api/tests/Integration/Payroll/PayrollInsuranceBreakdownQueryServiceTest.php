<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\Calculation\CalculationStep;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxResult;
use MyInvoice\Service\Payroll\Calculation\PayrollRounding;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCalculationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthEmploymentKind;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthResult;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsurerLiabilityResult;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsurerSnapshotStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthJurisdictionEvidence;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibility;
use MyInvoice\Service\Payroll\HealthInsurance\HealthParticipationDecision;
use MyInvoice\Service\Payroll\HealthInsurance\HealthParticipationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthPersonMonthResult;
use MyInvoice\Service\Payroll\HealthInsurance\HealthRelationshipResult;
use MyInvoice\Service\Payroll\IncomeTax\AnnualTaxAccumulatorResult;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentIncomeTaxPolicy2026;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\RelationshipTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Service\Payroll\Insurance\PayrollInsuranceBreakdownQueryService;
use MyInvoice\Service\Payroll\Net\NetRelationshipIncome;
use MyInvoice\Service\Payroll\Net\PayrollNetResult;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryResultPersister;
use MyInvoice\Service\Payroll\SocialInsurance\SocialCalculationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialDiscountEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmployerRateCategory;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmploymentKind;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthResult;
use MyInvoice\Service\Payroll\SocialInsurance\SocialJurisdictionEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationDecision;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPersonMonthResult;
use MyInvoice\Service\Payroll\SocialInsurance\SocialRelationshipResult;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * MZ-10-W07 / MZ-11-W07 — rozklad pojistného musí dát tutéž částku jako uložený
 * zákonný výsledek. Kdyby dal jinou, není to vysvětlení, ale druhý tichý výpočet,
 * a účetní by se o něj opřel při kontrole.
 */
#[Group('integration')]
final class PayrollInsuranceBreakdownQueryServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const SOCIAL_RULESET_ID = 'synthetic-social-2026';
    private const HEALTH_RULESET_ID = 'synthetic-health-2026';
    private const TAX_RULESET_ID = 'synthetic-tax-2026';
    private const SOCIAL_RULESET_HASH =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const HEALTH_RULESET_HASH =
        'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const TAX_RULESET_HASH =
        'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const SOCIAL_EMPLOYEE_RATE = '0.071';
    private const SOCIAL_EMPLOYER_RATE = '0.248';
    private const HEALTH_RATE = '0.135';

    private Connection $db;
    private PayrollRunStatutoryResultPersister $persister;
    private PayrollInsuranceBreakdownQueryService $service;
    private int $supplierId;
    /** @var list<int> */
    private array $employeeIds = [];
    /** @var array<int,int> */
    private array $employmentByEmployee = [];
    private int $revisionId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        foreach ([
            'payroll_statutory_results',
            'payroll_statutory_person_results',
            'payroll_statutory_relationship_results',
        ] as $table) {
            if (!$db->hasTable($table)) {
                $this->markTestSkipped('Migrace 1255 neproběhla.');
            }
        }
        $this->db = $db;
        $repository = new PayrollStatutoryResultRepository($db);
        $this->persister = new PayrollRunStatutoryResultPersister($repository, $db);
        $this->service = new PayrollInsuranceBreakdownQueryService(
            new PayrollRunRepository($db),
            $repository,
        );

        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstSupplierId($pdo);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        foreach ([0, 1] as $_index) {
            $employeeId = $this->createEmployee($pdo, $this->supplierId);
            $this->employeeIds[] = $employeeId;
            $this->employmentByEmployee[$employeeId] = $this->createEmployment(
                $pdo,
                $this->supplierId,
                $employeeId,
            );
        }
        $this->revisionId = $this->createRevisionGraph($pdo);
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

    public function testBreakdownReconcilesWithTheStoredStatutoryResultToTheHaler(): void
    {
        $this->persistResults();
        $employeeId = $this->employeeIds[0];

        $breakdown = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $employeeId,
        );

        $social = $breakdown['social'];
        self::assertTrue($social['available']);
        self::assertSame('calculated', $social['status']);
        // 30 000 Kč × 7,1 % = 2 130 Kč, sazba i zaokrouhlení z uloženého kroku.
        self::assertSame(3_000_000, $social['assessment_base']['participating_minor']);
        self::assertSame(self::SOCIAL_EMPLOYEE_RATE, $social['employee']['contribution_step']['rate']['decimal']);
        self::assertSame(
            $social['employee']['before_discount_minor'] - $social['employee']['working_pensioner_discount_minor'],
            $social['employee']['contribution_minor'],
        );
        self::assertSame(213_000, $social['employee']['contribution_minor']);
        self::assertSame('company_month', $social['employer']['scope']);
        self::assertSame(self::SOCIAL_EMPLOYER_RATE, $social['employer']['contribution_step']['rate']['decimal']);

        $health = $breakdown['health'];
        self::assertTrue($health['available']);
        self::assertSame('persisted', $health['contribution']['rate_source']);
        self::assertSame(self::HEALTH_RATE, $health['contribution']['standard_step']['rate']['decimal']);
        self::assertSame(
            $health['contribution']['employee_standard_minor']
                + $health['contribution']['employer_standard_minor'],
            $health['contribution']['standard_minor'],
        );
        self::assertSame(
            $health['contribution']['employee_minor'] + $health['contribution']['employer_minor'],
            $health['contribution']['total_minor'],
        );
        self::assertSame(405_000, $health['contribution']['total_minor']);

        // Součet mezikroků musí dát přesně tu částku, kterou nese uložený výsledek.
        $stored = $this->storedPersonSnapshot('health_insurance', $employeeId);
        self::assertSame(
            $stored['employee_contribution_minor_units'],
            $health['contribution']['employee_minor'],
        );
        self::assertSame(
            $stored['employer_contribution_minor_units'],
            $health['contribution']['employer_minor'],
        );
        $storedSocial = $this->storedPersonSnapshot('social_insurance', $employeeId);
        self::assertSame(
            $storedSocial['employee_contribution_minor_units'],
            $social['employee']['contribution_minor'],
        );
    }

    public function testAnnualMaximumShowsUpInTheSocialBreakdown(): void
    {
        $this->persistResults(cappedBaseMinorUnits: 1_200_000, yearToDateMinorUnits: 217_753_400);
        $breakdown = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        );

        $base = $breakdown['social']['assessment_base'];
        self::assertTrue($base['annual_maximum_applied']);
        self::assertSame(3_000_000, $base['participating_minor']);
        self::assertSame(1_200_000, $base['capped_minor']);
        self::assertSame(1_800_000, $base['annual_maximum_reduction_minor']);
        self::assertSame(217_753_400, $base['year_to_date_before_month_minor']);
        // Pojistné se počítá z omezeného základu, ne z původního.
        self::assertSame(
            1_200_000,
            $breakdown['social']['employee']['contribution_step']['input_minor_units'],
        );
    }

    public function testMinimumAssessmentBaseTopUpShowsUpInTheHealthBreakdown(): void
    {
        $this->persistResults(healthBaseMinorUnits: 1_000_000, healthMinimumMinorUnits: 2_130_000);
        $breakdown = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        );

        $health = $breakdown['health'];
        self::assertTrue($health['minimum']['top_up_applied']);
        self::assertSame(2_130_000, $health['minimum']['effective_minor']);
        self::assertSame(1_130_000, $health['minimum']['top_up_base_minor']);
        self::assertSame('employee', $health['minimum']['top_up_responsibility']);
        $topUp = $health['contribution']['employee_top_up_minor']
            + $health['contribution']['employer_top_up_minor'];
        self::assertGreaterThan(0, $topUp);
        self::assertSame(
            $health['contribution']['standard_minor'] + $topUp,
            $health['contribution']['total_minor'],
        );
    }

    public function testHealthLiabilitySplitsAcrossInsurersAndMarksThePersonsOwn(): void
    {
        $this->persistResults();

        $first = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        );
        $second = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[1],
        );

        self::assertCount(2, $first['health']['insurer_liabilities']);
        self::assertSame(
            ['111', '201'],
            array_column($first['health']['insurer_liabilities'], 'insurer_code'),
        );
        self::assertSame(
            [true, false],
            array_column($first['health']['insurer_liabilities'], 'is_person_insurer'),
        );
        self::assertSame(
            [false, true],
            array_column($second['health']['insurer_liabilities'], 'is_person_insurer'),
        );
        // Osoba patří v měsíci právě jedné pojišťovně — model víc neumožňuje.
        self::assertSame('111', $first['health']['insurer']['code']);
        self::assertSame('201', $second['health']['insurer']['code']);
        // Rozpad podle pojišťoven musí sedět na součet za měsíc.
        $total = array_sum(array_column($first['health']['insurer_liabilities'], 'total_minor'));
        self::assertSame(810_000, $total);
    }

    public function testRevisionWithoutStoredResultsSaysSoInsteadOfShowingNothing(): void
    {
        $breakdown = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        );

        self::assertFalse($breakdown['social']['available']);
        self::assertSame('result_set_missing', $breakdown['social']['unavailable_reason']);
        self::assertFalse($breakdown['health']['available']);
        self::assertSame('result_set_missing', $breakdown['health']['unavailable_reason']);
        // Žádné nuly vydávané za výpočet.
        self::assertArrayNotHasKey('contribution', $breakdown['health']);
        self::assertArrayNotHasKey('employee', $breakdown['social']);
    }

    public function testRevisionWithoutStoredHealthStepReportsTheRateAsNotRecorded(): void
    {
        $this->persistResults(recordHealthSteps: false);
        $breakdown = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        );

        self::assertTrue($breakdown['health']['available']);
        self::assertSame('not_recorded', $breakdown['health']['contribution']['rate_source']);
        self::assertNull($breakdown['health']['contribution']['standard_step']);
        // Částky uložené jsou, jen se k nim nedá doložit sazba — a to se řekne.
        self::assertSame(405_000, $breakdown['health']['contribution']['total_minor']);
    }

    /**
     * Revize bez uložených kroků nesmí dopočet do minima ZATAJIT — nese ho
     * v částkách. Základ dopočtu se ale nedá vymyslet, takže zůstane null.
     */
    public function testTopUpIsStillReportedWhenTheRevisionDidNotStoreItsStep(): void
    {
        $this->persistResults(
            healthBaseMinorUnits: 1_000_000,
            healthMinimumMinorUnits: 2_130_000,
            recordHealthSteps: false,
        );
        $breakdown = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        );

        self::assertTrue($breakdown['health']['minimum']['top_up_applied']);
        self::assertNull($breakdown['health']['minimum']['top_up_base_minor']);
        self::assertGreaterThan(0, $breakdown['health']['contribution']['employee_top_up_minor']);
        self::assertSame('not_recorded', $breakdown['health']['contribution']['rate_source']);
    }

    /**
     * Osoba bez pojistného nemá krok právem — hlásit u ní „sazba se neuložila"
     * by byl planý poplach, po kterém účetní varování přestane číst.
     */
    public function testZeroContributionIsReportedAsNotApplicableNotAsMissingRate(): void
    {
        $this->persistResults(healthBaseMinorUnits: 0, recordHealthSteps: false);
        $breakdown = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        );

        self::assertSame('not_applicable', $breakdown['health']['contribution']['rate_source']);
        self::assertSame(0, $breakdown['health']['contribution']['total_minor']);
    }

    public function testForeignPersonIsNotPartOfTheRevision(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->service->breakdown($this->supplierId, $this->revisionId, 987_654_321);
    }

    private function persistResults(
        int $cappedBaseMinorUnits = 3_000_000,
        int $yearToDateMinorUnits = 0,
        int $healthBaseMinorUnits = 3_000_000,
        int $healthMinimumMinorUnits = 0,
        bool $recordHealthSteps = true,
    ): void {
        $this->persister->persist(
            $this->supplierId,
            $this->revisionId,
            null,
            $this->snapshot(),
            $this->socialResult($cappedBaseMinorUnits, $yearToDateMinorUnits),
            $this->healthResult($healthBaseMinorUnits, $healthMinimumMinorUnits, $recordHealthSteps),
            $this->taxResults(),
            $this->netResults(),
        );
    }

    private function socialResult(int $cappedBase, int $yearToDate): SocialInsuranceMonthResult
    {
        $rate = DecimalRate::fromString(self::SOCIAL_EMPLOYEE_RATE);
        $people = [];
        $employeeTotal = 0;
        foreach ($this->employeeIds as $employeeId) {
            $relationshipReference = "employment:{$this->employmentByEmployee[$employeeId]}";
            $step = CalculationStep::calculate(
                'monthly-employee-social-insurance',
                $cappedBase,
                $rate,
                RoundingMode::Ceil,
            );
            $contribution = PayrollRounding::ceilToCzk($step->outputMinorUnits);
            $employeeTotal += $contribution;
            $people[] = new SocialPersonMonthResult(
                "employee:{$employeeId}",
                SocialCalculationStatus::Calculated,
                SocialJurisdictionEvidence::CzechRegimeVerified,
                'evidence:social-a1',
                null,
                $yearToDate,
                3_000_000,
                $cappedBase,
                $contribution,
                0,
                $contribution,
                $step,
                null,
                [new SocialRelationshipResult(
                    $relationshipReference,
                    SocialEmploymentKind::Employment,
                    new SocialParticipationDecision(
                        $relationshipReference,
                        SocialParticipationStatus::Participates,
                        3_000_000,
                        3_000_000,
                        null,
                        ['regular-employment'],
                    ),
                    3_000_000,
                    $cappedBase,
                    ['BASE'],
                    [],
                    ['BASE'],
                    ['MEAL_ALLOWANCE'],
                    SocialDiscountEvidence::NotClaimed,
                    SocialEmployerRateCategory::Ordinary,
                    1,
                    null,
                )],
                [],
            );
        }
        $companyBase = $cappedBase * count($this->employeeIds);
        $employerStep = CalculationStep::calculate(
            'monthly-employer-social-insurance',
            $companyBase,
            DecimalRate::fromString(self::SOCIAL_EMPLOYER_RATE),
            RoundingMode::Ceil,
        );
        $employer = PayrollRounding::ceilToCzk($employerStep->outputMinorUnits);

        return new SocialInsuranceMonthResult(
            '2026-06-30',
            SocialCalculationStatus::Calculated,
            3_000_000 * count($this->employeeIds),
            $companyBase,
            $employeeTotal,
            $employer,
            0,
            0,
            $employer,
            $employerStep,
            null,
            $people,
            [],
            self::SOCIAL_RULESET_ID,
            self::SOCIAL_RULESET_HASH,
        );
    }

    private function healthResult(
        int $assessmentBase,
        int $minimumBase,
        bool $recordSteps,
    ): HealthInsuranceMonthResult {
        $rate = DecimalRate::fromString(self::HEALTH_RATE);
        $insurerCodes = ['111', '201'];
        $people = [];
        $liabilities = [];
        $employeeTotal = 0;
        $employerTotal = 0;
        foreach ($this->employeeIds as $index => $employeeId) {
            $relationshipReference = "employment:{$this->employmentByEmployee[$employeeId]}";
            $standardStep = CalculationStep::calculate(
                'monthly-health-insurance-standard',
                $assessmentBase,
                $rate,
                RoundingMode::Ceil,
            );
            $standard = PayrollRounding::ceilToCzk($standardStep->outputMinorUnits);
            $employeeStandard = PayrollRounding::ceilToCzk(
                RoundingMode::Ceil->roundFraction($standard, 3),
            );
            $employerStandard = $standard - $employeeStandard;
            $topUpStep = null;
            $topUp = 0;
            if ($minimumBase > $assessmentBase) {
                $topUpStep = CalculationStep::calculate(
                    'monthly-health-insurance-minimum-top-up',
                    $minimumBase - $assessmentBase,
                    $rate,
                    RoundingMode::Ceil,
                );
                $topUp = PayrollRounding::ceilToCzk($topUpStep->outputMinorUnits);
            }
            $employee = $employeeStandard + $topUp;
            $employeeTotal += $employee;
            $employerTotal += $employerStandard;
            $people[] = new HealthPersonMonthResult(
                personId: "employee:{$employeeId}",
                status: HealthCalculationStatus::Calculated,
                jurisdiction: HealthJurisdictionEvidence::CzechRegimeVerified,
                jurisdictionEvidenceReference: 'evidence:health-jurisdiction',
                insurerStatus: HealthInsurerSnapshotStatus::Verified,
                insurerCode: $insurerCodes[$index],
                insurerEvidenceReference: 'evidence:health-insurer',
                assessmentBaseMinorUnits: $assessmentBase,
                otherEmployerAssessmentBaseMinorUnits: 0,
                combinedAssessmentBaseMinorUnits: $assessmentBase,
                employmentCalendarDays: 30,
                minimumExcludedCalendarDays: 0,
                minimumApplicableCalendarDays: 30,
                statutoryMonthlyMinimumMinorUnits: $minimumBase,
                effectiveMinimumMinorUnits: $minimumBase,
                topUpResponsibility: HealthMinimumTopUpResponsibility::Employee,
                topUpResponsibilityEvidenceReference: null,
                selectedTopUpEmployerEvidenceReference: null,
                standardContributionMinorUnits: $standard,
                employeeStandardContributionMinorUnits: $employeeStandard,
                employerStandardContributionMinorUnits: $employerStandard,
                employeeMinimumTopUpMinorUnits: $topUp,
                employerMinimumTopUpMinorUnits: 0,
                employeeContributionMinorUnits: $employee,
                employerContributionMinorUnits: $employerStandard,
                totalContributionMinorUnits: $standard + $topUp,
                relationships: [new HealthRelationshipResult(
                    $relationshipReference,
                    HealthEmploymentKind::Employment,
                    new HealthParticipationDecision(
                        $relationshipReference,
                        HealthParticipationStatus::Participates,
                        $assessmentBase,
                        $assessmentBase,
                        null,
                        ['regular-employment'],
                    ),
                    $assessmentBase,
                    $assessmentBase,
                    ['BASE'],
                    [],
                    ['BASE'],
                    [],
                )],
                minimumReductionEvidence: [],
                otherEmployerEvidence: [],
                issues: [],
                standardContributionStep: $recordSteps ? $standardStep : null,
                minimumTopUpStep: $recordSteps ? $topUpStep : null,
            );
            $liabilities[] = new HealthInsurerLiabilityResult(
                $insurerCodes[$index],
                1,
                $assessmentBase,
                $employee,
                $employerStandard,
                $employee + $employerStandard,
            );
        }

        return new HealthInsuranceMonthResult(
            '2026-06-30',
            HealthCalculationStatus::Calculated,
            $assessmentBase * count($this->employeeIds),
            $employeeTotal,
            $employerTotal,
            $employeeTotal + $employerTotal,
            $people,
            $liabilities,
            [],
            self::HEALTH_RULESET_ID,
            self::HEALTH_RULESET_HASH,
        );
    }

    /** @return array<int,MonthlyEmploymentIncomeTaxResult> */
    private function taxResults(): array
    {
        $results = [];
        foreach ($this->employeeIds as $employeeId) {
            $relationshipReference = "employment:{$this->employmentByEmployee[$employeeId]}";
            $results[$employeeId] = new MonthlyEmploymentIncomeTaxResult(
                TaxCalculationStatus::Calculated,
                '2026-06-30',
                "employee:{$employeeId}",
                "supplier:{$this->supplierId}",
                [new RelationshipTaxResult(
                    $relationshipReference,
                    EmploymentRelationshipKind::Employment,
                    3_000_000,
                    TaxRegime::Advance,
                    null,
                )],
                new MonthlyAdvanceTaxResult(
                    3_000_000,
                    3_000_000,
                    3_000_000,
                    0,
                    [],
                    450_000,
                    0,
                    0,
                    false,
                    450_000,
                    0,
                    self::TAX_RULESET_ID,
                    self::TAX_RULESET_HASH,
                ),
                [],
                0,
                0,
                0,
                0,
                [],
                0,
                0,
                new AnnualTaxAccumulatorResult(
                    2026,
                    5,
                    3_000_000,
                    0,
                    450_000,
                    0,
                    0,
                    0,
                    0,
                    3_000_000,
                    false,
                    [],
                    true,
                    false,
                ),
                [],
                EmploymentIncomeTaxPolicy2026::ID,
                EmploymentIncomeTaxPolicy2026::contractHash(),
                self::TAX_RULESET_ID,
                self::TAX_RULESET_HASH,
            );
        }
        ksort($results, SORT_NUMERIC);

        return $results;
    }

    /** @return array<int,PayrollNetResult> */
    private function netResults(): array
    {
        $results = [];
        foreach ($this->employeeIds as $employeeId) {
            $results[$employeeId] = new PayrollNetResult(
                "employee:{$employeeId}",
                [new NetRelationshipIncome(
                    "employment:{$this->employmentByEmployee[$employeeId]}",
                    3_000_000,
                    0,
                )],
                3_000_000,
                0,
                213_000,
                135_000,
                450_000,
                0,
                0,
                0,
                2_202_000,
                0,
                2_202_000,
                [],
            );
        }
        ksort($results, SORT_NUMERIC);

        return $results;
    }

    /** @return array<string,mixed> */
    private function storedPersonSnapshot(string $kind, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT result_snapshot_json
               FROM payroll_statutory_person_results
              WHERE supplier_id = ? AND revision_id = ?
                AND calculation_kind = ? AND employee_id = ?',
        );
        $stmt->execute([$this->supplierId, $this->revisionId, $kind, $employeeId]);
        $json = $stmt->fetchColumn();
        self::assertIsString($json);

        return (array) json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        $people = [];
        foreach ($this->employeeIds as $employeeId) {
            $employmentId = $this->employmentByEmployee[$employeeId];
            $people[] = [
                'employee' => [
                    'id' => $employeeId,
                    'full_name' => "Syntetická osoba {$employeeId}",
                    'profile_status' => 'complete',
                    'is_active' => true,
                ],
                'statutory_evidence' => ['schema_version' => 'synthetic.v1'],
                'enforcement_evidence' => null,
                'employments' => [[
                    'employment' => [
                        'id' => $employmentId,
                        'employee_id' => $employeeId,
                        'code' => 'synthetic',
                        'relation_type' => 'employment',
                    ],
                    'term' => ['id' => 1, 'effective_from' => '2026-01-01'],
                    'inputs' => [],
                    'absences' => [],
                    'time_month' => null,
                ]],
            ];
        }

        return [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'payment_date' => '2026-07-10',
            'statutory_period' => [
                'period_start' => '2026-06-01',
                'period_end' => '2026-06-30',
                'payment_date' => '2026-07-10',
                'tax_calculation_date' => '2026-06-30',
                'social_calculation_date' => '2026-06-30',
                'health_calculation_date' => '2026-06-30',
            ],
            'office_id' => null,
            'ruleset_manifest' => [
                ['id' => self::SOCIAL_RULESET_ID, 'sha256' => self::SOCIAL_RULESET_HASH],
                ['id' => self::HEALTH_RULESET_ID, 'sha256' => self::HEALTH_RULESET_HASH],
                ['id' => self::TAX_RULESET_ID, 'sha256' => self::TAX_RULESET_HASH],
            ],
            'people' => $people,
        ];
    }

    private function createRevisionGraph(PDO $pdo): int
    {
        $snapshotJson = CanonicalJson::encode($this->snapshot());
        $manifestJson = CanonicalJson::encode([
            'rulesets' => $this->snapshot()['ruleset_manifest'],
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, "2026-06-01", "2026-07-10")',
        )->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "calculated",
                     "payroll-run-input.v2", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $runId,
            hash('sha256', $manifestJson),
            $snapshotJson,
            hash('sha256', $snapshotJson),
            hash('sha256', 'synthetic-insurance-breakdown-' . $this->supplierId, true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $personInsert = $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")',
        );
        $employmentInsert = $pdo->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id,
                 input_json, input_hash, status)
             VALUES (?, ?, ?, ?, "{}", ?, "calculated")',
        );
        foreach ($this->employeeIds as $employeeId) {
            $personInsert->execute([$this->supplierId, $revisionId, $employeeId]);
            $employmentInsert->execute([
                $this->supplierId,
                $revisionId,
                $employeeId,
                $this->employmentByEmployee[$employeeId],
                str_repeat('3', 64),
            ]);
        }

        return $revisionId;
    }

    private function createEmployee(PDO $pdo, int $supplierId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba", "employee", "hpp", 1, 1, 0, 30000, 0, 1)',
        )->execute([$supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function createEmployment(PDO $pdo, int $supplierId, int $employeeId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01")',
        )->execute([$supplierId, $employeeId, "synthetic-{$employeeId}"]);

        return (int) $pdo->lastInsertId();
    }

    private function firstSupplierId(PDO $pdo): int
    {
        $stmt = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }
}
