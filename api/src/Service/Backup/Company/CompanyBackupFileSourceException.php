<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní fail-closed chyba čtení registrovaného souboru firmy. */
final class CompanyBackupFileSourceException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $registryKey,
        public readonly ?string $sourcePath = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $errorCode . ' [' . $registryKey
                . ($sourcePath === null ? '' : ':' . $sourcePath) . ']',
            0,
            $previous,
        );
    }
}
