<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollEmploymentConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Pracovní vztah mezitím změnil jiný uživatel.');
    }
}
