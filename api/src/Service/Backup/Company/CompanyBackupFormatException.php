<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use RuntimeException;

/** Strukturální chyba manifestu před jakýmkoli plánováním obnovy. */
final class CompanyBackupFormatException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
