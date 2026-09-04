<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataPolicy;

/** Ověří cíl zdrojové reference a global ID převede na natural key. */
final readonly class CompanyBackupReferenceIntegrityValidator
{
    public function __construct(
        private CompanyBackupSourceIdentityLookup $identities,
        private CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {}

    public function normalize(
        CompanyBackupReferenceOccurrence $occurrence,
    ): CompanyBackupReferenceOccurrence {
        if (in_array($occurrence->mapping, [
            CompanyBackupReferenceMapping::Actor,
            CompanyBackupReferenceMapping::CredentialDecision,
        ], true)) {
            return $occurrence;
        }

        $key = CompanyBackupSourceKey::fromValues(
            $occurrence->targetRegistryKey,
            $occurrence->sourceKey,
            $this->limits->maxSourceKeyBytes,
        );
        $identity = $this->identities->find($key);
        if ($identity === null) {
            throw $this->error('source_reference_unresolved', $occurrence);
        }

        $validPolicy = match ($occurrence->mapping) {
            CompanyBackupReferenceMapping::GlobalNaturalKey =>
                $identity->policy === TenantDataPolicy::GlobalReference,
            default => in_array($identity->policy, [
                TenantDataPolicy::TenantRoot,
                TenantDataPolicy::TenantOwned,
                TenantDataPolicy::TenantOwnedIndirect,
            ], true),
        };
        if (!$validPolicy) {
            throw $this->error('source_reference_policy_mismatch', $occurrence);
        }

        $global = $occurrence->mapping
            === CompanyBackupReferenceMapping::GlobalNaturalKey;
        $matches = match ($occurrence->mapping) {
            CompanyBackupReferenceMapping::TenantId =>
                $identity->hasTenantIdKey($key),
            CompanyBackupReferenceMapping::TenantIdOrZero =>
                $identity->hasPrimaryKey($key),
            CompanyBackupReferenceMapping::TenantReferenceKey =>
                $identity->hasReferenceKey($key),
            CompanyBackupReferenceMapping::TenantNaturalKey =>
                $identity->hasNaturalKey($key),
            default =>
                $identity->hasPrimaryKey($key) || $identity->hasNaturalKey($key),
        };
        if (!$matches) {
            throw $this->error('source_reference_key_mismatch', $occurrence);
        }
        if (!$global) {
            return $occurrence;
        }
        if ($identity->naturalKey === null) {
            throw $this->error(
                'source_global_natural_key_missing',
                $occurrence,
            );
        }
        return $occurrence->withSourceKey($identity->naturalKey);
    }

    private function error(
        string $errorCode,
        CompanyBackupReferenceOccurrence $occurrence,
    ): CompanyBackupPreflightException {
        return new CompanyBackupPreflightException(
            $errorCode,
            $occurrence->sourceRegistryKey,
            $occurrence->sourceColumn,
        );
    }
}
