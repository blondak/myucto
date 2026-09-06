<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Mail\SafeLogoPath;

/** Tenantově specifické omezení cesty uvnitř registrované souborové oblasti. */
enum CompanyBackupFilePathPolicy: string
{
    case Relative = 'relative';
    case SupplierLogo = 'supplier_logo';

    public static function fromDefinition(TenantDataDefinition $definition): self
    {
        $value = $definition->details['path_policy'] ?? null;
        $policy = is_string($value) ? self::tryFrom($value) : null;
        if ($definition->kind !== TenantDataObjectKind::FileArea
            || !$definition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
            || $policy === null
        ) {
            throw new \InvalidArgumentException(
                'Souborová oblast ' . $definition->key . ' nemá platnou path_policy.',
            );
        }
        return $policy;
    }

    public function accepts(string $sourcePath, int $supplierId): bool
    {
        return match ($this) {
            self::Relative => true,
            self::SupplierLogo => SafeLogoPath::isAllowedSourcePath(
                $sourcePath,
                $supplierId,
            ),
        };
    }

    public function restoreTargetPath(
        string $sourcePath,
        int $sourceSupplierId,
        int $targetSupplierId,
    ): string {
        $sourcePath = CompanyBackupFileEntry::normalizeSourcePath($sourcePath);
        if ($sourceSupplierId < 1
            || $targetSupplierId < 1
            || !$this->accepts($sourcePath, $sourceSupplierId)
        ) {
            throw new \InvalidArgumentException(
                'Zdrojová cesta souboru neodpovídá obnovované firmě.',
            );
        }
        if ($this === self::Relative) {
            return $sourcePath;
        }

        $sourcePrefix = 'sup-' . $sourceSupplierId;
        if (!str_starts_with($sourcePath, $sourcePrefix)) {
            throw new \InvalidArgumentException(
                'Zdrojová cesta loga nemá tenantový prefix.',
            );
        }
        $targetPath = 'sup-' . $targetSupplierId
            . substr($sourcePath, strlen($sourcePrefix));
        if (!$this->accepts($targetPath, $targetSupplierId)) {
            throw new \InvalidArgumentException(
                'Cílovou cestu loga nelze bezpečně odvodit.',
            );
        }
        return $targetPath;
    }
}
