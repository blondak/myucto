<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Zapisovatelný, omezený index úplného grafu zdrojových identit. */
interface CompanyBackupSourceIdentityIndex extends CompanyBackupSourceIdentityLookup
{
    public function add(CompanyBackupSourceIdentity $identity): void;

    public function seal(): void;

    public function identityCount(): int;

    public function entryCount(): int;

    public function indexedBytes(): int;

    public function close(): void;
}
