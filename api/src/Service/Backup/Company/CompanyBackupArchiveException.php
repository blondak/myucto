<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní technická chyba balíčku bez hesla nebo obsahu secretů ve zprávě. */
final class CompanyBackupArchiveException extends \RuntimeException
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
