<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\SocialInsurance;

use MyInvoice\Service\Payroll\Calculation\PayrollRounding;
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
use MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountOutcome;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountReason;
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

    /**
     * § 5d zaokrouhluje vyměřovací základ nahoru na celé koruny JEŠTĚ PŘED
     * sazbou; § 7 odst. 3 zaokrouhluje až vypočtené pojistné. U firmy s jedinou
     * kategorií to není kosmetika: z 1 028,01 Kč vyjde 1 029 Kč a obě pojistná
     * jsou o korunu vyšší, než kdyby se počítalo z haléřů.
     */
    public function testSection5dRoundsTheAssessmentBaseUpBeforeAnyRate(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship(
                    'hpp',
                    SocialEmploymentKind::Employment,
                    4_500_000,
                    102_801,
                ),
            ]),
        ]);

        self::assertSame(SocialCalculationStatus::Calculated, $result->status);
        self::assertSame(102_900, $result->participatingAssessmentBaseMinorUnits);
        self::assertSame(102_900, $result->cappedAssessmentBaseMinorUnits);
        // 7,1 % z 1 029 Kč = 73,06 → 74 Kč; 24,8 % = 255,19 → 256 Kč.
        self::assertSame(7_400, $result->employeeContributionMinorUnits);
        self::assertSame(25_600, $result->employerContributionMinorUnits);
        // Bez § 5d: 7,1 % z 1 028,01 Kč = 72,99 → 73 Kč a 24,8 % = 254,95 → 255 Kč.
        self::assertNotSame(7_300, $result->employeeContributionMinorUnits);
        self::assertNotSame(25_500, $result->employerContributionMinorUnits);
    }

    /**
     * Jednotkou zaokrouhlení je zaměstnání, ne osoba: § 5 odst. 1 váže základ
     * na „zaměstnání, které zakládá účast", a § 7a odst. 3 mluví o „úhrnu
     * vyměřovacích základů zaměstnance ze všech zaměstnání". Dva vztahy po
     * 1 000,50 Kč proto dají 2 002 Kč, ne 2 001 Kč ze zaokrouhleného součtu.
     */
    public function testSection5dRoundsEachRelationshipSeparatelyNotThePersonTotal(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship(
                    'hpp',
                    SocialEmploymentKind::Employment,
                    4_500_000,
                    100_050,
                    allocationOrder: 1,
                ),
                $this->relationship(
                    'hpp-2',
                    SocialEmploymentKind::Employment,
                    4_500_000,
                    100_050,
                    allocationOrder: 2,
                ),
            ]),
        ]);

        self::assertSame(SocialCalculationStatus::Calculated, $result->status);
        self::assertSame(200_200, $result->participatingAssessmentBaseMinorUnits);
        self::assertNotSame(200_100, $result->participatingAssessmentBaseMinorUnits);
        self::assertSame(
            [100_100, 100_100],
            array_map(
                static fn (object $relationship): int =>
                    $relationship->assessmentBaseMinorUnits,
                $result->people[0]->relationships,
            ),
        );
    }

    /**
     * Vyměřovací základ zaměstnavatele podle § 5a odst. 1 je úhrn základů
     * podle § 5. Jsou-li ty už zaokrouhlené, je celými korunami i on — a to
     * v každé ze tří kategorií zvlášť.
     */
    public function testEmployerCategoryBasesAreWholeCzkWithoutRoundingThemAgain(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship('hpp', SocialEmploymentKind::Employment, 4_500_000, 1_000_001),
            ]),
            $this->person('person-2', [
                $this->relationship(
                    'risk-hpp',
                    SocialEmploymentKind::Employment,
                    4_500_000,
                    1_000_099,
                    employerRate: SocialEmployerRateCategory::RiskEmployment,
                ),
            ]),
        ]);

        self::assertSame(
            [['ordinary', 1_000_100], ['risk_employment', 1_000_100]],
            array_map(
                static fn (object $category): array => [
                    $category->category->value,
                    $category->assessmentBaseMinorUnits,
                ],
                $result->employerCategories,
            ),
        );
        foreach ($result->employerCategories as $category) {
            self::assertSame(0, $category->assessmentBaseMinorUnits % 100);
        }
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
        self::assertSame(
            SocialPartTimeDiscountOutcome::Applied,
            $result->people[0]->relationships[0]->partTimeEmployerDiscountOutcome,
        );
    }

    /**
     * § 7a odst. 3 písm. a) — úhrn vyměřovacích základů nad 1,5násobek průměrné
     * mzdy slevu vylučuje. 1,5 × 48 967 Kč = 73 450,50 → 73 451 Kč, takže
     * 73 452 Kč už nárok nemá, kdežto 73 451 Kč ano.
     */
    public function testDiscountIsNotDueAboveTheAssessmentBaseLimit(): void
    {
        $overLimit = $this->discountedPerson(7_345_200, assessableMillihours: 138_000);
        self::assertSame(0, $overLimit->partTimeDiscountMinorUnits);
        self::assertSame(0, $overLimit->partTimeDiscountAssessmentBaseMinorUnits);
        self::assertSame(
            SocialPartTimeDiscountOutcome::AssessmentBaseAboveLimit,
            $overLimit->people[0]->relationships[0]->partTimeEmployerDiscountOutcome,
        );

        $atLimit = $this->discountedPerson(7_345_100, assessableMillihours: 138_000);
        self::assertSame(367_300, $atLimit->partTimeDiscountMinorUnits);
        self::assertSame(
            SocialPartTimeDiscountOutcome::Applied,
            $atLimit->people[0]->relationships[0]->partTimeEmployerDiscountOutcome,
        );
    }

    /**
     * § 7a odst. 3 písm. b) — základ na hodinu nad 1,15 % průměrné mzdy.
     * 1,15 % ze 48 967 Kč = 563,12 → 564 Kč. Při 100 odpracovaných hodinách
     * je hranicí 56 400 Kč základu; o korunu víc už dá 565 Kč na hodinu.
     */
    public function testDiscountIsNotDueAboveTheHourlyAssessmentBaseLimit(): void
    {
        $overLimit = $this->discountedPerson(5_640_100, assessableMillihours: 100_000);
        self::assertSame(0, $overLimit->partTimeDiscountMinorUnits);
        self::assertSame(
            SocialPartTimeDiscountOutcome::HourlyAssessmentBaseAboveLimit,
            $overLimit->people[0]->relationships[0]->partTimeEmployerDiscountOutcome,
        );

        $atLimit = $this->discountedPerson(5_640_000, assessableMillihours: 100_000);
        self::assertSame(282_000, $atLimit->partTimeDiscountMinorUnits);
    }

    /**
     * § 7a odst. 3 písm. c) — odpracovaná doba nad 138 hodin. Trvalo-li
     * zaměstnání jen část měsíce, limit se krátí poměrem kalendářních dnů
     * a zaokrouhluje na celé hodiny nahoru: 138 × 15 / 31 = 66,77 → 67 hodin.
     */
    public function testDiscountIsNotDueAboveTheMonthlyHourLimitProRated(): void
    {
        $overLimit = $this->discountedPerson(1_000_000, assessableMillihours: 138_001);
        self::assertSame(0, $overLimit->partTimeDiscountMinorUnits);
        self::assertSame(
            SocialPartTimeDiscountOutcome::WorkedHoursAboveLimit,
            $overLimit->people[0]->relationships[0]->partTimeEmployerDiscountOutcome,
        );

        $atLimit = $this->discountedPerson(1_000_000, assessableMillihours: 138_000);
        self::assertSame(50_000, $atLimit->partTimeDiscountMinorUnits);

        $shortMonth = $this->discountedPerson(
            1_000_000,
            assessableMillihours: 67_001,
            employmentDays: 15,
        );
        self::assertSame(0, $shortMonth->partTimeDiscountMinorUnits);
        $withinShortMonth = $this->discountedPerson(
            1_000_000,
            assessableMillihours: 67_000,
            employmentDays: 15,
        );
        self::assertSame(50_000, $withinShortMonth->partTimeDiscountMinorUnits);
    }

    /**
     * § 7a odst. 2 — sjednaná kratší doba nejméně 8 a nejvíce 30 hodin týdně.
     * Pro zaměstnance mladšího 21 let podle odst. 1 písm. g) tahle podmínka
     * neplatí, takže mu sleva náleží i při plném úvazku.
     */
    public function testShorterWorkingTimeIsRequiredExceptForEmployeesUnder21(): void
    {
        $fullTime = $this->discountedPerson(1_000_000, weeklyMillihours: 40_000);
        self::assertSame(0, $fullTime->partTimeDiscountMinorUnits);
        self::assertSame(
            SocialPartTimeDiscountOutcome::ShorterWorkingTimeOutsideRange,
            $fullTime->people[0]->relationships[0]->partTimeEmployerDiscountOutcome,
        );

        $tooShort = $this->discountedPerson(1_000_000, weeklyMillihours: 7_999);
        self::assertSame(0, $tooShort->partTimeDiscountMinorUnits);

        $under21 = $this->discountedPerson(
            1_000_000,
            weeklyMillihours: 40_000,
            reason: SocialPartTimeDiscountReason::Under21,
        );
        self::assertSame(50_000, $under21->partTimeDiscountMinorUnits);
        self::assertSame(
            SocialPartTimeDiscountOutcome::Applied,
            $under21->people[0]->relationships[0]->partTimeEmployerDiscountOutcome,
        );
    }

    /**
     * Sjednaná týdenní doba není jen vstup posouzení § 7a odst. 2 — je to
     * jediný pramen položky 10373 měsíčního hlášení. Když ji výsledek nenese,
     * musela by ji příprava podání dopočítat odjinud.
     */
    public function testResultCarriesTheAgreedWeeklyWorkingTimeForTheReport(): void
    {
        $relationship = $this->discountedPerson(1_000_000, weeklyMillihours: 20_000)
            ->people[0]->relationships[0];

        self::assertSame(20_000, $relationship->agreedWeeklyWorkingMillihours);
        self::assertSame(
            20_000,
            $relationship->jsonSerialize()['agreed_weekly_working_millihours'],
        );
    }

    /**
     * § 7a odst. 2 věta druhá a odst. 3 se počítají z ÚHRNU za všechna
     * zaměstnání v pracovním poměru u téhož zaměstnavatele. Druhý vztah proto
     * nárok ovlivní, i když se sleva uplatňuje jen z jednoho z nich.
     */
    public function testLimitsAggregateAcrossAllEmploymentRelationshipsOfThePerson(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship(
                    'hpp-1',
                    SocialEmploymentKind::Employment,
                    4_500_000,
                    4_000_000,
                    partTimeDiscount: SocialDiscountEvidence::Verified,
                    allocationOrder: 1,
                    weeklyMillihours: 15_000,
                ),
                $this->relationship(
                    'hpp-2',
                    SocialEmploymentKind::Employment,
                    4_500_000,
                    3_500_000,
                    allocationOrder: 2,
                    weeklyMillihours: 15_000,
                ),
            ]),
        ]);

        // Úhrn 75 000 Kč přesáhne 73 451 Kč, ačkoli vybraný vztah má jen 40 000 Kč.
        self::assertSame(0, $result->partTimeDiscountMinorUnits);
        self::assertSame(
            SocialPartTimeDiscountOutcome::AssessmentBaseAboveLimit,
            $result->people[0]->relationships[0]->partTimeEmployerDiscountOutcome,
        );
    }

    /**
     * Chybějící odpracované hodiny nesmí slevu tiše přiznat: § 7c odst. 3 dělá
     * z přeplacené slevy dluh na pojistném, kdežto neuplatněná sleva žádný
     * nedoplatek nezakládá. Bez podkladu proto měsíc končí ručním posouzením.
     */
    public function testMissingWorkedHoursBlockTheMonthInsteadOfGrantingTheDiscount(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship(
                    'hpp',
                    SocialEmploymentKind::Employment,
                    4_500_000,
                    1_000_000,
                    partTimeDiscount: SocialDiscountEvidence::Verified,
                    workedHoursMissing: true,
                ),
            ]),
        ]);

        self::assertSame(SocialCalculationStatus::ManualReview, $result->status);
        self::assertContains(
            'person:person-1:relationship:hpp:part_time_discount_worked_hours_missing',
            $result->issues,
        );
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

    /**
     * Firma se dvěma kategoriemi § 5a odst. 1 současně.
     *
     * Dokud byl vyměřovací základ zaměstnavatele jeden, dala tahle firma
     * 24,8 % z celého úhrnu. Zákon ale říká 24,8 % z písm. a) a 27,8 %
     * z písm. c) — na téhle sestavě je to rozdíl 2 700 Kč.
     */
    public function testTwoRateCategoriesInOneMonthAreAssessedAndChargedSeparately(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship('hpp', SocialEmploymentKind::Employment, 4_500_000, 4_000_000),
            ]),
            $this->person('person-2', [
                $this->relationship(
                    'risk-hpp',
                    SocialEmploymentKind::Employment,
                    4_500_000,
                    9_000_000,
                    employerRate: SocialEmployerRateCategory::RiskEmployment,
                ),
            ]),
        ]);

        self::assertSame(SocialCalculationStatus::Calculated, $result->status);
        self::assertSame(13_000_000, $result->cappedAssessmentBaseMinorUnits);
        self::assertSame(
            [
                ['ordinary', 4_000_000, 992_000],
                ['risk_employment', 9_000_000, 2_502_000],
            ],
            array_map(
                static fn (object $category): array => [
                    $category->category->value,
                    $category->assessmentBaseMinorUnits,
                    $category->contributionMinorUnits,
                ],
                $result->employerCategories,
            ),
        );
        self::assertSame(3_494_000, $result->employerContributionBeforeDiscountMinorUnits);
        // Jediná sazba z celého úhrnu: 24,8 % ze 130 000 Kč = 32 240 Kč.
        self::assertNotSame(3_224_000, $result->employerContributionBeforeDiscountMinorUnits);
        // Jeden krok pro dvě sazby neexistuje a nesmí se předstírat.
        self::assertNull($result->employerContributionStep);
    }

    /**
     * § 7 odst. 3 zaokrouhluje nahoru pojistné — a to je podle § 7 odst. 1
     * částka Z KATEGORIE, ne jedna za firmu. Obě kategorie tu mají zlomek
     * haléřů, takže se nahoru zaokrouhlí dvakrát; kdyby se sčítalo před
     * zaokrouhlením, vyšla by o korunu nižší částka, než jakou ČSSZ počítá
     * kontrolami 8 a 167 nad JMHZ.
     */
    public function testEachRateCategoryIsRoundedUpOnItsOwn(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship('hpp', SocialEmploymentKind::Employment, 4_500_000, 1_000_100),
            ]),
            $this->person('person-2', [
                $this->relationship(
                    'risk-hpp',
                    SocialEmploymentKind::Employment,
                    4_500_000,
                    1_000_100,
                    employerRate: SocialEmployerRateCategory::RiskEmployment,
                ),
            ]),
        ]);

        // 24,8 % z 10 001,00 Kč = 2 480,25 → 2 481 Kč; 27,8 % = 2 780,28 → 2 781 Kč.
        self::assertSame(
            [248_100, 278_100],
            array_map(
                static fn (object $category): int => $category->contributionMinorUnits,
                $result->employerCategories,
            ),
        );
        self::assertSame(526_200, $result->employerContributionBeforeDiscountMinorUnits);
        // Zaokrouhlení až ze součtu: 52 % z 10 001 Kč = 5 260,52 → 5 261 Kč.
        self::assertNotSame(526_100, $result->employerContributionBeforeDiscountMinorUnits);
    }

    /**
     * Kategorie, do které v měsíci nespadl žádný vztah, se nevykazuje vůbec —
     * ne jako nulový řádek. Nulový základ by v podání ČSSZ znamenal, že
     * zaměstnavatel takové zaměstnance má a nic za ně neodvedl.
     */
    public function testCategoryWithoutAnyRelationshipIsNotReportedAtAll(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship(
                    'rescue-hpp',
                    SocialEmploymentKind::Employment,
                    4_500_000,
                    5_000_000,
                    employerRate: SocialEmployerRateCategory::RescueAndCompanyFireService,
                ),
            ]),
        ]);

        self::assertCount(1, $result->employerCategories);
        self::assertSame(
            SocialEmployerRateCategory::RescueAndCompanyFireService,
            $result->employerCategories[0]->category,
        );
        self::assertSame('b', $result->employerCategories[0]->jsonSerialize()['paragraph5a_letter']);
        // 29,8 % z 50 000 Kč — sazba písm. b) počínaje rokem 2026.
        self::assertSame(1_490_000, $result->employerContributionBeforeDiscountMinorUnits);
    }

    /**
     * Jedna osoba, dva vztahy, dvě kategorie. § 5a rozlišuje podle vztahu, ne
     * podle osoby: osobní úhrn by celou částku hodil do jedné sazby.
     */
    public function testOnePersonSplitsAcrossCategoriesByRelationship(): void
    {
        $result = $this->calculate([
            $this->person('person-1', [
                $this->relationship('hpp', SocialEmploymentKind::Employment, 4_500_000, 3_000_000),
                $this->relationship(
                    'risk-hpp',
                    SocialEmploymentKind::Employment,
                    4_500_000,
                    2_000_000,
                    employerRate: SocialEmployerRateCategory::RiskEmployment,
                ),
            ]),
        ]);

        self::assertSame(
            ['ordinary' => 3_000_000, 'risk_employment' => 2_000_000],
            array_column(
                array_map(
                    static fn (object $category): array => [
                        $category->category->value,
                        $category->assessmentBaseMinorUnits,
                    ],
                    $result->employerCategories,
                ),
                1,
                0,
            ),
        );
        self::assertSame(744_000 + 556_000, $result->employerContributionBeforeDiscountMinorUnits);
    }

    public function testFailsClosedForUnverifiedDiscountAndUnverifiedEmployerRateCategory(): void
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
                        employerRate: SocialEmployerRateCategory::Unverified,
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
            'person:person-1:relationship:risk-hpp:employer_rate_category_unverified',
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

            $expectedCap = min(PayrollRounding::ceilToCzk($base), $maximum - $yearToDate);
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

    /**
     * Osoba s jedním doloženým nárokem podle § 7a. Výchozí hodnoty nárok
     * splňují, takže každý test mění právě tu veličinu, kterou zkoumá.
     */
    private function discountedPerson(
        int $amount,
        ?int $assessableMillihours = null,
        ?int $weeklyMillihours = null,
        ?int $employmentDays = null,
        ?SocialPartTimeDiscountReason $reason = null,
    ): \MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthResult {
        return $this->calculate([
            $this->person('person-1', [
                $this->relationship(
                    'hpp',
                    SocialEmploymentKind::Employment,
                    4_500_000,
                    $amount,
                    partTimeDiscount: SocialDiscountEvidence::Verified,
                    partTimeReason: $reason,
                    assessableMillihours: $assessableMillihours,
                    weeklyMillihours: $weeklyMillihours,
                    employmentDays: $employmentDays,
                ),
            ]),
        ]);
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
        ?SocialPartTimeDiscountReason $partTimeReason = null,
        ?int $assessableMillihours = null,
        ?int $weeklyMillihours = null,
        ?int $employmentDays = null,
        ?int $monthDays = null,
        bool $workedHoursMissing = false,
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
            in_array(
                $employerRate,
                [SocialEmployerRateCategory::Ordinary, SocialEmployerRateCategory::Unverified],
                true,
            )
                ? null
                : "synthetic:rate-category:{$id}",
            $partTimeDiscount === SocialDiscountEvidence::Verified
                ? ($partTimeReason ?? SocialPartTimeDiscountReason::Age55Plus)
                : null,
            $kind === SocialEmploymentKind::Employment && !$workedHoursMissing
                ? ($assessableMillihours ?? 100_000)
                : $assessableMillihours,
            $kind === SocialEmploymentKind::Employment ? ($employmentDays ?? 31) : $employmentDays,
            $kind === SocialEmploymentKind::Employment ? ($monthDays ?? 31) : $monthDays,
            $kind === SocialEmploymentKind::Employment
                ? ($weeklyMillihours ?? 20_000)
                : $weeklyMillihours,
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
