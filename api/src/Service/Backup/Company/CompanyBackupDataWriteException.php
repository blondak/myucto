<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní chyba JSONL zápisu bez business hodnot nebo fyzické cesty ve zprávě. */
final class CompanyBackupDataWriteException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $registryKey,
        public readonly ?int $rowNumber = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $errorCode . ': ' . $registryKey
            . ($rowNumber === null ? '' : ' #' . $rowNumber),
            0,
            $previous,
        );
    }
}
