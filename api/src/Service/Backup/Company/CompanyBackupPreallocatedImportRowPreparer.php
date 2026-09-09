<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;

/** Připraví INSERT nad dříve zapečetěnou mapou cílových identit. */
final readonly class CompanyBackupPreallocatedImportRowPreparer
{
    private CompanyBackupTableProjection $projection;

    private CompanyBackupSourceIdentityProjection $identityProjection;

    private CompanyBackupRowReferenceTransformer $transformer;

    public function __construct(
        TenantDataDefinition $definition,
        private CompanyBackupTargetIdentityMap $identities,
        CompanyBackupReferenceResolutionPlan $resolutions,
        private CompanyBackupImportDependencyPlan $plan,
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
        private ?CompanyBackupSqlFilePathMap $filePaths = null,
    ) {
        $this->projection = CompanyBackupTableProjection::fromDefinition($definition);
        $this->identityProjection = CompanyBackupSourceIdentityProjection::fromDefinition(
            $definition,
            $limits,
        );
        if (!in_array($definition->policy, [
            TenantDataPolicy::TenantRoot,
            TenantDataPolicy::TenantOwned,
            TenantDataPolicy::TenantOwnedIndirect,
        ], true)
            || !$identities->isSealed()
            || !$plan->containsInsertRegistryKey($definition->key)
            || !hash_equals(
                $plan->registryFingerprint,
                $resolutions->targetRegistryFingerprint,
            )
        ) {
            throw self::error(
                'import_preallocated_row_context_mismatch',
                $definition->key,
            );
        }
        $this->transformer = new CompanyBackupRowReferenceTransformer(
            $identities,
            $resolutions,
            $limits,
        );
    }

    /**
     * @param array<string,mixed> $sourceRow
     * @param null|callable(CompanyBackupEmbeddedHashReference,string):mixed $hashMapper
     * @param null|callable(CompanyBackupHashReference,string):mixed $hashReferenceMapper
     */
    public function prepare(
        array $sourceRow,
        ?callable $hashMapper = null,
        ?callable $hashReferenceMapper = null,
    ): CompanyBackupPreparedImportRow {
        try {
            $sourceIdentity = $this->identityProjection->identityForRow($sourceRow);
            $primaryMatch = $this->identities->findMatch(
                $sourceIdentity->primaryKey,
            );
        } catch (
            CompanyBackupIdentityMapException
            |CompanyBackupPreflightException
            |\LogicException $e
        ) {
            throw self::error(
                'import_preallocated_identity_lookup_failed',
                $this->projection->registryKey,
                previous: $e,
            );
        }
        if (!$primaryMatch instanceof CompanyBackupTargetIdentityMatch
            || !$this->validPrimaryMatch($sourceIdentity, $primaryMatch)
        ) {
            throw self::error(
                'import_preallocated_target_identity_invalid',
                $this->projection->registryKey,
            );
        }

        $targetSeed = $this->projection->seedPreallocatedPrimaryKey(
            $sourceRow,
            $primaryMatch->targetPrimaryKey,
        );
        $rowMapper = $this->filePaths === null
            ? null
            : fn (array $row): array => $this->filePaths->transform(
                $this->projection,
                $sourceRow,
                $row,
                true,
            );
        $targetRow = $this->transformer->transformForInsert(
            $this->projection,
            $targetSeed,
            $this->plan,
            $hashMapper,
            $rowMapper,
            $hashReferenceMapper,
        );
        $targetIdentity = $this->identityProjection->identityForRow($targetRow);
        if (!$this->matchesPreallocatedIdentity(
            $sourceIdentity,
            $targetIdentity,
        )) {
            throw self::error(
                'import_preallocated_target_identity_invalid',
                $this->projection->registryKey,
            );
        }

        return new CompanyBackupPreparedImportRow(
            $targetRow,
            $sourceIdentity,
            $targetIdentity,
        );
    }

    private function validPrimaryMatch(
        CompanyBackupSourceIdentity $sourceIdentity,
        CompanyBackupTargetIdentityMatch $match,
    ): bool {
        return $match->sourceKey->equals($sourceIdentity->primaryKey)
            && $match->mappedKey->equals($match->targetPrimaryKey)
            && $match->targetPrimaryKey->registryKey
                === $this->projection->registryKey
            && $match->targetPrimaryKey->columns
                === $this->projection->primaryKey
            && $match->externalRequirementId === null;
    }

    private function matchesPreallocatedIdentity(
        CompanyBackupSourceIdentity $sourceIdentity,
        CompanyBackupSourceIdentity $targetIdentity,
    ): bool {
        if ($sourceIdentity->policy !== $targetIdentity->policy
            || $sourceIdentity->primaryKey->registryKey
                !== $targetIdentity->primaryKey->registryKey
        ) {
            return false;
        }
        foreach ($sourceIdentity->keys() as $sourceKey) {
            try {
                $match = $this->identities->findMatch($sourceKey);
            } catch (CompanyBackupIdentityMapException|\LogicException $e) {
                throw self::error(
                    'import_preallocated_identity_lookup_failed',
                    $this->projection->registryKey,
                    previous: $e,
                );
            }
            $targetKey = $this->keyWithColumns(
                $targetIdentity,
                $sourceKey->columns,
            );
            if (!$match instanceof CompanyBackupTargetIdentityMatch
                || !$targetKey instanceof CompanyBackupSourceKey
                || !$match->sourceKey->equals($sourceKey)
                || !$match->targetPrimaryKey->equals(
                    $targetIdentity->primaryKey,
                )
                || !$match->mappedKey->equals($targetKey)
                || $match->externalRequirementId !== null
            ) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $columns */
    private function keyWithColumns(
        CompanyBackupSourceIdentity $identity,
        array $columns,
    ): ?CompanyBackupSourceKey {
        foreach ($identity->keys() as $key) {
            if ($key->columns === $columns) {
                return $key;
            }
        }
        return null;
    }

    private static function error(
        string $errorCode,
        string $registryKey,
        ?string $column = null,
        ?\Throwable $previous = null,
    ): CompanyBackupImportWriteException {
        return new CompanyBackupImportWriteException(
            $errorCode,
            $registryKey,
            $column,
            $previous,
        );
    }
}
