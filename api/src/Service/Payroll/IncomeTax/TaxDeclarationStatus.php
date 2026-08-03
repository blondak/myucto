<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

enum TaxDeclarationStatus: string
{
    case Signed = 'signed';
    case NotSigned = 'not-signed';
    case Unverified = 'unverified';
}
