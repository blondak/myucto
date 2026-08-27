<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/** Neměnná vazba ověřeného uploadu na konkrétní cílový kontrakt. */
final readonly class CompanyBackupTechnicalValidation
{
    public string $sourceRegistryFingerprint;
    public string $targetRegistryFingerprint;
    public bool $registryChanged;
    public string $bindingSha256;

    public function __construct(
        public CompanyBackupArchiveInspection $inspection,
        public TenantDataRegistrySnapshot $targetRegistry,
        public string $targetAppVersion,
        public string $targetSchemaRevision,
    ) {
        if ($targetRegistry->profile !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
            || !CompanyBackupManifestHeader::isSemanticVersion($targetAppVersion)
            || preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $targetSchemaRevision) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Cílový kontrakt technické validace není platný.',
            );
        }
        $this->sourceRegistryFingerprint = $inspection->sourceRegistry->fingerprint;
        $this->targetRegistryFingerprint = $targetRegistry->fingerprint;
        $this->registryChanged = !hash_equals(
            $this->sourceRegistryFingerprint,
            $this->targetRegistryFingerprint,
        );
        $this->bindingSha256 = CanonicalJson::sha256([
            'format' => 'myucto-company-backup-technical-validation',
            'version' => 1,
            'archive_sha256' => $inspection->archiveSha256,
            'manifest_sha256' => $inspection->manifest->sha256(),
            'backup_id' => $inspection->manifest->backupId,
            'source_registry_fingerprint' => $this->sourceRegistryFingerprint,
            'upcasters' => $inspection->compatibility->upcasterIds,
            'warnings' => $inspection->compatibility->warnings,
            'target' => [
                'app_version' => $targetAppVersion,
                'schema_revision' => $targetSchemaRevision,
                'registry_fingerprint' => $this->targetRegistryFingerprint,
            ],
        ]);
    }

    /**
     * @return array{
     *   backup_id:string,
     *   archive_sha256:string,
     *   manifest_sha256:string,
     *   source_registry_fingerprint:string,
     *   target_registry_fingerprint:string,
     *   registry_changed:bool,
     *   upcasters:list<string>,
     *   warnings:list<string>,
     *   binding_sha256:string
     * }
     */
    public function toArray(): array
    {
        return [
            'backup_id' => $this->inspection->manifest->backupId,
            'archive_sha256' => $this->inspection->archiveSha256,
            'manifest_sha256' => $this->inspection->manifest->sha256(),
            'source_registry_fingerprint' => $this->sourceRegistryFingerprint,
            'target_registry_fingerprint' => $this->targetRegistryFingerprint,
            'registry_changed' => $this->registryChanged,
            'upcasters' => $this->inspection->compatibility->upcasterIds,
            'warnings' => $this->inspection->compatibility->warnings,
            'binding_sha256' => $this->bindingSha256,
        ];
    }
}
