<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;

/** Databázová část obnovy, která nikdy nevlastní commit volající transakce. */
interface CompanyBackupDatabaseImport
{
    public function restore(
        CompanyBackupImportSource $source,
        CompanyBackupDataPreflightResult $preflight,
        CompanyBackupReferenceDecisionPlan $decisions,
        PayrollSensitiveData $sensitiveData,
    ): CompanyBackupDatabaseImportResult;
}
