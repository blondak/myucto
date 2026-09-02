<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

interface CompanyBackupArtifactRootResolver
{
    public function root(): string;
}
