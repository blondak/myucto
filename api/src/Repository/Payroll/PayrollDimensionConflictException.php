<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollDimensionConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Mzdovou dimenzi mezitím změnil jiný uživatel.');
    }
}
