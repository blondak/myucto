<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

enum JmhzProtocolErrorOrigin: string
{
    case Dis = 'dis';
    case Cjmhz = 'cjmhz';
    case Platform = 'platform';
}
