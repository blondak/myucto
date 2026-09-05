<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;

/**
 * Z původního payloadu reprodukuje vložený stav a cílový stav druhého průchodu.
 * Výstup omezuje na registry-driven allowlist odložených fyzických sloupců.
 */
final readonly class CompanyBackupDeferredRowPreparer
{
    private CompanyBackupTableProjection $projection;

    private CompanyBackupSourceIdentityProjection $identityProjection;

    private CompanyBackupRowReferenceTransformer $transformer;

    private CompanyBackupDeferredColumnSet $deferredColumns;

    private int $maxSourceKeyBytes;

    public function __construct(
        TenantDataDefinition $definition,
        private CompanyBackupTargetIdentityMap $identities,
        CompanyBackupReferenceResolutionPlan $resolutions,
        private CompanyBackupImportDependencyPlan $plan,
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {
        $this->projection = CompanyBackupTableProjection::fromDefinition($definition);
        $this->identityProjection = CompanyBackupSourceIdentityProjection::fromDefinition(
            $definition,
            $limits,
        );
        $this->maxSourceKeyBytes = $limits->maxSourceKeyBytes;
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
                'import_deferred_context_mismatch',
                $definition->key,
            );
        }
        $this->deferredColumns = CompanyBackupDeferredColumnSet::fromProjection(
            $this->projection,
            $plan,
        );
        if ($this->deferredColumns->columns === []) {
            throw self::error(
                'import_deferred_columns_empty',
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
     */
    public function prepare(
        array $sourceRow,
        ?callable $hashMapper = null,
    ): CompanyBackupPreparedDeferredUpdate {
        try {
            $sourceIdentity = $this->identityProjection->identityForRow($sourceRow);
            $match = $this->identities->findMatch($sourceIdentity->primaryKey);
        } catch (
            CompanyBackupIdentityMapException
            |CompanyBackupPreflightException
            |\LogicException $e
        ) {
            throw self::error(
                'import_deferred_identity_lookup_failed',
                $this->projection->registryKey,
                previous: $e,
            );
        }
        if (!$match instanceof CompanyBackupTargetIdentityMatch
            || !$match->sourceKey->equals($sourceIdentity->primaryKey)
            || !$match->mappedKey->equals($match->targetPrimaryKey)
            || $match->targetPrimaryKey->registryKey
                !== $this->projection->registryKey
            || $match->targetPrimaryKey->columns
                !== $this->projection->primaryKey
            || $match->externalRequirementId !== null
        ) {
            throw self::error(
                'import_deferred_target_identity_invalid',
                $this->projection->registryKey,
            );
        }

        $targetSeed = $sourceRow;
        foreach ($match->targetPrimaryKey->values as $column => $value) {
            $targetSeed[$column] = $value;
        }
        $stableHashMapper = $this->stableHashMapper($hashMapper);
        try {
            $before = $this->transformer->transformForInsert(
                $this->projection,
                $targetSeed,
                $this->plan,
                $stableHashMapper,
            );
            $after = $this->transformer->transform(
                $this->projection,
                $targetSeed,
                $stableHashMapper,
            );
        } catch (CompanyBackupRowTransformException|\LogicException $e) {
            throw self::error(
                'import_deferred_transform_failed',
                $this->projection->registryKey,
                $e instanceof CompanyBackupRowTransformException
                    ? $e->column
                    : null,
                $e,
            );
        }
        if (array_keys($before) !== $this->projection->dataColumns
            || array_keys($after) !== $this->projection->dataColumns
        ) {
            throw self::error(
                'import_deferred_transform_scope_invalid',
                $this->projection->registryKey,
            );
        }

        $allowed = array_fill_keys($this->deferredColumns->columns, true);
        foreach ($this->projection->dataColumns as $column) {
            if ($before[$column] !== $after[$column] && !isset($allowed[$column])) {
                throw self::error(
                    'import_deferred_transform_scope_invalid',
                    $this->projection->registryKey,
                    $column,
                );
            }
        }
        try {
            $beforePrimaryKey = CompanyBackupSourceKey::fromRow(
                $this->projection->registryKey,
                $this->projection->primaryKey,
                $before,
                $this->maxSourceKeyBytes,
            );
            $afterPrimaryKey = CompanyBackupSourceKey::fromRow(
                $this->projection->registryKey,
                $this->projection->primaryKey,
                $after,
                $this->maxSourceKeyBytes,
            );
        } catch (CompanyBackupPreflightException $e) {
            throw self::error(
                'import_deferred_target_identity_invalid',
                $this->projection->registryKey,
                previous: $e,
            );
        }
        if (!$beforePrimaryKey->equals($match->targetPrimaryKey)
            || !$afterPrimaryKey->equals($match->targetPrimaryKey)
        ) {
            throw self::error(
                'import_deferred_target_identity_invalid',
                $this->projection->registryKey,
            );
        }

        $beforeValues = [];
        $afterValues = [];
        foreach ($this->deferredColumns->columns as $column) {
            $beforeValues[$column] = $before[$column];
            $afterValues[$column] = $after[$column];
        }
        return new CompanyBackupPreparedDeferredUpdate(
            $match->targetPrimaryKey,
            $beforeValues,
            $afterValues,
        );
    }

    /**
     * @param null|callable(CompanyBackupEmbeddedHashReference,string):mixed $mapper
     * @return null|callable(CompanyBackupEmbeddedHashReference,string):mixed
     */
    private function stableHashMapper(?callable $mapper): ?callable
    {
        if ($mapper === null) {
            return null;
        }
        $mapped = [];
        return static function (
            CompanyBackupEmbeddedHashReference $reference,
            string $hash,
        ) use ($mapper, &$mapped): mixed {
            $key = $reference->signature() . "\0" . $hash;
            if (!array_key_exists($key, $mapped)) {
                $mapped[$key] = $mapper($reference, $hash);
            }
            return $mapped[$key];
        };
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
