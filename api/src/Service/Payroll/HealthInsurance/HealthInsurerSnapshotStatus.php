<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

enum HealthInsurerSnapshotStatus: string
{
    case Verified = 'verified';
    case Unverified = 'unverified';
    case NotApplicable = 'not_applicable';
}
