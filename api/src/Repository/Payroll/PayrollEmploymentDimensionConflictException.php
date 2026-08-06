<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollEmploymentDimensionConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Přiřazení dimenze mezitím změnil jiný uživatel.');
    }
}
