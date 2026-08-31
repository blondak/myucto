<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/** Přesný DB a at-rest kontrakt výslovně vybraných credential sloupců. */
final readonly class CompanyBackupOptionalSecretProjection
{
    /** @var list<string> */
    public array $primaryKey;

    /** @var list<CompanyBackupSecretSelectionEntry> */
    public array $entries;

    /** @var array<string,CompanyBackupSecretStorage> */
    public array $storage;

    /** @var array<string,?string> */
    public array $contexts;

    /**
     * @param list<string> $primaryKey
     * @param list<CompanyBackupSecretSelectionEntry> $entries
     * @param array<string,CompanyBackupSecretStorage> $storage
     * @param array<string,?string> $contexts
     */
    private function __construct(
        public string $registryKey,
        public string $name,
        public TenantDataPolicy $policy,
        array $primaryKey,
        public string $ownershipColumn,
        array $entries,
        array $storage,
        array $contexts,
    ) {
        $this->primaryKey = $primaryKey;
        $this->entries = $entries;
        $this->storage = $storage;
        $this->contexts = $contexts;
    }

    /** @param list<CompanyBackupSecretSelectionEntry> $entries */
    public static function fromSelection(
        TenantDataDefinition $definition,
        array $entries,
    ): self {
        if ($definition->kind !== TenantDataObjectKind::Table
            || !$definition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
            || !$definition->policy->hasMachineDataPayload()
            || $entries === []
        ) {
            throw self::error('secret_selection_object_unsupported', $definition->key);
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
        $storage = [];
        $contexts = [];
        $seen = [];
        $expectedPrimaryKey = $primaryKey;
        sort($expectedPrimaryKey, SORT_STRING);
        foreach ($entries as $entry) {
            $entryPrimaryKey = array_keys($entry->primaryKey);
            if ($entry->registryKey !== $definition->key
                || $entry->scope !== CompanyBackupSecretScope::Column
                || $entry->policy !== TenantSecretPolicy::OptionalCredential
                || ($policies[$entry->name] ?? null) !== $entry->policy
                || in_array($entry->name, $primaryKey, true)
                || $entryPrimaryKey !== $expectedPrimaryKey
            ) {
                throw self::error('secret_selection_scope_mismatch', $definition->key);
            }
            $signature = $entry->valueSignature();
            if (isset($seen[$signature])) {
                throw self::error('secret_selection_duplicate', $definition->key);
            }
            $seen[$signature] = true;
            if (!isset($storage[$entry->name])) {
                $contract = CompanyBackupSecretStorageContract::fromMetadata(
                    $secretMetadata[$entry->name] ?? null,
                    $definition->key,
                    $entry->name,
                );
                $storage[$entry->name] = $contract->storage;
                $contexts[$entry->name] = $contract->context;
            }
        }
        ksort($storage, SORT_STRING);
        ksort($contexts, SORT_STRING);
        usort(
            $entries,
            static fn (
                CompanyBackupSecretSelectionEntry $left,
                CompanyBackupSecretSelectionEntry $right,
            ): int => strcmp($left->valueSignature(), $right->valueSignature()),
        );

        return new self(
            $definition->key,
            $definition->name(),
            $definition->policy,
            $primaryKey,
            $ownershipColumn,
            $entries,
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
            ...array_keys($this->storage),
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
        foreach (array_keys($this->storage) as $column) {
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
    public function selectedColumns(
        CompanyBackupSecretSelectionEntry $entry,
    ): array {
        if ($entry->registryKey !== $this->registryKey
            || !isset($this->storage[$entry->name])
        ) {
            throw self::error('secret_selection_scope_mismatch', $this->registryKey);
        }
        return [...$this->primaryKey, $entry->name];
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
        foreach ($value as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || in_array($column, $result, true)
            ) {
                throw self::error('secret_source_primary_key_invalid', $registryKey);
            }
            $result[] = $column;
        }
        return $result;
    }

    private static function error(
        string $code,
        string $registryKey,
        ?string $column = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException($code, $registryKey, $column);
    }
}
