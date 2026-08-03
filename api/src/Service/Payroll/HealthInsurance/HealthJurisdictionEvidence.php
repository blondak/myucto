<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

enum HealthJurisdictionEvidence: string
{
    case CzechRegimeVerified = 'czech_regime_verified';
    case ForeignRegimeVerified = 'foreign_regime_verified';
    case Unverified = 'unverified';
}
