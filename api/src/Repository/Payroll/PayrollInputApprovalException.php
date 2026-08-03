<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollInputApprovalException extends \DomainException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
