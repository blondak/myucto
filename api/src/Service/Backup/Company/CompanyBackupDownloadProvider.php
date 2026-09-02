<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Připraví jediný tenantově ověřený stream hotového archivu. */
interface CompanyBackupDownloadProvider
{
    public function prepare(
        string $backupId,
        int $supplierId,
        ?string $rangeHeader,
        ?string $ifRangeHeader,
    ): CompanyBackupPreparedDownload;
}
