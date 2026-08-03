<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollEnforcementConflictException extends \RuntimeException
{
    public function __construct(public readonly ?int $currentVersion = null)
    {
        parent::__construct('Exekuční případ mezitím změnil jiný uživatel.');
    }
}
