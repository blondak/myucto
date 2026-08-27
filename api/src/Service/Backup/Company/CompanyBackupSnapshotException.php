<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní chyba infrastruktury konzistentního DB snapshotu. */
final class CompanyBackupSnapshotException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }
}
