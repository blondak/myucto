<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

enum TaxCalculationStatus: string
{
    case Calculated = 'calculated';
    case ManualReview = 'manual-review';
}
