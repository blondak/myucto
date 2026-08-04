<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollStateConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Nastavení mezd mezitím změnil jiný uživatel.');
    }
}
