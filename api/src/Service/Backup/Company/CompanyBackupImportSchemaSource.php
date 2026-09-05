<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;

/** Runtime schéma a AUTO_INCREMENT metadata pro zapisovací část obnovy. */
interface CompanyBackupImportSchemaSource
{
    public function read(
        PDO $database,
        CompanyBackupTableProjection $projection,
    ): CompanyBackupTableSchema;

    public function readImportMetadata(
        PDO $database,
        CompanyBackupTableProjection $projection,
    ): CompanyBackupImportTableMetadata;
}
