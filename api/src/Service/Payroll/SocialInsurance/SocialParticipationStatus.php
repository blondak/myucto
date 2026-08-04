<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

enum SocialParticipationStatus: string
{
    case Participates = 'participates';
    case DoesNotParticipate = 'does_not_participate';
    case ManualReview = 'manual_review';
}
