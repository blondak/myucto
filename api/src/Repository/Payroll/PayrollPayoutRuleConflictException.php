<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollPayoutRuleConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Výplatní pravidlo mezitím změnil jiný uživatel.');
    }
}
