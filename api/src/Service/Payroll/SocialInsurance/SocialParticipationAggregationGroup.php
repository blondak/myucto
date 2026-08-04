<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

enum SocialParticipationAggregationGroup: string
{
    case RegularRelationship = 'regular_relationship';
    case Dpp = 'dpp';
    case SmallScaleCandidate = 'small_scale_candidate';
}
