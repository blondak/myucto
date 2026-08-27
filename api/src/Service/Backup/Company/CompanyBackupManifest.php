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
        return new self($header, $registry);
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
