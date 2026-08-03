<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

enum PayrollComponentValueKind: string
{
    case MONETARY = 'monetary';
    case NON_MONETARY = 'non_monetary';
}
