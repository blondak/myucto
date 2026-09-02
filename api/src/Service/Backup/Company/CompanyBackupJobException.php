<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní interní chyba lifecycle bez hesla nebo absolutní cesty ve zprávě. */
final class CompanyBackupJobException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }
}
