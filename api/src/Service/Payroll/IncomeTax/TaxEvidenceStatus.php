<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

enum TaxEvidenceStatus: string
{
    case Verified = 'verified';
    case Unverified = 'unverified';
}
