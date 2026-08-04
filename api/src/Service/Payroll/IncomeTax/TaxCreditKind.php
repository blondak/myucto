<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

enum TaxCreditKind: string
{
    case Taxpayer = 'taxpayer';
    case DisabilityBasic = 'disability-basic';
    case DisabilityExtended = 'disability-extended';
    case ZtpP = 'ztp-p';
}
