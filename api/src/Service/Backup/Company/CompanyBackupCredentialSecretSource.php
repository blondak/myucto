<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;

/** Zdroj výslovně vybraných non-payload credential řádků a jejich příloh. */
interface CompanyBackupCredentialSecretSource
{
    /**
     * @param array<mixed> $entries
     * @return iterable<mixed>
     */
    public function values(
        PDO $snapshot,
        int $supplierId,
        CompanyBackupCredentialTableProjection $projection,
        array $entries,
    ): iterable;
}
