<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

enum JmhzFieldRequirementKind: string
{
    case Required = 'required';
    case Optional = 'optional';
    case Conditional = 'conditional';
}
