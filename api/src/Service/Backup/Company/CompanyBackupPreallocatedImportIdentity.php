<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Zdrojová a cílová identita rezervovaná bez zápisu business řádku. */
final readonly class CompanyBackupPreallocatedImportIdentity
{
    public function __construct(
        public CompanyBackupSourceIdentity $sourceIdentity,
        public CompanyBackupSourceIdentity $targetIdentity,
    ) {
        if ($sourceIdentity->policy !== $targetIdentity->policy
            || $sourceIdentity->primaryKey->registryKey
                !== $targetIdentity->primaryKey->registryKey
        ) {
            throw new \InvalidArgumentException(
                'Předalokované identity nemají souhlasný objekt ani politiku.',
            );
        }
    }
}
