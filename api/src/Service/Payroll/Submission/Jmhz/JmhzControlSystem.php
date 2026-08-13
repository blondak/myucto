<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

enum JmhzControlSystem: string
{
    case Eportal = 'eportal';
    case Dis = 'dis';
    case Cjmhz = 'cjmhz';
    case Unavailable = 'unavailable';
}
