<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Neměnná metadata jediného zveřejněného archivu svázaná s jobem a firmou. */
final readonly class CompanyBackupStoredArtifact
{
    public function __construct(
        public int $supplierId,
        public string $backupId,
        public string $relativePath,
        public string $downloadName,
        public int $bytes,
        public string $sha256,
        public int $entryCount,
    ) {
        $expectedPath = 'sup-' . $supplierId . '/' . $backupId . '.zip';
        $expectedName = 'myucto-company-backup-' . $backupId . '.zip';
        if ($supplierId < 1
            || !CompanyBackupManifestHeader::isCanonicalBackupId($backupId)
            || $relativePath !== $expectedPath
            || $downloadName !== $expectedName
            || $bytes < 1
            || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1
            || $entryCount < 3
        ) {
            throw new \InvalidArgumentException(
                'Metadata uloženého archivu zálohy firmy nejsou platná.',
            );
        }
    }
}
