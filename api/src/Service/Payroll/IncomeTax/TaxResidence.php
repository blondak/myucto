<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

enum TaxResidence: string
{
    case CzechResident = 'czech-resident';
    case NonResident = 'non-resident';
    case Unverified = 'unverified';
}
