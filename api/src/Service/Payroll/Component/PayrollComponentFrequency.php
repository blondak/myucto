<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

enum PayrollComponentFrequency: string
{
    case REGULAR = 'regular';
    case ONE_OFF = 'one_off';
}
