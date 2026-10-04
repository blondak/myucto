<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Personnel;

final class PayrollPersonnelFileException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
