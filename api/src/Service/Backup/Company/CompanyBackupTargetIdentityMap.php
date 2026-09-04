<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Diskově omezená mapa zdrojových souřadnic na nové cílové primární klíče. */
interface CompanyBackupTargetIdentityMap
{
    public function add(
        CompanyBackupSourceIdentity $sourceIdentity,
        CompanyBackupSourceKey $targetPrimaryKey,
    ): void;

    public function find(
        CompanyBackupSourceKey $sourceKey,
    ): ?CompanyBackupSourceKey;

    public function seal(): void;

    public function identityCount(): int;

    public function entryCount(): int;

    public function indexedBytes(): int;

    public function close(): void;
}
