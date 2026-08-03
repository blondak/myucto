<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollInstitutionAccountConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Účet instituce mezitím změnil jiný uživatel.');
    }
}
