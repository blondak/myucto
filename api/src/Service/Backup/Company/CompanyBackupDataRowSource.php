<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use PDO;

/** Zdroj už deterministicky seřazených řádků jednoho objektu registru. */
interface CompanyBackupDataRowSource
{
    /** @return iterable<mixed,array<string,mixed>> */
    public function rows(
        PDO $snapshot,
        int $supplierId,
        TenantDataDefinition $definition,
    ): iterable;
}
