<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;

/** Ověří obnovený tenantový graf uvnitř dosud necommitnuté transakce. */
interface CompanyBackupPostImportValidator
{
    public function validate(
        PDO $database,
        CompanyBackupImportSource $source,
        CompanyBackupDataPreflightResult $preflight,
        CompanyBackupDatabaseImportResult $result,
    ): CompanyBackupPostImportValidationResult;
}
