<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use PDO;

/** Vypíše odkazy na soubory z téhož konzistentního DB pohledu jako JSONL. */
interface CompanyBackupFileReferenceSource
{
    /** @return iterable<CompanyBackupFileReference> */
    public function references(
        PDO $snapshot,
        int $supplierId,
        TenantDataDefinition $definition,
    ): iterable;
}
