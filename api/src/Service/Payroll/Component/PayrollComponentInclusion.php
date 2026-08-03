<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

enum PayrollComponentInclusion: string
{
    case INCLUDED = 'included';
    case EXCLUDED = 'excluded';
    case MANUAL_REVIEW = 'manual_review';
}
