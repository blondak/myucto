<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final class PayrollRegistrationXmlException extends \RuntimeException
{
    public function __construct(
        public readonly string $validationCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
