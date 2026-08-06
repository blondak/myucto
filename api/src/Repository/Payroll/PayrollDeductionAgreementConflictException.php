<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollDeductionAgreementConflictException extends \RuntimeException
{
    public function __construct(public readonly ?int $currentVersion = null)
    {
        parent::__construct('Dohodu o srážce mezitím změnil jiný uživatel.');
    }
}
