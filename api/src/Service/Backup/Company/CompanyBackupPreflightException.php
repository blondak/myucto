<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní preflight chyba bez zdrojových business hodnot ve zprávě. */
final class CompanyBackupPreflightException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }
}
