<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

enum OtherWithholdingEligibility: string
{
    case Automatic = 'automatic';
    case EligibleVerified = 'eligible-verified';
    case IneligibleVerified = 'ineligible-verified';
    case Unverified = 'unverified';
}
