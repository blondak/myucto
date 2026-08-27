<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Výsledek atomického zveřejnění balíčku, vhodný pro ETag a job metadata. */
final readonly class CompanyBackupArchiveWriteResult
{
    public function __construct(
        public string $archivePath,
        public string $archiveSha256,
        public int $archiveBytes,
        public int $entryCount,
    ) {
        if ($archivePath === ''
            || preg_match('/^[0-9a-f]{64}$/D', $archiveSha256) !== 1
            || $archiveBytes < 1
            || $entryCount < 3
        ) {
            throw new \InvalidArgumentException('Výsledek zápisu zálohy není platný.');
        }
    }
}
