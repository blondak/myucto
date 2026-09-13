<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Export;

/** Filtr drží víc řádků, než daný formát exportu unese. */
final class PayrollInputExportTooLargeException extends \RuntimeException
{
    public function __construct(
        public readonly int $rowCount,
        public readonly int $limit,
        string $message,
    ) {
        parent::__construct($message);
    }
}
