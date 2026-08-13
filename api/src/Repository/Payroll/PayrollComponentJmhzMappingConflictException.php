<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollComponentJmhzMappingConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Mapování JMHZ mezitím změnil jiný uživatel.');
    }
}
