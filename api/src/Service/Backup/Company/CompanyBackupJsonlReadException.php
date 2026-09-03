<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní chyba JSONL čtení bez business hodnot nebo fyzické cesty ve zprávě. */
final class CompanyBackupJsonlReadException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $registryKey,
        public readonly ?int $rowNumber = null,
        public readonly ?string $column = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $errorCode . ': ' . $registryKey
            . ($rowNumber === null ? '' : ' #' . $rowNumber)
            . ($column === null ? '' : '.' . $column),
            0,
            $previous,
        );
    }
}
