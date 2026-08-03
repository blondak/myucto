<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

enum SocialJurisdictionEvidence: string
{
    case CzechRegimeVerified = 'czech_regime_verified';
    case ForeignRegimeVerified = 'foreign_regime_verified';
    case Unverified = 'unverified';
}
