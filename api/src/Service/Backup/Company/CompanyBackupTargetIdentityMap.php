<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Diskově omezená mapa zdrojových souřadnic na odpovídající cílové klíče. */
interface CompanyBackupTargetIdentityMap
{
    public function add(
        CompanyBackupSourceIdentity $sourceIdentity,
        CompanyBackupSourceIdentity $targetIdentity,
    ): void;

    public function find(
        CompanyBackupSourceKey $sourceKey,
    ): ?CompanyBackupSourceKey;

    public function findMatch(
        CompanyBackupSourceKey $sourceKey,
    ): ?CompanyBackupTargetIdentityMatch;

    public function seal(): void;

    public function isSealed(): bool;

    public function identityCount(): int;

    public function entryCount(): int;

    public function indexedBytes(): int;

    public function close(): void;
}
