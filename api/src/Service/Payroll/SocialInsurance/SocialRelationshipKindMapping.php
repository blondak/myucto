<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

final readonly class SocialRelationshipKindMapping
{
    public function __construct(
        public SocialEmploymentKind $kind,
        public SocialParticipationAggregationGroup $aggregationGroup,
    ) {}
}
