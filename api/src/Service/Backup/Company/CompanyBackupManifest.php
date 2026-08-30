<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/** Úplná strojová obálka manifestu po kompatibilitní kontrole hlavičky. */
final readonly class CompanyBackupManifest
{
    private function __construct(
        public CompanyBackupManifestHeader $header,
        public TenantDataRegistrySnapshot $registry,
        public CompanyBackupDataInventory $data,
        public CompanyBackupFileInventory $files,
        public CompanyBackupSecretInventory $secrets,
    ) {}

    public static function fromHeader(CompanyBackupManifestHeader $header): self
    {
        $manifest = $header->toArray();
        try {
            $registry = TenantDataRegistrySnapshot::fromArray($manifest['registry'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw new CompanyBackupFormatException(
                'manifest_registry_invalid',
                'registry',
                'Zdrojový tenantový registr v manifestu není platný: ' . $e->getMessage(),
            );
        }
        if ($registry->profile !== TenantDataRegistry::COMPANY_BACKUP_PROFILE) {
            throw new CompanyBackupFormatException(
                'manifest_registry_invalid',
                'registry.profile',
                'Manifest musí obsahovat úplný profil company_backup.',
            );
        }
        try {
            $data = CompanyBackupDataInventory::fromArray($manifest['data'] ?? null, $registry);
        } catch (\InvalidArgumentException $e) {
            throw new CompanyBackupFormatException(
                'manifest_data_invalid',
                'data',
                'Inventář strojových dat v manifestu není platný: ' . $e->getMessage(),
            );
        }
        try {
            $files = CompanyBackupFileInventory::fromArray(
                $manifest['files'] ?? null,
                $registry,
            );
        } catch (\InvalidArgumentException $e) {
            throw new CompanyBackupFormatException(
                'manifest_files_invalid',
                'files',
                'Inventář souborů v manifestu není platný: ' . $e->getMessage(),
            );
        }
        try {
            $secrets = CompanyBackupSecretInventory::fromArray(
                $manifest['secrets'] ?? null,
                $registry,
            );
        } catch (\InvalidArgumentException $e) {
            throw new CompanyBackupFormatException(
                'manifest_secrets_invalid',
                'secrets',
                'Inventář secrets v manifestu není platný: ' . $e->getMessage(),
            );
        }
        $declaresEnvelope = in_array(
            CompanyBackupSecretEnvelopeDescriptor::CAPABILITY,
            $header->requiredCapabilities,
            true,
        );
        if (($secrets->envelope !== null) !== $declaresEnvelope) {
            throw new CompanyBackupFormatException(
                'manifest_secrets_invalid',
                'secrets.envelope',
                'Secret envelope a jeho povinná capability se musí deklarovat společně.',
            );
        }
        return new self($header, $registry, $data, $files, $secrets);
    }

    public function canonicalJson(): string
    {
        return $this->header->canonicalJson();
    }

    public function sha256(): string
    {
        return $this->header->sha256();
    }
}
