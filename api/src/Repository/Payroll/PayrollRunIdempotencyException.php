<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollRunIdempotencyException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Idempotency key už byl použit pro jiný příkaz nebo jiný obsah požadavku.',
        );
    }
}
