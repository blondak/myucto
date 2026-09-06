<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/** Produkční staging vždy ukládá pod runtime data directory. */
final readonly class CompanyBackupRuntimeFileStagingRootResolver implements
    CompanyBackupFileStagingRootResolver
{
    public function root(): string
    {
        return RuntimePaths::storage('tmp/company-backup-restores');
    }
}
