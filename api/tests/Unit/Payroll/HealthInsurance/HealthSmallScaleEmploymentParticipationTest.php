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
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthResult;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceRelationshipInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsurerSnapshotStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthJurisdictionEvidence;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpEmployerSelection;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibility;
use MyInvoice\Service\Payroll\HealthInsurance\HealthParticipationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthPersonMonthInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthRelationshipKindMapper;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Tests\Fixtures\Payroll\ActivePayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

/**
 * Zaměstnání malého rozsahu (§ 7 zákona č. 187/2006 Sb.) je pojem nemocenského,
 * a tedy sociálního pojištění. Zdravotní pojištění jej nezná: pracovní poměr je
 * podle § 5 písm. a) zákona č. 48/1997 Sb. zaměstnáním bez příjmové podmínky a
 * příjmový limit mají jen taxativně vyjmenované výjimky (DPP, DPČ, člen
 * družstva, dobrovolný pracovník pečovatelské služby). Nízký příjem se proto
 * ve zdravotním pojištění neřeší vyloučením z účasti, ale doplatkem do
 * minimálního vyměřovacího základu.
 *
 * Tenhle test tu odlišnost zamyká, aby se sociální práh 4 500 Kč nepřenesl do
 * zdravotní domény jako „chybějící podmínka“.
 */
final class HealthSmallScaleEmploymentParticipationTest extends TestCase
{
    public function testSmallScaleEmploymentIsAnOrdinaryHealthEmployment(): void
    {
        self::assertSame(
            HealthEmploymentKind::Employment,
            (new HealthRelationshipKindMapper())
                ->fromDatabaseRelationType('small_scale_employment'),
        );
    }

    public function testIncomeBelowSocialSmallScaleThresholdStillParticipates(): void
    {
        $result = $this->calculate(300_000);

        self::assertSame(HealthCalculationStatus::Calculated, $result->status);
        self::assertSame(
            HealthParticipationStatus::Participates,
            $result->people[0]->relationships[0]->participation->status,
        );
        self::assertSame(['dependent_income_relationship'], $result->people[0]
            ->relationships[0]->participation->reasonCodes);
        self::assertNull($result->people[0]->relationships[0]->participation->thresholdMinorUnits);
        self::assertSame(300_000, $result->assessmentBaseMinorUnits);
    }

    public function testLowIncomeIsSettledByMinimumTopUpInsteadOfLosingParticipation(): void
    {
        $result = $this->calculate(300_000);
        $person = $result->people[0];

        self::assertSame(2_240_000, $person->effectiveMinimumMinorUnits);
        self::assertSame(261_900, $person->employeeMinimumTopUpMinorUnits);
        self::assertSame(0, $person->employerMinimumTopUpMinorUnits);
        self::assertSame(13_500, $person->employeeStandardContributionMinorUnits);
        self::assertSame(27_000, $person->employerStandardContributionMinorUnits);
        self::assertSame(302_400, $result->totalContributionMinorUnits);
        self::assertTrue($person->ppzCounted);
        self::assertCount(1, $result->insurerLiabilities);
        self::assertSame(302_400, $result->insurerLiabilities[0]->totalContributionMinorUnits);
    }

    public function testIncomeAtSocialSmallScaleThresholdBehavesIdentically(): void
    {
        $below = $this->calculate(449_900);
        $atThreshold = $this->calculate(450_000);

        self::assertSame(
            $below->people[0]->relationships[0]->participation->status,
            $atThreshold->people[0]->relationships[0]->participation->status,
        );
        self::assertSame(
            HealthParticipationStatus::Participates,
            $below->people[0]->relationships[0]->participation->status,
        );
        self::assertSame(449_900, $below->assessmentBaseMinorUnits);
        self::assertSame(450_000, $atThreshold->assessmentBaseMinorUnits);
    }

    private function calculate(int $wageMinorUnits): HealthInsuranceMonthResult
    {
        $relationship = new HealthInsuranceRelationshipInput(
            'zmr-1',
            (new HealthRelationshipKindMapper())
                ->fromDatabaseRelationType('small_scale_employment'),
            '2026-08-01',
            null,
            HealthIncomeAttribution::CurrentEmploymentMonth,
            [
                new HealthAssessmentComponent(
                    'wage',
                    $wageMinorUnits,
                    HealthComponentTreatment::Included,
                    HealthComponentTreatment::Included,
                    HealthCorrectionTreatment::CurrentMonth,
                ),
            ],
        );
        $person = new HealthPersonMonthInput(
            'person-zmr',
            HealthJurisdictionEvidence::CzechRegimeVerified,
            null,
            HealthInsurerSnapshotStatus::Verified,
            '111',
            'insurer:synthetic-snapshot',
            [$relationship],
            [],
            [],
            HealthMinimumTopUpResponsibility::Employee,
            null,
            null,
            HealthMinimumTopUpEmployerSelection::Unverified,
        );

        return (new HealthInsuranceMonthCalculator(
            ActivePayrollRulesetFixture::provider(PayrollRulesetDomain::HealthInsurance),
        ))->calculate(new HealthInsuranceMonthInput('2026-08-31', [$person]));
    }
}
