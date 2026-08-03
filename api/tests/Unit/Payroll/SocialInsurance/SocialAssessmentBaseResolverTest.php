<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\SocialInsurance;

use MyInvoice\Service\Payroll\SocialInsurance\SocialAssessmentBaseResolver;
use MyInvoice\Service\Payroll\SocialInsurance\SocialAssessmentComponent;
use MyInvoice\Service\Payroll\SocialInsurance\SocialComponentTreatment;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmploymentKind;
use MyInvoice\Service\Payroll\SocialInsurance\SocialIncomeAttribution;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceRelationshipInput;
use PHPUnit\Framework\TestCase;

final class SocialAssessmentBaseResolverTest extends TestCase
{
    public function testClassifiesParticipationAndAssessmentBaseIndependently(): void
    {
        $facts = (new SocialAssessmentBaseResolver())->resolve(
            $this->relationship([
                new SocialAssessmentComponent(
                    'wage',
                    4_000_000,
                    SocialComponentTreatment::Included,
                    SocialComponentTreatment::Included,
                ),
                new SocialAssessmentComponent(
                    'statutory_exclusion',
                    100_000,
                    SocialComponentTreatment::Excluded,
                    SocialComponentTreatment::Excluded,
                ),
                new SocialAssessmentComponent(
                    'participation_only',
                    50_000,
                    SocialComponentTreatment::Included,
                    SocialComponentTreatment::Excluded,
                ),
            ]),
        );

        self::assertSame(4_050_000, $facts->participationIncomeMinorUnits);
        self::assertSame(4_000_000, $facts->assessmentBaseMinorUnits);
        self::assertSame(['participation_only', 'wage'], $facts->includedParticipationComponents);
        self::assertSame(
            ['participation_only', 'statutory_exclusion'],
            $facts->excludedAssessmentBaseComponents,
        );
        self::assertSame([], $facts->issues);
    }

    public function testSupportsNegativeCorrectionWithinPositiveMonthlyBase(): void
    {
        $facts = (new SocialAssessmentBaseResolver())->resolve(
            $this->relationship([
                $this->included('wage', 500_000),
                $this->included('prior_correction', -50_000),
            ]),
        );

        self::assertSame(450_000, $facts->participationIncomeMinorUnits);
        self::assertSame(450_000, $facts->assessmentBaseMinorUnits);
        self::assertSame([], $facts->issues);
    }

    public function testFailsClosedForNegativeResultAndUnclassifiedComponent(): void
    {
        $facts = (new SocialAssessmentBaseResolver())->resolve(
            $this->relationship([
                $this->included('prior_correction', -50_000),
                new SocialAssessmentComponent(
                    'unknown_benefit',
                    10_000,
                    SocialComponentTreatment::ManualReview,
                    SocialComponentTreatment::ManualReview,
                ),
            ]),
        );

        self::assertContains(
            'negative_participation_income_requires_period_revision',
            $facts->issues,
        );
        self::assertContains(
            'negative_assessment_base_requires_period_revision',
            $facts->issues,
        );
        self::assertContains(
            'participation_component_manual_review:unknown_benefit',
            $facts->issues,
        );
        self::assertContains(
            'assessment_component_manual_review:unknown_benefit',
            $facts->issues,
        );
    }

    /** @param non-empty-list<SocialAssessmentComponent> $components */
    private function relationship(array $components): SocialInsuranceRelationshipInput
    {
        return new SocialInsuranceRelationshipInput(
            'relationship-1',
            SocialEmploymentKind::Employment,
            450_000,
            true,
            SocialIncomeAttribution::CurrentEmploymentMonth,
            $components,
        );
    }

    private function included(string $code, int $amount): SocialAssessmentComponent
    {
        return new SocialAssessmentComponent(
            $code,
            $amount,
            SocialComponentTreatment::Included,
            SocialComponentTreatment::Included,
        );
    }
}
