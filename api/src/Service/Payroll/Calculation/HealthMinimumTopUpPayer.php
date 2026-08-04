<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

enum HealthMinimumTopUpPayer: string
{
    case Employee = 'employee';
    case Employer = 'employer';
}
