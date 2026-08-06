<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollDependantNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Vyživovaná osoba nenalezena.');
    }
}
