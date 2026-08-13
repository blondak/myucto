<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

enum JmhzOfficialExampleValidationResult: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case NotApplicable = 'not_applicable';
}
