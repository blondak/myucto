<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollRecurringComponentConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Předpis opakované složky mezitím změnil jiný uživatel.');
    }
}
