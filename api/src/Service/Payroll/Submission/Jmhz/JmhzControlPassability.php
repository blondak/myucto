<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

enum JmhzControlPassability: string
{
    case Blocking = 'blocking';
    case Passable = 'passable';
    case Unavailable = 'unavailable';
}
