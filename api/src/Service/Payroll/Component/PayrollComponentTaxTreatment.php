<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

enum PayrollComponentTaxTreatment: string
{
    case INCLUDED = 'included';
    case EXEMPT = 'exempt';
    case WITHHOLDING_CANDIDATE = 'withholding_candidate';
    case MANUAL_REVIEW = 'manual_review';
}
