<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

final class PayrollPersonAccountVerificationConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct(
            "Ověření účtu bylo mezitím změněno (aktuální verze {$currentVersion}).",
        );
    }
}
