<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní chyba transakčního koordinátoru bez business hodnot. */
final class CompanyBackupRestoreException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }
}
