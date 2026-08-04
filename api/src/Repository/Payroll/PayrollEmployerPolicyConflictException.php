<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollEmployerPolicyConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Nastavení zaměstnavatelské politiky mezitím změnil jiný uživatel.');
    }
}
