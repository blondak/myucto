<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;

/** Mapuje read-only globální payload na již ověřené cílové řádky. */
final readonly class CompanyBackupGlobalIdentityMapper
{
    private CompanyBackupSourceIdentityProjection $identityProjection;

    public function __construct(
        TenantDataDefinition $definition,
        private CompanyBackupTargetIdentityMap $identities,
        private CompanyBackupReferenceResolutionPlan $resolutions,
        CompanyBackupImportDependencyPlan $plan,
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {
        $this->identityProjection = CompanyBackupSourceIdentityProjection::fromDefinition(
            $definition,
            $limits,
        );
        if ($definition->policy !== TenantDataPolicy::GlobalReference
            || $identities->isSealed()
            || !$plan->containsGlobalRegistryKey($definition->key)
            || $this->identityProjection->naturalKeyColumns === null
            || $this->identityProjection->referenceKeyColumns !== []
            || !hash_equals(
                $plan->registryFingerprint,
                $resolutions->targetRegistryFingerprint,
            )
        ) {
            throw self::error('import_global_context_mismatch', $definition->key);
        }
    }

    /** @param array<string,mixed> $sourceRow */
    public function map(array $sourceRow): void
    {
        $sourceIdentity = $this->identityProjection->identityForRow($sourceRow);
        $naturalKey = $sourceIdentity->naturalKey;
        if ($naturalKey === null) {
            throw self::error(
                'import_global_identity_invalid',
                $this->identityProjection->registryKey,
            );
        }
        $requirementId = CompanyBackupExternalReferenceRequirement::idFor(
            CompanyBackupReferenceMapping::GlobalNaturalKey,
            $this->identityProjection->registryKey,
            $naturalKey->values,
        );
        $resolution = $this->resolutions->resolution($requirementId);
        $targetPrimaryKey = $resolution?->targetPrimaryKey;
        if (!$resolution instanceof CompanyBackupReferenceResolution
            || $resolution->decision->mapping
                !== CompanyBackupReferenceMapping::GlobalNaturalKey
            || $resolution->decision->targetRegistryKey
                !== $this->identityProjection->registryKey
            || $resolution->decision->action
                !== CompanyBackupReferenceDecisionAction::MapExisting
            || !is_array($targetPrimaryKey)
        ) {
            throw self::error(
                'import_global_resolution_invalid',
                $this->identityProjection->registryKey,
            );
        }

        $actualColumns = array_keys($targetPrimaryKey);
        $expectedColumns = $this->identityProjection->primaryKeyColumns;
        if ($actualColumns !== $expectedColumns) {
            throw self::error(
                'import_global_resolution_invalid',
                $this->identityProjection->registryKey,
            );
        }
        $targetRow = $sourceRow;
        foreach ($targetPrimaryKey as $column => $value) {
            $targetRow[$column] = $value;
        }
        $targetIdentity = $this->identityProjection->identityForRow($targetRow);
        $this->identities->add($sourceIdentity, $targetIdentity);
    }

    private static function error(
        string $errorCode,
        string $registryKey,
    ): CompanyBackupImportWriteException {
        return new CompanyBackupImportWriteException($errorCode, $registryKey);
    }
}
