<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní chyba vytváření balíčku bez hesla nebo fyzické cesty ve zprávě. */
final class CompanyBackupArchiveWriteException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?string $entry = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $errorCode . ($entry === null ? '' : ': ' . $entry),
            0,
            $previous,
        );
    }
}
