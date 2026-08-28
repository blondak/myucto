<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;

/** Zdroj skutečných počtů výchozích secret vynechání v DB snapshotu. */
interface CompanyBackupSecretOmissionCountSource
{
    /**
     * @param list<CompanyBackupSecretDeclaration> $declarations
     * @return array<string,int> declaration signature => count
     */
    public function counts(
        PDO $snapshot,
        int $supplierId,
        TenantDataDefinition $definition,
        array $declarations,
        TenantDataRegistry $registry,
    ): array;
}
