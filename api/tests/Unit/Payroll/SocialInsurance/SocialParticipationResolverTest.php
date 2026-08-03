<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\SocialInsurance;

use MyInvoice\Service\Payroll\SocialInsurance\SocialAssessmentBaseResolver;
use MyInvoice\Service\Payroll\SocialInsurance\SocialAssessmentComponent;
use MyInvoice\Service\Payroll\SocialInsurance\SocialComponentTreatment;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmploymentKind;
use MyInvoice\Service\Payroll\SocialInsurance\SocialIncomeAttribution;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceRelationshipInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationResolver;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialParticipationResolverTest extends TestCase
{
    #[DataProvider('dppBoundaryProvider')]
    public function testDppParticipationBoundary(
        int $incomeMinorUnits,
        SocialParticipationStatus $expected,
    ): void {
        $decision = $this->resolve([
            $this->relationship('dpp', SocialEmploymentKind::Dpp, null, $incomeMinorUnits),
        ])['dpp'];

        self::assertSame($expected, $decision->status);
        self::assertSame(1_200_000, $decision->thresholdMinorUnits);
    }

    /** @return iterable<string,array{int,SocialParticipationStatus}> */
    public static function dppBoundaryProvider(): iterable
    {
        yield 'one haler below' => [
            1_199_999,
            SocialParticipationStatus::DoesNotParticipate,
        ];
        yield 'exactly threshold' => [1_200_000, SocialParticipationStatus::Participates];
        yield 'one haler above' => [1_200_001, SocialParticipationStatus::Participates];
    }

    public function testAggregatesMultipleDppRelationshipsAtSameEmployer(): void
    {
        $decisions = $this->resolve([
            $this->relationship('dpp-a', SocialEmploymentKind::Dpp, null, 600_000),
            $this->relationship('dpp-b', SocialEmploymentKind::Dpp, null, 600_000),
        ]);

        self::assertSame(SocialParticipationStatus::Participates, $decisions['dpp-a']->status);
        self::assertSame(SocialParticipationStatus::Participates, $decisions['dpp-b']->status);
        self::assertSame(1_200_000, $decisions['dpp-a']->groupIncomeMinorUnits);
    }

    public function testAggregatesSmallScaleEmploymentDpcAndCorporateBody(): void
    {
        $decisions = $this->resolve([
            $this->relationship('hpp', SocialEmploymentKind::Employment, null, 150_000),
            $this->relationship('dpc', SocialEmploymentKind::Dpc, 200_000, 150_000),
            $this->relationship('body', SocialEmploymentKind::CorporateBody, null, 150_000),
        ]);

        foreach ($decisions as $decision) {
            self::assertSame(SocialParticipationStatus::Participates, $decision->status);
            self::assertSame(450_000, $decision->groupIncomeMinorUnits);
        }
    }

    public function testRegularRelationshipParticipatesFromAgreedIncomeEvenWithZeroCreditedIncome(): void
    {
        $decision = $this->resolve([
            $this->relationship('hpp', SocialEmploymentKind::Employment, 450_000, 0),
        ])['hpp'];

        self::assertSame(SocialParticipationStatus::Participates, $decision->status);
        self::assertSame(
            ['regular_relationship_agreed_income_threshold_met'],
            $decision->reasonCodes,
        );
    }

    public function testPostTerminationIncomeParticipatesOnlyWithVerifiedEndMonthAttribution(): void
    {
        $verified = $this->resolve([
            $this->relationship(
                'verified',
                SocialEmploymentKind::Dpc,
                null,
                450_000,
                false,
                SocialIncomeAttribution::PostTerminationEndMonthVerified,
            ),
        ])['verified'];
        self::assertSame(SocialParticipationStatus::Participates, $verified->status);

        $unverified = $this->resolve([
            $this->relationship(
                'unverified',
                SocialEmploymentKind::Dpc,
                null,
                450_000,
                false,
                SocialIncomeAttribution::Unverified,
            ),
        ])['unverified'];
        self::assertSame(SocialParticipationStatus::ManualReview, $unverified->status);
        self::assertContains('income_month_attribution_unverified', $unverified->reasonCodes);
    }

    public function testEmptyInactiveRelationshipDoesNotRequireManualReview(): void
    {
        $decision = $this->resolve([
            $this->relationship(
                'inactive',
                SocialEmploymentKind::Dpc,
                null,
                0,
                false,
                SocialIncomeAttribution::Unverified,
            ),
        ])['inactive'];

        self::assertSame(SocialParticipationStatus::DoesNotParticipate, $decision->status);
        self::assertSame(['inactive_without_attributable_income'], $decision->reasonCodes);
    }

    public function testUnresolvedDppRelationshipBlocksWholeAggregationGroup(): void
    {
        $clean = $this->relationship(
            'clean',
            SocialEmploymentKind::Dpp,
            null,
            1_200_000,
        );
        $unresolved = new SocialInsuranceRelationshipInput(
            'unresolved',
            SocialEmploymentKind::Dpp,
            null,
            true,
            SocialIncomeAttribution::CurrentEmploymentMonth,
            [new SocialAssessmentComponent(
                'unknown_income',
                100_000,
                SocialComponentTreatment::ManualReview,
                SocialComponentTreatment::ManualReview,
            )],
        );
        $decisions = $this->resolve([$clean, $unresolved]);

        self::assertSame(SocialParticipationStatus::ManualReview, $decisions['clean']->status);
        self::assertSame(
            ['dpp_group_contains_unresolved_relationship'],
            $decisions['clean']->reasonCodes,
        );
        self::assertSame(
            SocialParticipationStatus::ManualReview,
            $decisions['unresolved']->status,
        );
    }

    /**
     * @param list<SocialInsuranceRelationshipInput> $relationships
     * @return array<string,\MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationDecision>
     */
    private function resolve(array $relationships): array
    {
        $baseResolver = new SocialAssessmentBaseResolver();
        $facts = array_map($baseResolver->resolve(...), $relationships);

        return (new SocialParticipationResolver())->resolve($facts, 450_000, 1_200_000);
    }

    private function relationship(
        string $id,
        SocialEmploymentKind $kind,
        ?int $agreedIncome,
        int $creditedIncome,
        bool $active = true,
        SocialIncomeAttribution $attribution = SocialIncomeAttribution::CurrentEmploymentMonth,
    ): SocialInsuranceRelationshipInput {
        return new SocialInsuranceRelationshipInput(
            $id,
            $kind,
            $agreedIncome,
            $active,
            $attribution,
            [new SocialAssessmentComponent(
                'wage',
                $creditedIncome,
                SocialComponentTreatment::Included,
                SocialComponentTreatment::Included,
            )],
        );
    }
}
