<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/** Fail-closed adaptér nad jediným produkčním TenantDataRegistry. */
final readonly class CurrentCompanyBackupRegistrySnapshotProvider implements
    CompanyBackupRegistrySnapshotProvider
{
    public function __construct(private TenantDataRegistry $registry) {}

    public function current(): TenantDataRegistrySnapshot
    {
        return TenantDataRegistrySnapshot::fromRegistry(
            $this->registry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        );
    }
}
