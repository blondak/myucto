<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní chyba cílové ID mapy bez business hodnot ve zprávě. */
final class CompanyBackupIdentityMapException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?string $registryKey = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $errorCode
            . ($registryKey === null ? '' : ': ' . $registryKey),
            0,
            $previous,
        );
    }
}
