<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Registry původ jedné počítané secret položky. */
enum CompanyBackupSecretScope: string
{
    case Column = 'column';
    case CredentialVariant = 'credential_variant';
}
