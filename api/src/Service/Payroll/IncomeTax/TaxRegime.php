<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

enum TaxRegime: string
{
    case Advance = 'advance';
    case Withholding = 'withholding';
    case ManualReview = 'manual-review';
}
