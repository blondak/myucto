<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollInputCancellationException extends \DomainException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
