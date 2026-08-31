<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/** Přesný DB a at-rest kontrakt povinných doménových secretů jedné tabulky. */
final readonly class CompanyBackupProtectedSecretProjection
{
    /** @var list<string> */
    public array $primaryKey;

    /** @var list<string> */
    public array $columns;

    /** @var array<string,CompanyBackupSecretStorage> */
    public array $storage;

    /** @var array<string,?string> */
    public array $contexts;

    /**
     * @param list<string> $primaryKey
     * @param list<string> $columns
     * @param array<string,CompanyBackupSecretStorage> $storage
     * @param array<string,?string> $contexts
     */
    private function __construct(
        public string $registryKey,
        public string $name,
        public TenantDataPolicy $policy,
        array $primaryKey,
        public string $ownershipColumn,
        array $columns,
        array $storage,
        array $contexts,
    ) {
        $this->primaryKey = $primaryKey;
        $this->columns = $columns;
        $this->storage = $storage;
        $this->contexts = $contexts;
    }

    public static function fromDefinition(
        TenantDataDefinition $definition,
    ): self {
        if ($definition->kind !== TenantDataObjectKind::Table
            || !$definition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
            || !$definition->policy->hasMachineDataPayload()
        ) {
            throw self::error('secret_source_object_unsupported', $definition->key);
        }
        $primaryKey = self::identifierList(
            $definition->details['primary_key'] ?? null,
            $definition->key,
        );
        $ownership = $definition->details['ownership'] ?? null;
        if (!is_array($ownership) || array_is_list($ownership)) {
            throw self::error('secret_source_ownership_invalid', $definition->key);
        }
        $ownershipKeys = array_keys($ownership);
        sort($ownershipKeys, SORT_STRING);
        $strategy = $ownership['strategy'] ?? null;
        $ownershipColumn = $ownership['column'] ?? null;
        if ($ownershipKeys !== ['column', 'strategy']
            || !is_string($strategy)
            || !in_array($strategy, ['selected_supplier', 'supplier_id'], true)
            || !is_string($ownershipColumn)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $ownershipColumn) !== 1
            || ($strategy === 'selected_supplier'
                && ($definition->policy !== TenantDataPolicy::TenantRoot
                    || !in_array($ownershipColumn, $primaryKey, true)))
            || ($strategy === 'supplier_id'
                && $definition->policy === TenantDataPolicy::TenantRoot)
        ) {
            throw self::error('secret_source_ownership_invalid', $definition->key);
        }

        $secretMetadata = $definition->details['secrets'] ?? null;
        $policies = CompanyBackupSecretColumnSet::fromArray(
            $secretMetadata,
            $definition->key,
        )->policies;
        if (!is_array($secretMetadata)) {
            throw self::error('secret_source_storage_missing', $definition->key);
        }
        $columns = [];
        $storage = [];
        $contexts = [];
        foreach ($policies as $column => $policy) {
            if ($policy !== TenantSecretPolicy::ProtectedDomainSecret) {
                continue;
            }
            $metadata = $secretMetadata[$column] ?? null;
            if (!is_array($metadata) || array_is_list($metadata)) {
                throw self::error(
                    'secret_source_storage_missing',
                    $definition->key,
                    $column,
                );
            }
            $storageValue = $metadata['storage'] ?? null;
            $columnStorage = is_string($storageValue)
                ? CompanyBackupSecretStorage::tryFrom($storageValue)
                : null;
            if ($columnStorage === null) {
                throw self::error(
                    'secret_source_storage_missing',
                    $definition->key,
                    $column,
                );
            }
            $metadataKeys = array_keys($metadata);
            sort($metadataKeys, SORT_STRING);
            $context = $metadata['context'] ?? null;
            if ($columnStorage === CompanyBackupSecretStorage::ApplicationEncryptedContext) {
                if ($metadataKeys !== ['context', 'policy', 'storage']
                    || !is_string($context)
                    || preg_match('/^[a-z][a-z0-9:._-]{0,127}$/D', $context) !== 1
                ) {
                    throw self::error(
                        'secret_source_storage_invalid',
                        $definition->key,
                        $column,
                    );
                }
            } elseif ($metadataKeys !== ['policy', 'storage'] || $context !== null) {
                throw self::error(
                    'secret_source_storage_invalid',
                    $definition->key,
                    $column,
                );
            }
            $columns[] = $column;
            $storage[$column] = $columnStorage;
            $contexts[$column] = is_string($context) ? $context : null;
        }
        if ($columns === []) {
            throw self::error('secret_source_projection_empty', $definition->key);
        }

        return new self(
            $definition->key,
            $definition->name(),
            $definition->policy,
            $primaryKey,
            $ownershipColumn,
            $columns,
            $storage,
            $contexts,
        );
    }

    public function assertRuntimeSchema(CompanyBackupTableSchema $schema): void
    {
        if ($schema->primaryKey !== $this->primaryKey) {
            throw self::error('secret_source_primary_key_mismatch', $this->registryKey);
        }
        $available = array_fill_keys($schema->columns, true);
        foreach ([
            ...$this->primaryKey,
            $this->ownershipColumn,
            ...$this->columns,
        ] as $column) {
            if (!isset($available[$column])) {
                throw self::error(
                    'secret_source_schema_invalid',
                    $this->registryKey,
                    $column,
                );
            }
        }
        $generated = array_fill_keys($schema->generatedColumns, true);
        foreach ($this->columns as $column) {
            if (isset($generated[$column])) {
                throw self::error(
                    'secret_source_schema_invalid',
                    $this->registryKey,
                    $column,
                );
            }
        }
    }

    /** @return list<string> */
    public function selectedColumns(): array
    {
        $columns = $this->primaryKey;
        foreach ($this->columns as $column) {
            if (!in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }
        return $columns;
    }

    /** @return list<string> */
    private static function identifierList(mixed $value, string $registryKey): array
    {
        if (!is_array($value)
            || !array_is_list($value)
            || $value === []
            || count($value) > 16
        ) {
            throw self::error('secret_source_primary_key_invalid', $registryKey);
        }
        $result = [];
        $seen = [];
        foreach ($value as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || isset($seen[$column])
            ) {
                throw self::error('secret_source_primary_key_invalid', $registryKey);
            }
            $seen[$column] = true;
            $result[] = $column;
        }
        return $result;
    }

    private static function error(
        string $code,
        string $registryKey,
        ?string $column = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            $code,
            $registryKey,
            $column,
        );
    }
}
