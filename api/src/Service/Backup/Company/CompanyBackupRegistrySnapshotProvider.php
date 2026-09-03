<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/** Poskytuje právě nasazený, úplný a obnovitelný profil zálohy firmy. */
interface CompanyBackupRegistrySnapshotProvider
{
    public function current(): TenantDataRegistrySnapshot;
}
