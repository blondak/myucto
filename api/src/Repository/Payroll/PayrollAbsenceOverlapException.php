<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollAbsenceOverlapException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ve zvoleném období už existuje překrývající se absence.');
    }
}
