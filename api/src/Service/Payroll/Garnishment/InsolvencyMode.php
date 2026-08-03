<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

enum InsolvencyMode: string
{
    case None = 'none';
    case AlertOnly = 'alert_only';
    case ApprovedStandard = 'approved_standard';
    case CourtDeterminedAmount = 'court_determined_amount';
}
