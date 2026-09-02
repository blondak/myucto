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

    /** @var array<string,CompanyBackupSecretContextTemplate|null> */
    private array $contextTemplates;

    /** @var array<string,CompanyBackupPayrollSensitiveContext|null> */
    private array $payrollSensitiveContexts;

    /**
     * @param list<string> $primaryKey
     * @param list<string> $columns
     * @param array<string,CompanyBackupSecretStorage> $storage
     * @param array<string,?string> $contexts
     * @param array<string,CompanyBackupSecretContextTemplate|null> $contextTemplates
     * @param array<string,CompanyBackupPayrollSensitiveContext|null> $payrollSensitiveContexts
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
        array $contextTemplates,
        array $payrollSensitiveContexts,
    ) {
        $this->primaryKey = $primaryKey;
        $this->columns = $columns;
        $this->storage = $storage;
        $this->contexts = $contexts;
        $this->contextTemplates = $contextTemplates;
        $this->payrollSensitiveContexts = $payrollSensitiveContexts;
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
        $contextTemplates = [];
        $payrollSensitiveContexts = [];
        $dataColumns = self::companyBackupDataColumns($definition);
        $allowedContextColumns = array_values(array_unique([
            ...$primaryKey,
            $ownershipColumn,
        ]));
        foreach ($policies as $column => $policy) {
            if ($policy !== TenantSecretPolicy::ProtectedDomainSecret) {
                continue;
            }
            $metadata = $secretMetadata[$column] ?? null;
            $contract = CompanyBackupSecretStorageContract::fromMetadata(
                $metadata,
                $definition->key,
                $column,
            );
            $columns[] = $column;
            $storage[$column] = $contract->storage;
            $contexts[$column] = $contract->context;
            $contract->contextTemplate?->assertAllowedColumns(
                $allowedContextColumns,
                $definition->key,
                $column,
            );
            if ($contract->payrollSensitiveContext !== null
                && !$contract->payrollSensitiveContext->hasValidCoordinates(
                    $primaryKey,
                    $ownershipColumn,
                    $dataColumns,
                )
            ) {
                throw self::error(
                    'secret_source_storage_invalid',
                    $definition->key,
                    $column,
                );
            }
            $contextTemplates[$column] = $contract->contextTemplate;
            $payrollSensitiveContexts[$column] =
                $contract->payrollSensitiveContext;
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
            $contextTemplates,
            $payrollSensitiveContexts,
        );
    }

    public function assertRuntimeSchema(CompanyBackupTableSchema $schema): void
    {
        if ($schema->primaryKey !== $this->primaryKey) {
            throw self::error('secret_source_primary_key_mismatch', $this->registryKey);
        }
        $available = array_fill_keys($schema->columns, true);
        foreach ([
            $this->ownershipColumn,
            ...$this->selectedColumns(),
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
        foreach ($this->contextTemplates as $template) {
            if ($template === null) {
                continue;
            }
            foreach ($template->columns as $column) {
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }
        foreach ($this->payrollSensitiveContexts as $context) {
            if ($context === null) {
                continue;
            }
            foreach ($context->columns() as $column) {
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }
        foreach ($this->columns as $column) {
            if (!in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }
        return $columns;
    }

    /** @param array<string,mixed> $row */
    public function contextFor(string $column, array $row): ?string
    {
        if (!array_key_exists($column, $this->contextTemplates)
            || !array_key_exists($column, $this->payrollSensitiveContexts)
        ) {
            throw self::error(
                'secret_source_schema_invalid',
                $this->registryKey,
                $column,
            );
        }
        $template = $this->contextTemplates[$column];
        $payrollContext = $this->payrollSensitiveContexts[$column];

        if ($payrollContext !== null) {
            try {
                return $payrollContext->resolve($row);
            } catch (\InvalidArgumentException) {
                throw self::error(
                    'secret_source_context_invalid',
                    $this->registryKey,
                    $column,
                );
            }
        }

        return $template?->resolve($row, $this->registryKey, $column);
    }

    /** @return list<string> */
    private static function companyBackupDataColumns(
        TenantDataDefinition $definition,
    ): array {
        $companyBackup = $definition->details['company_backup'] ?? null;
        $columns = is_array($companyBackup) && !array_is_list($companyBackup)
            ? ($companyBackup['data_columns'] ?? null)
            : null;
        if (!is_array($columns) || !array_is_list($columns)) {
            return [];
        }
        $result = [];
        foreach ($columns as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || in_array($column, $result, true)
            ) {
                return [];
            }
            $result[] = $column;
        }
        return $result;
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
