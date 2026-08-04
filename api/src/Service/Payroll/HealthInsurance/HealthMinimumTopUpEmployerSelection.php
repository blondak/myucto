<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

enum HealthMinimumTopUpEmployerSelection: string
{
    case ThisEmployer = 'this_employer';
    case OtherEmployer = 'other_employer';
    case Unverified = 'unverified';
}
