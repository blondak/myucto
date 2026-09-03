<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní chyba založení jobu bez hesla, proofu nebo interní diagnostiky. */
final class CompanyBackupCreationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        ?\Throwable $previous = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $errorCode) !== 1) {
            throw new \InvalidArgumentException(
                'Kód chyby vytvoření zálohy není platný.',
            );
        }
        parent::__construct($errorCode, 0, $previous);
    }
}
