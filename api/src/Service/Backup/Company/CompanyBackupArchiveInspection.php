<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/** Neměnný výsledek úspěšné technické validace bez rozbalení na disk. */
final readonly class CompanyBackupArchiveInspection
{
    /** @var array<string,string> */
    public array $entryHashes;

    /** @param array<string,string> $entryHashes */
    public function __construct(
        public CompanyBackupManifestHeader $manifest,
        public TenantDataRegistrySnapshot $sourceRegistry,
        public CompanyBackupDataInventory $dataInventory,
        public CompanyBackupFileInventory $fileInventory,
        public CompanyBackupCompatibilityResult $compatibility,
        public string $archiveSha256,
        public int $entryCount,
        public int $expandedBytes,
        array $entryHashes,
    ) {
        if ($sourceRegistry->profile !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
            || !hash_equals($sourceRegistry->fingerprint, $dataInventory->registryFingerprint)
            || !hash_equals($sourceRegistry->fingerprint, $fileInventory->registryFingerprint)
            || !$compatibility->isCompatible()
            || preg_match('/^[0-9a-f]{64}$/D', $archiveSha256) !== 1
            || $entryCount < 1
            || $expandedBytes < 0
        ) {
            throw new \InvalidArgumentException('Výsledek inspekce zálohy není platný.');
        }
        ksort($entryHashes, SORT_STRING);
        foreach ($entryHashes as $path => $hash) {
            if (preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException('Výsledek inspekce obsahuje neplatný hash.');
            }
        }
        $this->entryHashes = $entryHashes;
    }
}
