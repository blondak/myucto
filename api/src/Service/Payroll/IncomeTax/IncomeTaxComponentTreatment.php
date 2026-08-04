<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

enum IncomeTaxComponentTreatment: string
{
    case Included = 'included';
    case Exempt = 'exempt';
    case ManualReview = 'manual-review';
}
