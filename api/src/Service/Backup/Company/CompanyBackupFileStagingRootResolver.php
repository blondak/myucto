<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Poskytne oddělený runtime kořen pro plaintext souborový restore staging. */
interface CompanyBackupFileStagingRootResolver
{
    public function root(): string;
}
