<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use InvalidArgumentException;

final readonly class SocialInsuranceRelationshipInput
{
    /** @var non-empty-list<SocialAssessmentComponent> */
    public array $components;
    public SocialParticipationAggregationGroup $participationAggregationGroup;

    /**
     * CorporateBody represents one insurance relationship. A partner who is also
     * an executive of the same company must therefore be normalized into one input.
     *
     * @param array<mixed> $components
     */
    public function __construct(
        public string $relationshipId,
        public SocialEmploymentKind $kind,
        public ?int $agreedMonthlyIncomeMinorUnits,
        public bool $activeInParticipationMonth,
        public SocialIncomeAttribution $incomeAttribution,
        array $components,
        public SocialDiscountEvidence $partTimeEmployerDiscount = SocialDiscountEvidence::NotClaimed,
        public SocialEmployerRateCategory $employerRateCategory = SocialEmployerRateCategory::Ordinary,
        public bool $agricultureDppEmployeeDiscountRequested = false,
        public ?int $annualMaximumAllocationOrder = null,
        public ?string $partTimeEmployerDiscountEvidenceReference = null,
        ?SocialParticipationAggregationGroup $participationAggregationGroup = null,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $relationshipId) !== 1) {
            throw new InvalidArgumentException('Social insurance relationship ID is not canonical.');
        }
        if ($agreedMonthlyIncomeMinorUnits !== null && $agreedMonthlyIncomeMinorUnits < 0) {
            throw new InvalidArgumentException('Agreed monthly income cannot be negative.');
        }
        if (!array_is_list($components) || $components === []) {
            throw new InvalidArgumentException(
                'Social insurance relationship components must be a non-empty list.',
            );
        }
        foreach ($components as $component) {
            if (!$component instanceof SocialAssessmentComponent) {
                throw new InvalidArgumentException(
                    'Social insurance components must use the dedicated input type.',
                );
            }
        }
        if (
            $activeInParticipationMonth
            && $incomeAttribution === SocialIncomeAttribution::PostTerminationEndMonthVerified
        ) {
            throw new InvalidArgumentException(
                'Post-termination attribution cannot be used for an active relationship.',
            );
        }
        if (
            $agricultureDppEmployeeDiscountRequested
            && $kind !== SocialEmploymentKind::Dpp
        ) {
            throw new InvalidArgumentException(
                'The agriculture employee discount can only be requested for DPP.',
            );
        }
        if ($annualMaximumAllocationOrder !== null && $annualMaximumAllocationOrder < 0) {
            throw new InvalidArgumentException(
                'Annual maximum allocation order cannot be negative.',
            );
        }
        self::assertEvidenceReference(
            $partTimeEmployerDiscount,
            $partTimeEmployerDiscountEvidenceReference,
            'Part-time employer discount',
        );
        $resolvedAggregationGroup = $participationAggregationGroup ?? match ($kind) {
            SocialEmploymentKind::Employment =>
                SocialParticipationAggregationGroup::RegularRelationship,
            SocialEmploymentKind::Dpp =>
                SocialParticipationAggregationGroup::Dpp,
            SocialEmploymentKind::Dpc, SocialEmploymentKind::CorporateBody =>
                SocialParticipationAggregationGroup::SmallScaleCandidate,
        };
        if (
            ($kind === SocialEmploymentKind::Dpp)
            !== ($resolvedAggregationGroup === SocialParticipationAggregationGroup::Dpp)
        ) {
            throw new InvalidArgumentException(
                'DPP relationship and participation aggregation group must match.',
            );
        }

        $this->components = $components;
        $this->participationAggregationGroup = $resolvedAggregationGroup;
    }

    private static function assertEvidenceReference(
        SocialDiscountEvidence $evidence,
        ?string $reference,
        string $label,
    ): void {
        if (
            $evidence === SocialDiscountEvidence::Verified
            && ($reference === null
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/D', $reference) !== 1)
        ) {
            throw new InvalidArgumentException("{$label} verification requires an evidence reference.");
        }
        if ($evidence !== SocialDiscountEvidence::Verified && $reference !== null) {
            throw new InvalidArgumentException(
                "{$label} evidence reference is only allowed for verified claims.",
            );
        }
    }
}
