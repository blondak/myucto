<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní chyba stagingu souborů bez zdrojových cest a obsahu ve zprávě. */
final class CompanyBackupFileRestoreException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $registryKey = 'file-area:inventory',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode . ': ' . $registryKey, 0, $previous);
    }
}
