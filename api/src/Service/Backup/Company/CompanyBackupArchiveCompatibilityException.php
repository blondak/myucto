<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Kompatibilitní stopka před čtením business payloadu. */
final class CompanyBackupArchiveCompatibilityException extends \RuntimeException
{
    public function __construct(
        public readonly CompanyBackupCompatibilityResult $compatibility,
    ) {
        parent::__construct('Záloha není kompatibilní s cílovou aplikací.');
    }
}
