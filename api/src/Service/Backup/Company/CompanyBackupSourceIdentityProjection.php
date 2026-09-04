<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;

/** Registry-driven projekce řádku do primárních a obnovovacích klíčů. */
final readonly class CompanyBackupSourceIdentityProjection
{
    /** @var list<string> */
    public array $primaryKeyColumns;

    /** @var list<string>|null */
    public ?array $tenantScopedPrimaryKeyColumns;

    /** @var list<string>|null */
    public ?array $naturalKeyColumns;

    /** @var list<list<string>> */
    public array $referenceKeyColumns;

    /**
     * @param list<string> $primaryKeyColumns
     * @param list<string>|null $tenantScopedPrimaryKeyColumns
     * @param list<string>|null $naturalKeyColumns
     * @param list<list<string>> $referenceKeyColumns
     */
    private function __construct(
        public string $registryKey,
        public TenantDataPolicy $policy,
        array $primaryKeyColumns,
        ?array $tenantScopedPrimaryKeyColumns,
        ?array $naturalKeyColumns,
        array $referenceKeyColumns,
        private int $maxSourceKeyBytes,
    ) {
        $this->primaryKeyColumns = $primaryKeyColumns;
        $this->tenantScopedPrimaryKeyColumns = $tenantScopedPrimaryKeyColumns;
        $this->naturalKeyColumns = $naturalKeyColumns;
        $this->referenceKeyColumns = $referenceKeyColumns;
    }

    public static function fromDefinition(
        TenantDataDefinition $definition,
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ): self {
        try {
            $projection = CompanyBackupTableProjection::fromDefinition($definition);
        } catch (CompanyBackupDataSourceException $e) {
            throw new CompanyBackupPreflightException(
                $e->errorCode,
                $e->registryKey,
                $e->column,
                $e,
            );
        }

        $exported = array_fill_keys($projection->dataColumns, true);
        $naturalKey = self::optionalKey(
            $definition->details,
            'natural_key',
            $definition->key,
            $exported,
        );
        $referenceKeys = self::referenceKeys(
            $definition->details,
            $definition->key,
            $exported,
        );
        if ($definition->policy === TenantDataPolicy::GlobalReference
            && $naturalKey === null
        ) {
            throw new CompanyBackupPreflightException(
                'source_key_metadata_invalid',
                $definition->key,
            );
        }

        $tenantScopedPrimaryKey = null;
        if (in_array($definition->policy, [
            TenantDataPolicy::TenantRoot,
            TenantDataPolicy::TenantOwned,
            TenantDataPolicy::TenantOwnedIndirect,
        ], true)
            && isset($exported['supplier_id'])
            && !in_array('supplier_id', $projection->primaryKey, true)
        ) {
            $tenantScopedPrimaryKey = [
                'supplier_id',
                ...$projection->primaryKey,
            ];
        }

        $sourceKeyCount = 1
            + ($tenantScopedPrimaryKey === null ? 0 : 1)
            + ($naturalKey === null ? 0 : 1)
            + count($referenceKeys);
        if ($sourceKeyCount > $limits->maxSourceKeysPerRow) {
            throw new CompanyBackupPreflightException(
                'source_key_count_exceeded',
                $definition->key,
            );
        }

        return new self(
            $definition->key,
            $definition->policy,
            $projection->primaryKey,
            $tenantScopedPrimaryKey,
            $naturalKey,
            $referenceKeys,
            $limits->maxSourceKeyBytes,
        );
    }

    /** @param array<string,mixed> $row */
    public function identityForRow(array $row): CompanyBackupSourceIdentity
    {
        $primaryKey = CompanyBackupSourceKey::fromRow(
            $this->registryKey,
            $this->primaryKeyColumns,
            $row,
            $this->maxSourceKeyBytes,
        );
        $tenantScopedPrimaryKey = $this->tenantScopedPrimaryKeyColumns === null
            ? null
            : CompanyBackupSourceKey::fromRow(
                $this->registryKey,
                $this->tenantScopedPrimaryKeyColumns,
                $row,
                $this->maxSourceKeyBytes,
            );
        $naturalKey = $this->naturalKeyColumns === null
            ? null
            : CompanyBackupSourceKey::fromRow(
                $this->registryKey,
                $this->naturalKeyColumns,
                $row,
                $this->maxSourceKeyBytes,
            );
        $referenceKeys = array_map(
            fn (array $columns): CompanyBackupSourceKey =>
                CompanyBackupSourceKey::fromRow(
                    $this->registryKey,
                    $columns,
                    $row,
                    $this->maxSourceKeyBytes,
                ),
            $this->referenceKeyColumns,
        );

        return new CompanyBackupSourceIdentity(
            $this->policy,
            $primaryKey,
            $tenantScopedPrimaryKey,
            $naturalKey,
            $referenceKeys,
        );
    }

    /**
     * @param array<string,mixed> $details
     * @param array<string,true> $exported
     * @return list<string>|null
     */
    private static function optionalKey(
        array $details,
        string $field,
        string $registryKey,
        array $exported,
    ): ?array {
        if (!array_key_exists($field, $details)) {
            return null;
        }
        return self::key($details[$field], $registryKey, $exported);
    }

    /**
     * @param array<string,true> $exported
     * @return list<string>
     */
    private static function key(
        mixed $value,
        string $registryKey,
        array $exported,
    ): array {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new CompanyBackupPreflightException(
                'source_key_metadata_invalid',
                $registryKey,
            );
        }
        $columns = [];
        foreach ($value as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || isset($columns[$column])
                || !isset($exported[$column])
            ) {
                throw new CompanyBackupPreflightException(
                    'source_key_metadata_invalid',
                    $registryKey,
                    is_string($column) ? $column : null,
                );
            }
            $columns[$column] = true;
        }
        return array_keys($columns);
    }

    /**
     * @param array<string,mixed> $details
     * @param array<string,true> $exported
     * @return list<list<string>>
     */
    private static function referenceKeys(
        array $details,
        string $registryKey,
        array $exported,
    ): array {
        if (!array_key_exists('reference_keys', $details)) {
            return [];
        }
        $value = $details['reference_keys'];
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new CompanyBackupPreflightException(
                'source_key_metadata_invalid',
                $registryKey,
            );
        }
        $keys = [];
        $signatures = [];
        foreach ($value as $item) {
            $key = self::key($item, $registryKey, $exported);
            $signature = implode(',', $key);
            if (count($key) < 2
                || $key[0] !== 'supplier_id'
                || isset($signatures[$signature])
            ) {
                throw new CompanyBackupPreflightException(
                    'source_key_metadata_invalid',
                    $registryKey,
                );
            }
            $signatures[$signature] = true;
            $keys[] = $key;
        }
        $ordered = $keys;
        usort(
            $ordered,
            static fn (array $left, array $right): int => strcmp(
                implode(',', $left),
                implode(',', $right),
            ),
        );
        if ($ordered !== $keys) {
            throw new CompanyBackupPreflightException(
                'source_key_metadata_invalid',
                $registryKey,
            );
        }
        return $keys;
    }
}
