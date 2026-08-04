<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

enum HealthCorrectionTreatment: string
{
    case CurrentMonth = 'current_month';
    case PriorPeriodRevision = 'prior_period_revision';
    case Unverified = 'unverified';
}
