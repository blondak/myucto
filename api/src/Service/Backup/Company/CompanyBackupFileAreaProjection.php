<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;

/** Exportní kontrakt runtime kořene jedné registrované souborové oblasti. */
final readonly class CompanyBackupFileAreaProjection
{
    private function __construct(
        public string $registryKey,
        public string $name,
        public CompanyBackupFilePolicy $policy,
        public string $storageSubdirectory,
    ) {}

    public static function fromDefinition(TenantDataDefinition $definition): self
    {
        $subdirectory = $definition->details['storage_subdirectory'] ?? null;
        $ownership = $definition->details['ownership'] ?? null;
        if ($definition->kind !== TenantDataObjectKind::FileArea
            || !$definition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
            || !$definition->policy->hasMachineDataPayload()
            || !is_string($subdirectory)
            || !self::validSubdirectory($subdirectory)
            || !is_array($ownership)
            || array_is_list($ownership)
            || ($ownership['strategy'] ?? null) !== 'database_references'
        ) {
            throw new CompanyBackupFileSourceException(
                'file_area_metadata_invalid',
                $definition->key,
            );
        }
        try {
            $policy = CompanyBackupFilePolicy::fromDefinition($definition);
        } catch (\InvalidArgumentException $e) {
            throw new CompanyBackupFileSourceException(
                'file_area_metadata_invalid',
                $definition->key,
                previous: $e,
            );
        }
        return new self(
            $definition->key,
            $definition->name(),
            $policy,
            $subdirectory,
        );
    }

    private static function validSubdirectory(string $value): bool
    {
        if ($value === ''
            || strlen($value) > 255
            || str_starts_with($value, '/')
            || str_ends_with($value, '/')
            || str_contains($value, '\\')
            || preg_match('/\A[A-Za-z]:/', $value) === 1
        ) {
            return false;
        }
        foreach (explode('/', $value) as $segment) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $segment) !== 1
                || $segment === '.'
                || $segment === '..'
                || str_ends_with($segment, '.')
                || str_ends_with($segment, ' ')
            ) {
                return false;
            }
        }
        return true;
    }
}
