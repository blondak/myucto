<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní preflight chyba bez zdrojových business hodnot ve zprávě. */
final class CompanyBackupPreflightException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?string $registryKey = null,
        public readonly ?string $column = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $errorCode
            . ($registryKey === null ? '' : ': ' . $registryKey)
            . ($column === null ? '' : '.' . $column),
            0,
            $previous,
        );
    }
}
