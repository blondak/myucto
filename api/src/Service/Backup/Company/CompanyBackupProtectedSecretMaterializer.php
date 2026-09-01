<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Známý cílový převod plaintextu z envelope do doménového at-rest formátu. */
enum CompanyBackupProtectedSecretMaterializer: string
{
    case PayrollSensitiveV1 = 'payroll_sensitive_v1';
}
