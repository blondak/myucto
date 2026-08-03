<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

enum SocialDiscountEvidence: string
{
    case NotClaimed = 'not_claimed';
    case Verified = 'verified';
    case Unverified = 'unverified';
}
