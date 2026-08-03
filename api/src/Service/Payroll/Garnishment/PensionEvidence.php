<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

enum PensionEvidence: string
{
    case None = 'none';
    case Verified = 'verified';
    case Unknown = 'unknown';
}
