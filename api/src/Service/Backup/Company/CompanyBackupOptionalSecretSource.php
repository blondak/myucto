<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;

/** Zdroj pouze těch optional credential hodnot, které prošly výběrem. */
interface CompanyBackupOptionalSecretSource
{
    /** @return iterable<mixed> */
    public function values(
        PDO $snapshot,
        int $supplierId,
        CompanyBackupOptionalSecretProjection $projection,
    ): iterable;
}
