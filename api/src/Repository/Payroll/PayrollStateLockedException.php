<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollStateLockedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Po ostrém spuštění nelze mzdový modul vypnout obyčejnou změnou nastavení.');
    }
}
