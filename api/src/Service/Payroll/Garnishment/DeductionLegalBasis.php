<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

enum DeductionLegalBasis: string
{
    case Statutory = 'statutory';
    case VoluntaryAgreement = 'voluntary_agreement';
}
