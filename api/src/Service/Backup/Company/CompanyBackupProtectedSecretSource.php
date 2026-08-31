<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;

/** Zdroj povinných plaintext hodnot uvnitř jednoho konzistentního DB read view. */
interface CompanyBackupProtectedSecretSource
{
    /** @return iterable<mixed> */
    public function values(
        PDO $snapshot,
        int $supplierId,
        CompanyBackupProtectedSecretProjection $projection,
    ): iterable;
}
