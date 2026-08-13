<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

enum JmhzMatrixKind: string
{
    case Part = 'part';
    case Scenario = 'scenario';
    case Foundation = 'foundation';
    case Interaction = 'interaction';
}
