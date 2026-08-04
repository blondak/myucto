<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final class EmploymentExitReadinessException extends \DomainException
{
    public function __construct(
        public readonly string $readinessCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
