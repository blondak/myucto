<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;

/**
 * Přidělí nový primární klíč, připraví první INSERT a atomicky
 * rozšíří diskovou mapu o všechny identity jednoho tenantového řádku.
 */
final class CompanyBackupImportRowPreparer
{
    private readonly CompanyBackupTableProjection $projection;

    private readonly CompanyBackupSourceIdentityProjection $identityProjection;

    private readonly CompanyBackupRowReferenceTransformer $transformer;

    private bool $closed = false;

    public function __construct(
        TenantDataDefinition $definition,
        CompanyBackupImportTableMetadata $metadata,
        private readonly ?CompanyBackupSqlPrimaryKeyReservation $primaryKeys,
        private readonly CompanyBackupTargetIdentityMap $identities,
        CompanyBackupReferenceResolutionPlan $resolutions,
        private readonly CompanyBackupImportDependencyPlan $plan,
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
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
            || !$plan->containsInsertRegistryKey($definition->key)
            || !hash_equals(
                $plan->registryFingerprint,
                $resolutions->targetRegistryFingerprint,
            )
        ) {
            throw self::error('import_row_context_mismatch', $definition->key);
        }

        $autoIncrement = $metadata->autoIncrement;
        if ($autoIncrement !== null && $primaryKeys === null) {
            throw self::error(
                'import_primary_key_reservation_missing',
                $definition->key,
                $autoIncrement->column,
            );
        }
        if ($autoIncrement === null && $primaryKeys !== null) {
            throw self::error(
                'import_primary_key_reservation_unexpected',
                $definition->key,
            );
        }
        if ($autoIncrement !== null) {
            $primaryKeys->assertScope($this->projection, $autoIncrement);
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
    ): CompanyBackupPreparedImportRow {
        if ($this->closed) {
            throw self::error(
                'import_row_preparer_closed',
                $this->projection->registryKey,
            );
        }

        $sourceIdentity = $this->identityProjection->identityForRow($sourceRow);
        $targetSeed = $sourceRow;
        $autoIncrement = $this->primaryKeys;
        if ($autoIncrement !== null) {
            $column = $this->projection->primaryKey[0];
            $targetSeed[$column] = $autoIncrement->next();
        }
        $targetRow = $this->transformer->transformForInsert(
            $this->projection,
            $targetSeed,
            $this->plan,
            $hashMapper,
        );
        $targetIdentity = $this->identityProjection->identityForRow($targetRow);
        $this->identities->add($sourceIdentity, $targetIdentity);

        return new CompanyBackupPreparedImportRow(
            $targetRow,
            $sourceIdentity,
            $targetIdentity,
        );
    }

    public function finish(): void
    {
        if ($this->closed) {
            throw self::error(
                'import_row_preparer_closed',
                $this->projection->registryKey,
            );
        }
        $this->primaryKeys?->finish();
        $this->closed = true;
    }

    private static function error(
        string $errorCode,
        string $registryKey,
        ?string $column = null,
    ): CompanyBackupImportWriteException {
        return new CompanyBackupImportWriteException(
            $errorCode,
            $registryKey,
            $column,
        );
    }
}
