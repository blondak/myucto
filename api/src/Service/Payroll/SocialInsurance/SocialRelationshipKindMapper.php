<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use InvalidArgumentException;

final class SocialRelationshipKindMapper
{
    public function fromRelationType(string $relationType): SocialRelationshipKindMapping
    {
        return match ($relationType) {
            'employment' => new SocialRelationshipKindMapping(
                SocialEmploymentKind::Employment,
                SocialParticipationAggregationGroup::RegularRelationship,
            ),
            'small_scale_employment' => new SocialRelationshipKindMapping(
                SocialEmploymentKind::Employment,
                SocialParticipationAggregationGroup::SmallScaleCandidate,
            ),
            'dpp' => new SocialRelationshipKindMapping(
                SocialEmploymentKind::Dpp,
                SocialParticipationAggregationGroup::Dpp,
            ),
            'dpc' => new SocialRelationshipKindMapping(
                SocialEmploymentKind::Dpc,
                SocialParticipationAggregationGroup::SmallScaleCandidate,
            ),
            'partner_dependent', 'statutory_body' => new SocialRelationshipKindMapping(
                SocialEmploymentKind::CorporateBody,
                SocialParticipationAggregationGroup::SmallScaleCandidate,
            ),
            default => throw new InvalidArgumentException(
                'Unsupported payroll relation type for social insurance.',
            ),
        };
    }
}
