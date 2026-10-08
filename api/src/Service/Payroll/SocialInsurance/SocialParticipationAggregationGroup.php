<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

enum SocialParticipationAggregationGroup: string
{
    case RegularRelationship = 'regular_relationship';
    case Dpp = 'dpp';
    case SmallScaleCandidate = 'small_scale_candidate';

    /**
     * Příjem ze závislé činnosti, který u plátce účast nezakládá (druhy
     * činnosti 11 až 14, {@see \MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily::isOutsideStatutoryInsurance()}).
     */
    case OutsideInsurance = 'outside_insurance';
}
