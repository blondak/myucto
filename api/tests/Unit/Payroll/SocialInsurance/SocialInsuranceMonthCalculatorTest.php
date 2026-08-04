<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\SocialInsurance;

use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\SocialInsurance\SocialAssessmentComponent;
use MyInvoice\Service\Payroll\SocialInsurance\SocialCalculationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialComponentTreatment;
use MyInvoice\Service\Payroll\SocialInsurance\SocialDiscountEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmployerRateCategory;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmploymentKind;
use MyInvoice\Service\Payroll\SocialInsurance\SocialIncomeAttribution;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthCalculator;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceRelationshipInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialJurisdictionEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationAggregationGroup;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPersonMonthInput;
use MyInvoice\Tests\Fixtures\Payroll\ActivePayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

final class SocialInsuranceMonthCalculatorTest extends TestCase
{
    public function testCalculatesOrdinaryHppEmployeeAndEmployerContribution(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship(
                    'hpp',
                    SocialEmploymentKind::Employment,
                    4_500_000,
                    4_790_000,
                ),
            ]),
        ]);

        self::assertSame(SocialCalculationStatus::Calculated, $result->status);
        self::assertSame(4_790_000, $result->cappedAssessmentBaseMinorUnits);
        self::assertSame(340_100, $result->employeeContributionMinorUnits);
        self::assertSame(1_188_000, $result->employerContributionMinorUnits);
        self::assertSame(
            SocialParticipationStatus::Participates,
            $result->people[0]->relationships[0]->participation->status,
        );
    }

    public function testRegularEmploymentDoesNotBecomeSmallScaleWhenAgreedIncomeIsMissing(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship(
                    'regular-employment',
                    SocialEmploymentKind::Employment,
                    null,
                    100_000,
                ),
            ]),
        ]);

        self::assertSame(SocialCalculationStatus::Calculated, $result->status);
        self::assertSame(
            SocialParticipationStatus::Participates,
            $result->people[0]->relationships[0]->participation->status,
        );
        self::assertSame(
            ['regular_relationship'],
            $result->people[0]->relationships[0]->participation->reasonCodes,
        );
    }

    public function testAggregatesDppThresholdBeforeCalculatingContributions(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship('dpp-a', SocialEmploymentKind::Dpp, null, 600_000),
                $this->relationship('dpp-b', SocialEmploymentKind::Dpp, null, 600_000),
            ]),
        ]);

        self::assertSame(1_200_000, $result->cappedAssessmentBaseMinorUnits);
        self::assertSame(85_200, $result->employeeContributionMinorUnits);
        self::assertSame(297_600, $result->employerContributionMinorUnits);
    }

    public function testExplicitSmallScaleEmploymentsShareTheirParticipationThreshold(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship(
                    'small-a',
                    SocialEmploymentKind::Employment,
                    null,
                    225_000,
                    aggregationGroup:
                        SocialParticipationAggregationGroup::SmallScaleCandidate,
                ),
                $this->relationship(
                    'small-b',
                    SocialEmploymentKind::Employment,
                    null,
                    225_000,
                    aggregationGroup:
                        SocialParticipationAggregationGroup::SmallScaleCandidate,
                ),
            ]),
        ]);

        self::assertSame(SocialCalculationStatus::Calculated, $result->status);
        self::assertSame(450_000, $result->participatingAssessmentBaseMinorUnits);
        self::assertSame(
            [
                SocialParticipationStatus::Participates,
                SocialParticipationStatus::Participates,
            ],
            array_map(
                static fn ($relationship): SocialParticipationStatus =>
                    $relationship->participation->status,
                $result->people[0]->relationships,
            ),
        );
    }

    public function testAppliesAnnualMaximumOncePerPersonAcrossRelationships(): void
    {
        $annualMaximum = 235_041_600;
        $result = $this->calculate([
            $this->person(
                'person-1',
                [
                    $this->relationship(
                        'a-hpp',
                        SocialEmploymentKind::Employment,
                        450_000,
                        80_000,
                        allocationOrder: 1,
                    ),
                    $this->relationship(
                        'b-dpc',
                        SocialEmploymentKind::Dpc,
                        450_000,
                        80_000,
                        allocationOrder: 2,
                    ),
                ],
                $annualMaximum - 100_000,
            ),
        ]);

        self::assertSame(100_000, $result->cappedAssessmentBaseMinorUnits);
        self::assertSame(80_000, $result->people[0]->relationships[0]->cappedAssessmentBaseMinorUnits);
        self::assertSame(20_000, $result->people[0]->relationships[1]->cappedAssessmentBaseMinorUnits);
        self::assertSame(7_100, $result->employeeContributionMinorUnits);
        self::assertSame(24_800, $result->employerContributionMinorUnits);
    }

    public function testAnnualMaximumWithMultipleRelationshipsRequiresExplicitAllocationOrder(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [
                    $this->relationship(
                        'hpp',
                        SocialEmploymentKind::Employment,
                        450_000,
                        100_000,
                    ),
                    $this->relationship(
                        'dpc',
                        SocialEmploymentKind::Dpc,
                        450_000,
                        100_000,
                    ),
                ],
                235_041_600 - 100_000,
            ),
        ]);

        self::assertSame(SocialCalculationStatus::ManualReview, $result->status);
        self::assertContains(
            'person:person-1:annual_maximum_relationship_allocation_order_required',
            $result->issues,
        );
        self::assertNull($result->employerContributionMinorUnits);
    }

    public function testAnnualMaximumIsIndependentForDifferentPeople(): void
    {
        $annualMaximum = 235_041_600;
        $result = $this->calculate([
            $this->person(
                'person-a',
                [$this->relationship('hpp-a', SocialEmploymentKind::Employment, 450_000, 100_000)],
                $annualMaximum - 50_000,
            ),
            $this->person(
                'person-b',
                [$this->relationship('hpp-b', SocialEmploymentKind::Employment, 450_000, 100_000)],
                $annualMaximum - 50_000,
            ),
        ]);

        self::assertSame(100_000, $result->cappedAssessmentBaseMinorUnits);
        self::assertSame(7_200, $result->employeeContributionMinorUnits);
        self::assertSame(24_800, $result->employerContributionMinorUnits);
    }

    public function testAppliesWorkingPensionerAndVerifiedPartTimeDiscounts(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [
                    $this->relationship(
                        'discounted-hpp',
                        SocialEmploymentKind::Employment,
                        450_000,
                        4_790_000,
                        partTimeDiscount: SocialDiscountEvidence::Verified,
                    ),
                ],
                workingPensioner: SocialDiscountEvidence::Verified,
            ),
        ]);

        self::assertSame(340_100, $result->people[0]->employeeContributionBeforeDiscountMinorUnits);
        self::assertSame(311_400, $result->people[0]->workingPensionerDiscountMinorUnits);
        self::assertSame(28_700, $result->employeeContributionMinorUnits);
        self::assertSame(4_790_000, $result->partTimeDiscountAssessmentBaseMinorUnits);
        self::assertSame(239_500, $result->partTimeDiscountMinorUnits);
        self::assertSame(948_500, $result->employerContributionMinorUnits);
    }

    public function testPartTimeDiscountUsesOnlySelectedRelationshipBase(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship(
                    'discounted',
                    SocialEmploymentKind::Employment,
                    450_000,
                    1_000_000,
                    partTimeDiscount: SocialDiscountEvidence::Verified,
                ),
                $this->relationship(
                    'ordinary',
                    SocialEmploymentKind::Dpc,
                    450_000,
                    2_000_000,
                ),
            ]),
        ]);

        self::assertSame(3_000_000, $result->cappedAssessmentBaseMinorUnits);
        self::assertSame(1_000_000, $result->partTimeDiscountAssessmentBaseMinorUnits);
        self::assertSame(50_000, $result->partTimeDiscountMinorUnits);
        self::assertSame(694_000, $result->employerContributionMinorUnits);
    }

    public function testExcludedComponentIsVisibleButNotInAssessmentBase(): void
    {
        $relationship = new SocialInsuranceRelationshipInput(
            'hpp',
            SocialEmploymentKind::Employment,
            450_000,
            true,
            SocialIncomeAttribution::CurrentEmploymentMonth,
            [
                $this->component('wage', 1_000_000),
                new SocialAssessmentComponent(
                    'excluded_reimbursement',
                    100_000,
                    SocialComponentTreatment::Excluded,
                    SocialComponentTreatment::Excluded,
                ),
            ],
        );
        $result = $this->calculate([$this->person('person-1', [$relationship])]);

        self::assertSame(1_000_000, $result->cappedAssessmentBaseMinorUnits);
        self::assertSame(
            ['excluded_reimbursement'],
            $result->people[0]->relationships[0]->excludedAssessmentBaseComponents,
        );
    }

    public function testVerifiedForeignRegimeProducesNoCzechContribution(): void
    {
        $result = $this->calculate([
            $this->person(
                'foreign-person',
                [$this->relationship(
                    'foreign-hpp',
                    SocialEmploymentKind::Employment,
                    450_000,
                    5_000_000,
                )],
                jurisdiction: SocialJurisdictionEvidence::ForeignRegimeVerified,
            ),
        ]);

        self::assertSame(SocialCalculationStatus::Calculated, $result->status);
        self::assertSame(0, $result->cappedAssessmentBaseMinorUnits);
        self::assertSame(0, $result->employeeContributionMinorUnits);
        self::assertSame(0, $result->employerContributionMinorUnits);
        self::assertSame(
            ['foreign_social_security_regime_verified'],
            $result->people[0]->relationships[0]->participation->reasonCodes,
        );
    }

    public function testFailsClosedForUnverifiedJurisdiction(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [$this->relationship(
                    'hpp',
                    SocialEmploymentKind::Employment,
                    450_000,
                    1_000_000,
                )],
                jurisdiction: SocialJurisdictionEvidence::Unverified,
            ),
        ]);

        self::assertSame(SocialCalculationStatus::ManualReview, $result->status);
        self::assertNull($result->employeeContributionMinorUnits);
        self::assertNull($result->employerContributionMinorUnits);
        self::assertContains(
            'person:person-1:social_security_jurisdiction_unverified',
            $result->issues,
        );
    }

    public function testVerifiedForeignRegimeRequiresEvidenceReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Verified foreign social insurance regime requires an evidence reference.',
        );

        new SocialPersonMonthInput(
            'person-1',
            SocialJurisdictionEvidence::ForeignRegimeVerified,
            0,
            [$this->relationship(
                'hpp',
                SocialEmploymentKind::Employment,
                450_000,
                1_000_000,
            )],
        );
    }

    public function testVerifiedEmployerDiscountRequiresEvidenceReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Part-time employer discount verification requires an evidence reference.',
        );

        new SocialInsuranceRelationshipInput(
            'hpp',
            SocialEmploymentKind::Employment,
            450_000,
            true,
            SocialIncomeAttribution::CurrentEmploymentMonth,
            [$this->component('wage', 1_000_000)],
            SocialDiscountEvidence::Verified,
        );
    }

    public function testFailsClosedForUnverifiedDiscountAndSpecialEmployerRate(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-1',
                [
                    $this->relationship(
                        'risk-hpp',
                        SocialEmploymentKind::Employment,
                        450_000,
                        1_000_000,
                        partTimeDiscount: SocialDiscountEvidence::Unverified,
                        employerRate: SocialEmployerRateCategory::RiskEmployment,
                    ),
                ],
                workingPensioner: SocialDiscountEvidence::Unverified,
            ),
        ]);

        self::assertSame(SocialCalculationStatus::ManualReview, $result->status);
        self::assertContains(
            'person:person-1:relationship:risk-hpp:part_time_discount_unverified',
            $result->issues,
        );
        self::assertContains(
            'person:person-1:relationship:risk-hpp:employer_rate_category_requires_manual_review',
            $result->issues,
        );
        self::assertContains(
            'person:person-1:working_pensioner_discount_unverified',
            $result->issues,
        );
    }

    public function testFailsClosedForAgricultureDppDiscountAndDuplicatePartTimeClaim(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship(
                    'dpp',
                    SocialEmploymentKind::Dpp,
                    null,
                    1_200_000,
                    partTimeDiscount: SocialDiscountEvidence::Verified,
                    agricultureDiscountRequested: true,
                ),
                $this->relationship(
                    'hpp',
                    SocialEmploymentKind::Employment,
                    450_000,
                    1_000_000,
                    partTimeDiscount: SocialDiscountEvidence::Verified,
                ),
            ]),
        ]);

        self::assertSame(SocialCalculationStatus::ManualReview, $result->status);
        self::assertContains(
            'person:person-1:relationship:dpp:agriculture_dpp_discount_requires_manual_review',
            $result->issues,
        );
        self::assertContains(
            'person:person-1:relationship:dpp:part_time_discount_relationship_kind_unsupported',
            $result->issues,
        );
        self::assertContains(
            'person:person-1:part_time_discount_may_select_only_one_relationship_per_person',
            $result->issues,
        );
    }

    public function testCanonicalOutputDoesNotDependOnInputOrder(): void
    {
        $personA = $this->person('a', [
            $this->relationship('a-2', SocialEmploymentKind::Dpc, 450_000, 200_000),
            $this->relationship('a-1', SocialEmploymentKind::Employment, 450_000, 100_000),
        ]);
        $personB = $this->person('b', [
            $this->relationship('b-1', SocialEmploymentKind::Dpp, null, 1_200_000),
        ]);

        $forward = $this->calculate([$personA, $personB])->toCanonicalJson();
        $reverse = $this->calculate([$personB, $personA])->toCanonicalJson();

        self::assertSame($forward, $reverse);
    }

    public function testMatchesSynthetic2026GoldenScenario(): void
    {
        $result = $this->calculate([
            $this->person(
                'person-a',
                [$this->relationship(
                    'hpp-a',
                    SocialEmploymentKind::Employment,
                    450_000,
                    4_790_000,
                    partTimeDiscount: SocialDiscountEvidence::Verified,
                )],
                workingPensioner: SocialDiscountEvidence::Verified,
            ),
            $this->person('person-b', [
                $this->relationship('dpp-b1', SocialEmploymentKind::Dpp, null, 600_000),
                $this->relationship('dpp-b2', SocialEmploymentKind::Dpp, null, 600_000),
            ]),
        ]);
        $projection = [
            'ruleset_id' => $result->rulesetId,
            'status' => $result->status->value,
            'participating_base' => $result->participatingAssessmentBaseMinorUnits,
            'capped_base' => $result->cappedAssessmentBaseMinorUnits,
            'employee_contribution' => $result->employeeContributionMinorUnits,
            'employer_before_discount' =>
                $result->employerContributionBeforeDiscountMinorUnits,
            'part_time_discount_base' => $result->partTimeDiscountAssessmentBaseMinorUnits,
            'part_time_discount' => $result->partTimeDiscountMinorUnits,
            'employer_contribution' => $result->employerContributionMinorUnits,
            'people' => array_map(
                static fn ($person): array => [
                    'person_id' => $person->personId,
                    'capped_base' => $person->cappedAssessmentBaseMinorUnits,
                    'employee_before_discount' =>
                        $person->employeeContributionBeforeDiscountMinorUnits,
                    'working_pensioner_discount' =>
                        $person->workingPensionerDiscountMinorUnits,
                    'employee_contribution' => $person->employeeContributionMinorUnits,
                    'relationships' => array_map(
                        static fn ($relationship): array => [
                            'id' => $relationship->relationshipId,
                            'participation' => $relationship->participation->status->value,
                            'group_income' =>
                                $relationship->participation->groupIncomeMinorUnits,
                            'threshold' => $relationship->participation->thresholdMinorUnits,
                            'reasons' => $relationship->participation->reasonCodes,
                            'capped_base' =>
                                $relationship->cappedAssessmentBaseMinorUnits,
                        ],
                        $person->relationships,
                    ),
                ],
                $result->people,
            ),
        ];
        $fixture = file_get_contents(
            dirname(__DIR__, 3) . '/Fixtures/Payroll/social-insurance-2026-golden.json',
        );
        self::assertIsString($fixture);

        self::assertSame(
            json_decode($fixture, true, flags: JSON_THROW_ON_ERROR),
            $projection,
        );
    }

    public function testDeterministicPropertySweepHonoursAnnualMaximumAndNonNegativeResults(): void
    {
        $maximum = 235_041_600;
        $state = 17;
        for ($i = 0; $i < 250; $i++) {
            $state = (int) (($state * 48_271) % 2_147_483_647);
            $base = $state % 10_000_001;
            $state = (int) (($state * 48_271) % 2_147_483_647);
            $yearToDate = $state % ($maximum + 1);
            $result = $this->calculate([
                $this->person(
                    "person-{$i}",
                    [$this->relationship(
                        "hpp-{$i}",
                        SocialEmploymentKind::Employment,
                        450_000,
                        $base,
                    )],
                    $yearToDate,
                ),
            ]);

            $expectedCap = min($base, $maximum - $yearToDate);
            self::assertSame($expectedCap, $result->cappedAssessmentBaseMinorUnits);
            self::assertGreaterThanOrEqual(0, $result->employeeContributionMinorUnits);
            self::assertGreaterThanOrEqual(0, $result->employerContributionMinorUnits);
            self::assertSame(
                $result->toCanonicalJson(),
                $this->calculate([
                    $this->person(
                        "person-{$i}",
                        [$this->relationship(
                            "hpp-{$i}",
                            SocialEmploymentKind::Employment,
                            450_000,
                            $base,
                        )],
                        $yearToDate,
                    ),
                ])->toCanonicalJson(),
            );
        }
    }

    /** @param list<SocialPersonMonthInput> $people */
    private function calculate(array $people): \MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthResult
    {
        return $this->calculator()->calculate(
            new SocialInsuranceMonthInput('2026-08-03', $people),
        );
    }

    private function calculator(): SocialInsuranceMonthCalculator
    {
        return new SocialInsuranceMonthCalculator(
            ActivePayrollRulesetFixture::provider(PayrollRulesetDomain::SocialInsurance),
        );
    }

    /**
     * @param non-empty-list<SocialInsuranceRelationshipInput> $relationships
     */
    private function person(
        string $id,
        array $relationships,
        int $yearToDate = 0,
        SocialJurisdictionEvidence $jurisdiction =
            SocialJurisdictionEvidence::CzechRegimeVerified,
        SocialDiscountEvidence $workingPensioner = SocialDiscountEvidence::NotClaimed,
    ): SocialPersonMonthInput {
        return new SocialPersonMonthInput(
            $id,
            $jurisdiction,
            $yearToDate,
            $relationships,
            $workingPensioner,
            $jurisdiction === SocialJurisdictionEvidence::ForeignRegimeVerified
                ? "synthetic:a1:{$id}"
                : null,
            $workingPensioner === SocialDiscountEvidence::Verified
                ? "synthetic:pension:{$id}"
                : null,
        );
    }

    private function relationship(
        string $id,
        SocialEmploymentKind $kind,
        ?int $agreedIncome,
        int $amount,
        SocialDiscountEvidence $partTimeDiscount = SocialDiscountEvidence::NotClaimed,
        SocialEmployerRateCategory $employerRate = SocialEmployerRateCategory::Ordinary,
        bool $agricultureDiscountRequested = false,
        ?int $allocationOrder = null,
        ?SocialParticipationAggregationGroup $aggregationGroup = null,
    ): SocialInsuranceRelationshipInput {
        return new SocialInsuranceRelationshipInput(
            $id,
            $kind,
            $agreedIncome,
            true,
            SocialIncomeAttribution::CurrentEmploymentMonth,
            [$this->component('wage', $amount)],
            $partTimeDiscount,
            $employerRate,
            $agricultureDiscountRequested,
            $allocationOrder,
            $partTimeDiscount === SocialDiscountEvidence::Verified
                ? "synthetic:part-time:{$id}"
                : null,
            $aggregationGroup,
        );
    }

    private function component(string $code, int $amount): SocialAssessmentComponent
    {
        return new SocialAssessmentComponent(
            $code,
            $amount,
            SocialComponentTreatment::Included,
            SocialComponentTreatment::Included,
        );
    }
}
