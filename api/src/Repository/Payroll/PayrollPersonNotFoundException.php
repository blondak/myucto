<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollPersonNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Zaměstnanec nenalezen.');
    }
}
