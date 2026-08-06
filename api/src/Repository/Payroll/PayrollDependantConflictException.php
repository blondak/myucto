<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollDependantConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Záznam mezitím upravil jiný uživatel.');
    }
}
