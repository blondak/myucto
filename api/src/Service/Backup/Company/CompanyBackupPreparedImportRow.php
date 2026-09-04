<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Jeden transformovaný INSERT řádek svázaný se zdrojovou a cílovou identitou. */
final readonly class CompanyBackupPreparedImportRow
{
    /**
     * @param array<string,mixed> $row
     */
    public function __construct(
        public array $row,
        public CompanyBackupSourceIdentity $sourceIdentity,
        public CompanyBackupSourceIdentity $targetIdentity,
    ) {
        if ($sourceIdentity->policy !== $targetIdentity->policy
            || $sourceIdentity->primaryKey->registryKey
                !== $targetIdentity->primaryKey->registryKey
        ) {
            throw new \InvalidArgumentException(
                'Připravený importní řádek nemá souhlasné identity.',
            );
        }
    }
}
