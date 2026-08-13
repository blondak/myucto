<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

enum JmhzFieldEffect: string
{
    case Add = 'add';
    case Remove = 'remove';
    case None = 'none';
}
