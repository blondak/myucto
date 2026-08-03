<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollRunConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Mzdový běh mezitím změnil jiný požadavek.');
    }
}
