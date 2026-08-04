<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollInputConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Mzdový vstup mezitím změnil jiný uživatel.');
    }
}
