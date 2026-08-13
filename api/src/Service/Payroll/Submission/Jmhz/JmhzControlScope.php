<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

enum JmhzControlScope: string
{
    case Pvpoj = 'pvpoj';
    case EmployeeForm = 'employee_form';
    case Global = 'global';
    case Unassigned = 'unassigned';
    case Summary = 'summary';
    case Unavailable = 'unavailable';
}
