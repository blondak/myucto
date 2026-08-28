<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

enum CompanyBackupFileState: string
{
    case Present = 'present';
    case Missing = 'missing';
}
