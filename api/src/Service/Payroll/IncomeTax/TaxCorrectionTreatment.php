<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

enum TaxCorrectionTreatment: string
{
    case CurrentMonth = 'current-month';
    case PriorPeriodRevision = 'prior-period-revision';
    case Unverified = 'unverified';
}
