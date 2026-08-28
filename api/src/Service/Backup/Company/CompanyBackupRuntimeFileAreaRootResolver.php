<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/** Produkční resolver, který vždy respektuje MYINVOICE_DATA_DIR. */
final readonly class CompanyBackupRuntimeFileAreaRootResolver implements
    CompanyBackupFileAreaRootResolver
{
    public function resolve(string $storageSubdirectory): string
    {
        return RuntimePaths::storage($storageSubdirectory);
    }
}
