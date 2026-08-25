<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\HealthInsurance;

use MyInvoice\Service\Payroll\HealthInsurance\HealthAssessmentComponent;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCalculationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthComponentTreatment;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCorrectionTreatment;
use MyInvoice\Service\Payroll\HealthInsurance\HealthEmploymentKind;
use MyInvoice\Service\Payroll\HealthInsurance\HealthIncomeAttribution;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthCalculator;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceRelationshipInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsurerSnapshotStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthJurisdictionEvidence;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumReductionInterval;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumReductionReason;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpEmployerSelection;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibility;
use MyInvoice\Service\Payroll\HealthInsurance\HealthOtherEmployerBase;
use MyInvoice\Service\Payroll\HealthInsurance\HealthParticipationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthPersonMonthInput;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Tests\Fixtures\Payroll\ActivePayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

final class HealthInsuranceMonthCalculatorTest extends TestCase
{
    public function testCalculatesOrdinaryEmploymentWithEmployeeMinimumTopUp(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship('hpp', HealthEmploymentKind::Employment, 1_000_000),
            ]),
        ]);

        $expected = $this->golden('ordinary_minimum');
        self::assertSame(HealthCalculationStatus::Calculated, $result->status);
        self::assertSame($expected['assessment_base_minor_units'], $result->assessmentBaseMinorUnits);
        self::assertSame(
            $expected['effective_minimum_minor_units'],
            $result->people[0]->effectiveMinimumMinorUnits,
        );
        self::assertSame(
            $expected['employee_contribution_minor_units'],
            $result->employeeContributionMinorUnits,
        );
        self::assertSame(
            $expected['employer_contribution_minor_units'],
            $result->employerContributionMinorUnits,
        );
        self::assertSame(
            $expected['total_contribution_minor_units'],
            $result->totalContributionMinorUnits,
        );
    }

    public function testAggregatesDppButDoesNotCombineItWithDpc(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [
                    $this->relationship('dpp-a', HealthEmploymentKind::Dpp, 700_000),
                    $this->relationship('dpp-b', HealthEmploymentKind::Dpp, 500_000),
                    $this->relationship('dpc', HealthEmploymentKind::Dpc, 449_900),
                ],
                reductions: [$this->fullMonthReduction(HealthMinimumReductionReason::StateInsured)],
            ),
        ]);

        $expected = $this->golden('separate_agreement_groups');
        self::assertSame($expected['assessment_base_minor_units'], $result->assessmentBaseMinorUnits);
        self::assertSame(
            HealthParticipationStatus::DoesNotParticipate,
            $result->people[0]->relationships[0]->participation->status,
        );
        self::assertSame(
            HealthParticipationStatus::Participates,
            $result->people[0]->relationships[1]->participation->status,
        );
        self::assertSame(
            HealthParticipationStatus::Participates,
            $result->people[0]->relationships[2]->participation->status,
        );
        self::assertSame(
            $expected['total_contribution_minor_units'],
            $result->totalContributionMinorUnits,
        );
    }

    public function testCorporateBodyRewardParticipatesWithoutAgreementThreshold(): void
    {
        $result = $this->calculate([
            $this->person(
                'executive',
                [
                    $this->relationship(
                        'executive-office',
                        HealthEmploymentKind::CorporateBody,
                        450_000,
                    ),
                ],
                reductions: [$this->fullMonthReduction(HealthMinimumReductionReason::StateInsured)],
            ),
        ]);

        self::assertSame(HealthCalculationStatus::Calculated, $result->status);
        self::assertSame(
            HealthParticipationStatus::Participates,
            $result->people[0]->relationships[0]->participation->status,
        );
        self::assertSame(60_800, $result->totalContributionMinorUnits);
        self::assertSame(20_300, $result->employeeContributionMinorUnits);
        self::assertSame(40_500, $result->employerContributionMinorUnits);
    }

    public function testDpcParticipatesAtExactThresholdAndIgnoresInactiveZeroRelationship(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [
                    $this->relationship('dpc-current', HealthEmploymentKind::Dpc, 450_000),
                    $this->relationship(
                        'dpc-old',
                        HealthEmploymentKind::Dpc,
                        0,
                        from: '2026-07-01',
                        to: '2026-07-31',
                    ),
                ],
                reductions: [$this->fullMonthReduction(HealthMinimumReductionReason::StateInsured)],
            ),
        ]);

        self::assertSame(HealthCalculationStatus::Calculated, $result->status);
        self::assertSame(
            HealthParticipationStatus::Participates,
            $result->people[0]->relationships[0]->participation->status,
        );
        self::assertSame(
            HealthParticipationStatus::DoesNotParticipate,
            $result->people[0]->relationships[1]->participation->status,
        );
        self::assertSame(60_800, $result->totalContributionMinorUnits);
    }

    public function testUsesInclusiveProportionalCalendarDaysForEmploymentStart(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship(
                    'hpp',
                    HealthEmploymentKind::Employment,
                    500_000,
                    from: '2026-08-16',
                ),
            ]),
        ]);

        $expected = $this->golden('proportional_start');
        self::assertSame(
            $expected['employment_calendar_days'],
            $result->people[0]->employmentCalendarDays,
        );
        self::assertSame(
            $expected['effective_minimum_minor_units'],
            $result->people[0]->effectiveMinimumMinorUnits,
        );
        self::assertSame(
            $expected['employee_contribution_minor_units'],
            $result->employeeContributionMinorUnits,
        );
        self::assertSame(
            $expected['total_contribution_minor_units'],
            $result->totalContributionMinorUnits,
        );
    }

    public function testUnionsOverlappingVerifiedMinimumReductionIntervals(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [$this->relationship('hpp', HealthEmploymentKind::Employment, 500_000)],
                reductions: [
                    new HealthMinimumReductionInterval(
                        '2026-08-10',
                        '2026-08-20',
                        HealthMinimumReductionReason::SicknessCareOrQuarantine,
                        'absence:synthetic-1',
                    ),
                    new HealthMinimumReductionInterval(
                        '2026-08-15',
                        '2026-08-25',
                        HealthMinimumReductionReason::StateInsured,
                        'state:synthetic-1',
                    ),
                ],
            ),
        ]);

        self::assertSame(16, $result->people[0]->minimumExcludedCalendarDays);
        self::assertSame(15, $result->people[0]->minimumApplicableCalendarDays);
        self::assertSame(1_083_871, $result->people[0]->effectiveMinimumMinorUnits);
    }

    public function testFullMonthVerifiedExceptionUsesActualAssessmentBase(): void
    {
        foreach ([
            HealthMinimumReductionReason::StateInsured,
            HealthMinimumReductionReason::ZtpOrZtpP,
            HealthMinimumReductionReason::PensionAgeWithoutPension,
            HealthMinimumReductionReason::OsvcMinimumAdvance,
            HealthMinimumReductionReason::FosterRewardOnly,
        ] as $reason) {
            $kind = $reason === HealthMinimumReductionReason::FosterRewardOnly
                ? HealthEmploymentKind::FosterReward
                : HealthEmploymentKind::Employment;
            $result = $this->calculate([
                $this->person(
                    'person-' . $reason->value,
                    [$this->relationship('relationship', $kind, 100_000)],
                    reductions: [$this->fullMonthReduction($reason)],
                ),
            ]);

            self::assertSame(0, $result->people[0]->effectiveMinimumMinorUnits, $reason->value);
            self::assertSame(13_500, $result->totalContributionMinorUnits, $reason->value);
        }
    }

    public function testEmployerPaysTopUpOnlyWithVerifiedEmployerObstacle(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [$this->relationship('hpp', HealthEmploymentKind::Employment, 1_000_000)],
                topUpResponsibility:
                    HealthMinimumTopUpResponsibility::EmployerObstacleVerified,
                topUpEvidence: 'obstacle:synthetic-employer',
            ),
        ]);

        self::assertSame(45_000, $result->employeeContributionMinorUnits);
        self::assertSame(257_400, $result->employerContributionMinorUnits);
        self::assertSame(167_400, $result->people[0]->employerMinimumTopUpMinorUnits);
    }

    public function testFosterRewardOnlyExceptionRejectsAnotherRelationshipKind(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [$this->relationship('hpp', HealthEmploymentKind::Employment, 100_000)],
                reductions: [
                    $this->fullMonthReduction(HealthMinimumReductionReason::FosterRewardOnly),
                ],
            ),
        ]);

        self::assertSame(HealthCalculationStatus::ManualReview, $result->status);
        self::assertContains(
            'person:person-1:foster_reward_only_exception_relationships_mismatch',
            $result->issues,
        );
    }

    public function testCombinesOtherEmployerBaseWithoutEvidenceReferences(): void
    {
        $otherEmployer = new HealthOtherEmployerBase(
            'employer-2',
            1_000_000,
            '2026-08-01',
            null,
            null,
        );
        $result = $this->calculate([
            $this->person(
                'person-1',
                [$this->relationship('hpp', HealthEmploymentKind::Employment, 500_000)],
                otherEmployerBases: [$otherEmployer],
                topUpEmployerSelection:
                    HealthMinimumTopUpEmployerSelection::ThisEmployer,
            ),
        ]);
        $expected = $this->golden('multiple_employers');
        self::assertSame(HealthCalculationStatus::Calculated, $result->status);
        self::assertSame(
            $expected['other_employer_assessment_base_minor_units'],
            $result->people[0]->otherEmployerAssessmentBaseMinorUnits,
        );
        self::assertSame(
            $expected['combined_assessment_base_minor_units'],
            $result->people[0]->combinedAssessmentBaseMinorUnits,
        );
        self::assertSame(
            $expected['total_contribution_minor_units'],
            $result->totalContributionMinorUnits,
        );
    }

    public function testSelectionEvidenceWithoutSelectedEmployerFailsClosed(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [$this->relationship('hpp', HealthEmploymentKind::Employment, 500_000)],
                otherEmployerBases: [
                    new HealthOtherEmployerBase(
                        'employer-2',
                        1_000_000,
                        '2026-08-01',
                        null,
                        'confirmation:synthetic-employer-2',
                    ),
                ],
                selectedEmployerEvidence: 'selection:synthetic-unresolved',
            ),
        ]);

        self::assertSame(HealthCalculationStatus::ManualReview, $result->status);
        self::assertContains(
            'person:person-1:selected_top_up_employer_unverified',
            $result->issues,
        );
        self::assertNull($result->totalContributionMinorUnits);
    }

    public function testOtherSelectedEmployerCarriesTopUpOutsideThisEmployer(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [$this->relationship('hpp', HealthEmploymentKind::Employment, 500_000)],
                otherEmployerBases: [
                    new HealthOtherEmployerBase(
                        'employer-2',
                        1_000_000,
                        '2026-08-01',
                        null,
                        'confirmation:synthetic-employer-2',
                    ),
                ],
                selectedEmployerEvidence: 'selection:synthetic-employer-2',
                topUpEmployerSelection:
                    HealthMinimumTopUpEmployerSelection::OtherEmployer,
            ),
        ]);

        self::assertSame(HealthCalculationStatus::Calculated, $result->status);
        self::assertSame(67_500, $result->totalContributionMinorUnits);
        self::assertSame(0, $result->people[0]->employeeMinimumTopUpMinorUnits);
        self::assertSame(0, $result->people[0]->employerMinimumTopUpMinorUnits);
        self::assertSame(
            HealthMinimumTopUpEmployerSelection::OtherEmployer,
            $result->people[0]->topUpEmployerSelection,
        );
    }

    public function testOtherEmployerBaseOutsideCalculationMonthFailsClosed(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [$this->relationship('hpp', HealthEmploymentKind::Employment, 500_000)],
                otherEmployerBases: [
                    new HealthOtherEmployerBase(
                        'employer-2',
                        2_000_000,
                        '2026-07-01',
                        '2026-07-31',
                        'confirmation:synthetic-employer-2',
                    ),
                ],
            ),
        ]);

        self::assertSame(HealthCalculationStatus::ManualReview, $result->status);
        self::assertSame(
            0,
            $result->people[0]->otherEmployerAssessmentBaseMinorUnits,
        );
        self::assertContains(
            'person:person-1:other_employer_base_outside_calculation_month:employer-2',
            $result->issues,
        );
    }

    public function testRejectsDuplicateRelationshipIds(): void
    {
        $relationship = $this->relationship(
            'duplicate',
            HealthEmploymentKind::Employment,
            500_000,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship IDs must be unique');
        $this->person('person-1', [$relationship, $relationship]);
    }

    public function testRejectsDuplicateOtherEmployerReferences(): void
    {
        $otherEmployer = new HealthOtherEmployerBase(
            'employer-2',
            1_000_000,
            '2026-08-01',
            null,
            'confirmation:synthetic-employer-2',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('other employer references must be unique');
        $this->person(
            'person-1',
            [$this->relationship('hpp', HealthEmploymentKind::Employment, 500_000)],
            otherEmployerBases: [$otherEmployer, $otherEmployer],
        );
    }

    public function testRejectsSelectedTopUpEmployerEvidenceWithoutOtherEmployer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires another employer');
        $this->person(
            'person-1',
            [$this->relationship('hpp', HealthEmploymentKind::Employment, 500_000)],
            selectedEmployerEvidence: 'selection:synthetic-employer-1',
        );
    }

    public function testAggregatesPerPersonRoundedLiabilitiesByInsurer(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-a',
                [$this->relationship('hpp-a', HealthEmploymentKind::Employment, 12_345)],
                reductions: [$this->fullMonthReduction(HealthMinimumReductionReason::StateInsured)],
            ),
            $this->person(
                'person-b',
                [$this->relationship('hpp-b', HealthEmploymentKind::Employment, 12_345)],
                reductions: [$this->fullMonthReduction(HealthMinimumReductionReason::StateInsured)],
            ),
            $this->person(
                'person-c',
                [$this->relationship('hpp-c', HealthEmploymentKind::Employment, 10_000)],
                insurerCode: '201',
                reductions: [$this->fullMonthReduction(HealthMinimumReductionReason::StateInsured)],
            ),
        ]);

        self::assertCount(2, $result->insurerLiabilities);
        self::assertSame('111', $result->insurerLiabilities[0]->insurerCode);
        self::assertSame(2, $result->insurerLiabilities[0]->personCount);
        self::assertSame(3_400, $result->insurerLiabilities[0]->totalContributionMinorUnits);
        self::assertSame('201', $result->insurerLiabilities[1]->insurerCode);
        self::assertSame(1_400, $result->insurerLiabilities[1]->totalContributionMinorUnits);
    }

    public function testVerifiedForeignRegimeProducesNoCzechLiability(): void
    {
        $result = $this->calculate([
            $this->person(
                'foreign-person',
                [$this->relationship('foreign-hpp', HealthEmploymentKind::Employment, 5_000_000)],
                jurisdiction: HealthJurisdictionEvidence::ForeignRegimeVerified,
                jurisdictionEvidence: 'a1:synthetic-foreign',
                insurerStatus: HealthInsurerSnapshotStatus::NotApplicable,
                insurerCode: null,
                insurerEvidence: null,
            ),
        ]);

        self::assertSame(HealthCalculationStatus::Calculated, $result->status);
        self::assertSame(0, $result->totalContributionMinorUnits);
        self::assertSame([], $result->insurerLiabilities);
        self::assertSame(
            HealthParticipationStatus::DoesNotParticipate,
            $result->people[0]->relationships[0]->participation->status,
        );
    }

    public function testUnverifiedInsurerAndMinimumClaimFailClosed(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [$this->relationship('hpp', HealthEmploymentKind::Employment, 1_000_000)],
                insurerStatus: HealthInsurerSnapshotStatus::Unverified,
                insurerEvidence: null,
                reductions: [
                    new HealthMinimumReductionInterval(
                        '2026-08-01',
                        '2026-08-31',
                        HealthMinimumReductionReason::Unverified,
                        null,
                    ),
                ],
            ),
        ]);

        self::assertSame(HealthCalculationStatus::ManualReview, $result->status);
        self::assertNull($result->totalContributionMinorUnits);
        self::assertContains('person:person-1:health_insurer_snapshot_unverified', $result->issues);
        self::assertContains('person:person-1:minimum_reduction_unverified', $result->issues);
    }

    public function testPriorPeriodCorrectionRequiresRevisionButCurrentMonthCorrectionCanNet(): void
    {
        $prior = $this->calculate([
            $this->person('person-1', [
                $this->relationship(
                    'hpp',
                    HealthEmploymentKind::Employment,
                    1_000_000,
                    extraComponents: [
                        $this->component(
                            'prior_correction',
                            -100_000,
                            HealthCorrectionTreatment::PriorPeriodRevision,
                        ),
                    ],
                ),
            ]),
        ]);
        self::assertSame(HealthCalculationStatus::ManualReview, $prior->status);
        self::assertContains(
            'person:person-1:relationship:hpp:prior_period_correction_requires_period_revision:prior_correction',
            $prior->issues,
        );

        $current = $this->calculate([
            $this->person(
                'person-1',
                [
                    $this->relationship(
                        'hpp',
                        HealthEmploymentKind::Employment,
                        1_000_000,
                        extraComponents: [$this->component('current_correction', -100_000)],
                    ),
                ],
                reductions: [$this->fullMonthReduction(HealthMinimumReductionReason::StateInsured)],
            ),
        ]);
        self::assertSame(HealthCalculationStatus::Calculated, $current->status);
        self::assertSame(900_000, $current->assessmentBaseMinorUnits);
        self::assertSame(121_500, $current->totalContributionMinorUnits);
    }

    public function testNegativeCurrentMonthBaseFailsClosed(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship('hpp', HealthEmploymentKind::Employment, -1),
            ]),
        ]);

        self::assertSame(HealthCalculationStatus::ManualReview, $result->status);
        self::assertContains(
            'person:person-1:negative_assessment_base_requires_period_revision',
            $result->issues,
        );
    }

    public function testPostTerminationDppIncomeIsAttributedToEndMonth(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [
                    $this->relationship(
                        'dpp',
                        HealthEmploymentKind::Dpp,
                        1_200_000,
                        to: '2026-08-10',
                        attribution: HealthIncomeAttribution::PostTerminationEndMonthVerified,
                    ),
                ],
                reductions: [$this->fullMonthReduction(HealthMinimumReductionReason::StateInsured)],
            ),
        ]);

        self::assertSame(HealthCalculationStatus::Calculated, $result->status);
        self::assertSame(10, $result->people[0]->employmentCalendarDays);
        self::assertSame(162_000, $result->totalContributionMinorUnits);
    }

    public function testOrdinaryPostTerminationIncomeUsesPaymentMonthWithoutMinimum(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship(
                    'former-hpp',
                    HealthEmploymentKind::Employment,
                    100_000,
                    from: '2026-01-01',
                    to: '2026-07-31',
                    attribution:
                        HealthIncomeAttribution::PostTerminationPaymentMonthVerified,
                ),
            ]),
        ]);

        self::assertSame(HealthCalculationStatus::Calculated, $result->status);
        self::assertSame(0, $result->people[0]->employmentCalendarDays);
        self::assertSame(0, $result->people[0]->effectiveMinimumMinorUnits);
        self::assertSame(13_500, $result->totalContributionMinorUnits);
        self::assertFalse($result->people[0]->ppzCounted);
        self::assertSame(0, $result->insurerLiabilities[0]->personCount);
    }

    public function testPostTerminationIncomeDoesNotInheritMinimumFromOtherEmployer(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [
                    $this->relationship(
                        'former-hpp',
                        HealthEmploymentKind::Employment,
                        100_000,
                        from: '2026-01-01',
                        to: '2026-07-31',
                        attribution:
                            HealthIncomeAttribution::PostTerminationPaymentMonthVerified,
                    ),
                ],
                otherEmployerBases: [
                    new HealthOtherEmployerBase(
                        'employer-2',
                        1_000_000,
                        '2026-08-01',
                        null,
                        'confirmation:synthetic-employer-2',
                    ),
                ],
            ),
        ]);

        self::assertSame(HealthCalculationStatus::Calculated, $result->status);
        self::assertSame(0, $result->people[0]->effectiveMinimumMinorUnits);
        self::assertSame(13_500, $result->totalContributionMinorUnits);
        self::assertFalse($result->people[0]->ppzCounted);
    }

    public function testActiveInsuredPersonWithZeroBaseIsCountedForPpz(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [$this->relationship('hpp', HealthEmploymentKind::Employment, 0)],
                reductions: [
                    $this->fullMonthReduction(
                        HealthMinimumReductionReason::SicknessCareOrQuarantine,
                    ),
                ],
            ),
        ]);

        self::assertSame(HealthCalculationStatus::Calculated, $result->status);
        self::assertSame(0, $result->totalContributionMinorUnits);
        self::assertTrue($result->people[0]->ppzCounted);
        self::assertSame(1, $result->insurerLiabilities[0]->personCount);
    }

    public function testResultCarriesImmutableRulesetIdentity(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [$this->relationship('hpp', HealthEmploymentKind::Employment, 1_000_000)],
                reductions: [$this->fullMonthReduction(HealthMinimumReductionReason::StateInsured)],
            ),
        ]);
        $ruleset = ActivePayrollRulesetFixture::provider(
            PayrollRulesetDomain::HealthInsurance,
        )->forCalculation(PayrollRulesetDomain::HealthInsurance, '2026-08-31');

        self::assertSame($ruleset->id, $result->rulesetId);
        self::assertSame($ruleset->canonicalHash, $result->rulesetHash);
        self::assertJson($result->toCanonicalJson());
    }

    /** @param list<HealthPersonMonthInput> $people */
    private function calculate(array $people): \MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthResult
    {
        return (new HealthInsuranceMonthCalculator(
            ActivePayrollRulesetFixture::provider(PayrollRulesetDomain::HealthInsurance),
        ))->calculate(new HealthInsuranceMonthInput('2026-08-31', $people));
    }

    /**
     * @param list<HealthMinimumReductionInterval> $reductions
     * @param list<HealthOtherEmployerBase> $otherEmployerBases
     * @param non-empty-list<HealthInsuranceRelationshipInput> $relationships
     */
    private function person(
        string $id,
        array $relationships,
        HealthJurisdictionEvidence $jurisdiction =
            HealthJurisdictionEvidence::CzechRegimeVerified,
        ?string $jurisdictionEvidence = null,
        HealthInsurerSnapshotStatus $insurerStatus =
            HealthInsurerSnapshotStatus::Verified,
        ?string $insurerCode = '111',
        ?string $insurerEvidence = 'insurer:synthetic-snapshot',
        array $reductions = [],
        array $otherEmployerBases = [],
        HealthMinimumTopUpResponsibility $topUpResponsibility =
            HealthMinimumTopUpResponsibility::Employee,
        ?string $topUpEvidence = null,
        ?string $selectedEmployerEvidence = null,
        HealthMinimumTopUpEmployerSelection $topUpEmployerSelection =
            HealthMinimumTopUpEmployerSelection::Unverified,
    ): HealthPersonMonthInput {
        return new HealthPersonMonthInput(
            $id,
            $jurisdiction,
            $jurisdictionEvidence,
            $insurerStatus,
            $insurerCode,
            $insurerEvidence,
            $relationships,
            $reductions,
            $otherEmployerBases,
            $topUpResponsibility,
            $topUpEvidence,
            $selectedEmployerEvidence,
            $topUpEmployerSelection,
        );
    }

    /**
     * @param list<HealthAssessmentComponent> $extraComponents
     */
    private function relationship(
        string $id,
        HealthEmploymentKind $kind,
        int $amountMinorUnits,
        string $from = '2026-08-01',
        ?string $to = null,
        HealthIncomeAttribution $attribution =
            HealthIncomeAttribution::CurrentEmploymentMonth,
        array $extraComponents = [],
    ): HealthInsuranceRelationshipInput {
        return new HealthInsuranceRelationshipInput(
            $id,
            $kind,
            $from,
            $to,
            $attribution,
            [$this->component('wage', $amountMinorUnits), ...$extraComponents],
        );
    }

    private function component(
        string $code,
        int $amountMinorUnits,
        HealthCorrectionTreatment $correction =
            HealthCorrectionTreatment::CurrentMonth,
    ): HealthAssessmentComponent {
        return new HealthAssessmentComponent(
            $code,
            $amountMinorUnits,
            HealthComponentTreatment::Included,
            HealthComponentTreatment::Included,
            $correction,
        );
    }

    private function fullMonthReduction(
        HealthMinimumReductionReason $reason,
    ): HealthMinimumReductionInterval {
        return new HealthMinimumReductionInterval(
            '2026-08-01',
            '2026-08-31',
            $reason,
            'evidence:synthetic-' . $reason->value,
        );
    }

    /** @return array<string,int> */
    private function golden(string $scenario): array
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/Payroll/health-insurance-2026-golden.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey($scenario, $decoded);

        return $decoded[$scenario];
    }
}
