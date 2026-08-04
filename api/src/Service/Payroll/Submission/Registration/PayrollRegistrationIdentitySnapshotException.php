<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final class PayrollRegistrationIdentitySnapshotException extends \DomainException
{
    public function __construct(
        public readonly string $validationCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
