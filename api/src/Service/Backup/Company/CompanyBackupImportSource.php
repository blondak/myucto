<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/** Opakovatelný, technickou validací svázaný zdroj databázového importu. */
interface CompanyBackupImportSource
{
    public function sourceRegistry(): TenantDataRegistrySnapshot;

    public function targetRegistry(): TenantDataRegistrySnapshot;

    public function dataInventory(): CompanyBackupDataInventory;

    public function technicalValidationBindingSha256(): string;

    /**
     * @param callable(array<string,mixed>):void $rowVisitor
     * @param null|callable(CompanyBackupReferenceOccurrence):void $referenceVisitor
     */
    public function consumeRows(
        string $registryKey,
        callable $rowVisitor,
        ?callable $referenceVisitor = null,
    ): int;

    public function secretPayload(): ?CompanyBackupSecretPayload;
}
