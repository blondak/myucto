<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní chyba DB zdroje bez SQL, hodnot řádků nebo fyzických cest ve zprávě. */
final class CompanyBackupDataSourceException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $registryKey,
        public readonly ?string $column = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $errorCode . ': ' . $registryKey
            . ($column === null ? '' : '.' . $column),
            0,
            $previous,
        );
    }
}
