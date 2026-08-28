<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Překládá stabilní storage podadresář na runtime cestu konkrétní instance. */
interface CompanyBackupFileAreaRootResolver
{
    public function resolve(string $storageSubdirectory): string;
}
