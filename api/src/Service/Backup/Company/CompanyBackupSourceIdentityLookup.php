<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Lookup nad úplným, mimo business tabulky uloženým indexem zdrojových řádků. */
interface CompanyBackupSourceIdentityLookup
{
    public function find(CompanyBackupSourceKey $key): ?CompanyBackupSourceIdentity;
}
