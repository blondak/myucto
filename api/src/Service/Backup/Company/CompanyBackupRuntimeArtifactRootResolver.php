<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\RuntimePaths;

final readonly class CompanyBackupRuntimeArtifactRootResolver implements
    CompanyBackupArtifactRootResolver
{
    public function root(): string
    {
        return RuntimePaths::storage('company-backups');
    }
}
