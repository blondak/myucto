<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantSecretColumnDetector;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/** Ověřená, explicitní projekce jedné tabulky do strojového JSONL snapshotu. */
final readonly class CompanyBackupTableProjection
{
    /** @var list<string> */
    public array $primaryKey;

    /** @var array<string,mixed> */
    public array $ownership;

    /** @var list<string> */
    public array $dataColumns;

    /** @var list<string> */
    public array $generatedColumns;

    /** @var array<string,string> */
    public array $omitColumns;

    /** @var array<string,TenantSecretPolicy> */
    public array $secretPolicies;

    public CompanyBackupReferenceSet $references;

    /**
     * @param list<string> $primaryKey
     * @param array<string,mixed> $ownership
     * @param list<string> $dataColumns
     * @param list<string> $generatedColumns
     * @param array<string,string> $omitColumns
     * @param array<string,TenantSecretPolicy> $secretPolicies
     */
    private function __construct(
        public string $registryKey,
        public string $name,
        public TenantDataPolicy $policy,
        array $primaryKey,
        array $ownership,
        array $dataColumns,
        array $generatedColumns,
        array $omitColumns,
        array $secretPolicies,
        CompanyBackupReferenceSet $references,
    ) {
        $this->primaryKey = $primaryKey;
        $this->ownership = $ownership;
        $this->dataColumns = $dataColumns;
        $this->generatedColumns = $generatedColumns;
        $this->omitColumns = $omitColumns;
        $this->secretPolicies = $secretPolicies;
        $this->references = $references;
    }

    public static function fromDefinition(TenantDataDefinition $definition): self
    {
        $registryKey = $definition->key;
        if ($definition->kind !== TenantDataObjectKind::Table
            || !$definition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
            || !$definition->policy->hasMachineDataPayload()
        ) {
            throw new CompanyBackupDataSourceException(
                'data_object_kind_unsupported',
                $registryKey,
            );
        }

        $name = $definition->name();
        self::assertIdentifier($name, $registryKey);
        $primaryKey = self::identifierList(
            $definition->details['primary_key'] ?? null,
            $registryKey,
        );
        if ($primaryKey === []) {
            throw new CompanyBackupDataSourceException(
                'data_primary_key_missing',
                $registryKey,
            );
        }

        $ownership = $definition->details['ownership'] ?? null;
        if (!is_array($ownership) || array_is_list($ownership)) {
            throw new CompanyBackupDataSourceException(
                'data_ownership_invalid',
                $registryKey,
            );
        }

        $metadata = $definition->details['company_backup'] ?? null;
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new CompanyBackupDataSourceException(
                'data_projection_missing',
                $registryKey,
            );
        }
        $metadataKeys = array_keys($metadata);
        sort($metadataKeys, SORT_STRING);
        if ($metadataKeys !== [
            'data_columns',
            'generated_columns',
            'omit_columns',
            'references',
        ]) {
            throw new CompanyBackupDataSourceException(
                'data_projection_invalid',
                $registryKey,
            );
        }

        $dataColumns = self::identifierList($metadata['data_columns'], $registryKey);
        if ($dataColumns === []) {
            throw new CompanyBackupDataSourceException(
                'data_projection_empty',
                $registryKey,
            );
        }
        $generatedColumns = self::identifierList(
            $metadata['generated_columns'],
            $registryKey,
        );
        $omitColumns = self::omitColumns($metadata['omit_columns'], $registryKey);
        $secretPolicies = self::secretPolicies(
            $definition->details['secrets'] ?? null,
            $registryKey,
        );
        $references = CompanyBackupReferenceSet::fromArray(
            $metadata['references'],
            $registryKey,
        );
        $references->assertProjectionColumns($dataColumns);

        $classified = [];
        foreach ($dataColumns as $column) {
            $classified[$column] = 'data';
        }
        foreach ($generatedColumns as $column) {
            self::claimColumn($classified, $column, 'generated', $registryKey);
        }
        foreach (array_keys($omitColumns) as $column) {
            self::claimColumn($classified, $column, 'omit', $registryKey);
        }
        foreach ($secretPolicies as $column => $policy) {
            if ($policy === TenantSecretPolicy::NotSecret) {
                if (($classified[$column] ?? null) !== 'data') {
                    throw new CompanyBackupDataSourceException(
                        'data_secret_policy_invalid',
                        $registryKey,
                        $column,
                    );
                }
                continue;
            }
            self::claimColumn($classified, $column, 'secret', $registryKey);
        }

        foreach ($dataColumns as $column) {
            if (TenantSecretColumnDetector::matches($column)
                && ($secretPolicies[$column] ?? null) !== TenantSecretPolicy::NotSecret
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_secret_column_unclassified',
                    $registryKey,
                    $column,
                );
            }
        }
        foreach ($primaryKey as $column) {
            if (($classified[$column] ?? null) !== 'data') {
                throw new CompanyBackupDataSourceException(
                    'data_primary_key_not_exported',
                    $registryKey,
                    $column,
                );
            }
        }

        return new self(
            $registryKey,
            $name,
            $definition->policy,
            $primaryKey,
            $ownership,
            $dataColumns,
            $generatedColumns,
            $omitColumns,
            $secretPolicies,
            $references,
        );
    }

    /**
     * @param array<mixed> $columns
     * @param array<mixed> $generatedColumns
     * @param array<mixed> $primaryKey
     */
    public function assertRuntimeSchema(
        array $columns,
        array $generatedColumns,
        array $primaryKey,
    ): void {
        $columns = $this->runtimeIdentifierList($columns);
        $generatedColumns = $this->runtimeIdentifierList($generatedColumns);
        $primaryKey = $this->runtimeIdentifierList($primaryKey);

        foreach ($columns as $column) {
            if (TenantSecretColumnDetector::matches($column)
                && !isset($this->secretPolicies[$column])
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_secret_column_unclassified',
                    $this->registryKey,
                    $column,
                );
            }
        }

        $expected = array_fill_keys($this->dataColumns, true);
        foreach ($this->generatedColumns as $column) {
            $expected[$column] = true;
        }
        foreach (array_keys($this->omitColumns) as $column) {
            $expected[$column] = true;
        }
        foreach (array_keys($this->secretPolicies) as $column) {
            $expected[$column] = true;
        }
        $actual = array_fill_keys($columns, true);
        foreach ($columns as $column) {
            if (!isset($expected[$column])) {
                throw new CompanyBackupDataSourceException(
                    'data_schema_column_unclassified',
                    $this->registryKey,
                    $column,
                );
            }
        }
        foreach (array_keys($expected) as $column) {
            if (!isset($actual[$column])) {
                throw new CompanyBackupDataSourceException(
                    'data_schema_column_missing',
                    $this->registryKey,
                    $column,
                );
            }
        }

        $declaredGenerated = $this->generatedColumns;
        sort($declaredGenerated, SORT_STRING);
        $runtimeGenerated = $generatedColumns;
        sort($runtimeGenerated, SORT_STRING);
        if ($declaredGenerated !== $runtimeGenerated) {
            throw new CompanyBackupDataSourceException(
                'data_generated_columns_mismatch',
                $this->registryKey,
            );
        }
        if ($this->primaryKey !== $primaryKey) {
            throw new CompanyBackupDataSourceException(
                'data_primary_key_mismatch',
                $this->registryKey,
            );
        }
    }

    public function requiredSecretEnvelopeColumn(): ?string
    {
        foreach ($this->secretPolicies as $column => $policy) {
            if ($policy === TenantSecretPolicy::ProtectedDomainSecret) {
                return $column;
            }
        }
        return null;
    }

    public function hasDataColumn(string $column): bool
    {
        return in_array($column, $this->dataColumns, true);
    }

    /** @param array<string,string> $classified */
    private static function claimColumn(
        array &$classified,
        string $column,
        string $classification,
        string $registryKey,
    ): void {
        if (isset($classified[$column])) {
            throw new CompanyBackupDataSourceException(
                'data_column_classification_duplicate',
                $registryKey,
                $column,
            );
        }
        $classified[$column] = $classification;
    }

    /** @return list<string> */
    private static function identifierList(mixed $value, string $registryKey): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new CompanyBackupDataSourceException(
                'data_projection_invalid',
                $registryKey,
            );
        }
        $result = [];
        $seen = [];
        foreach ($value as $column) {
            if (!is_string($column)) {
                throw new CompanyBackupDataSourceException(
                    'data_projection_invalid',
                    $registryKey,
                );
            }
            self::assertIdentifier($column, $registryKey);
            if (isset($seen[$column])) {
                throw new CompanyBackupDataSourceException(
                    'data_column_classification_duplicate',
                    $registryKey,
                    $column,
                );
            }
            $seen[$column] = true;
            $result[] = $column;
        }
        return $result;
    }

    /** @return array<string,string> */
    private static function omitColumns(mixed $value, string $registryKey): array
    {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw new CompanyBackupDataSourceException(
                'data_projection_invalid',
                $registryKey,
            );
        }
        $result = [];
        foreach ($value as $column => $reason) {
            if (!is_string($column)
                || !is_string($reason)
                || preg_match('/^[a-z][a-z0-9._-]{2,127}$/D', $reason) !== 1
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_projection_invalid',
                    $registryKey,
                );
            }
            self::assertIdentifier($column, $registryKey);
            $result[$column] = $reason;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @return array<string,TenantSecretPolicy> */
    private static function secretPolicies(mixed $value, string $registryKey): array
    {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw new CompanyBackupDataSourceException(
                'data_secret_registry_invalid',
                $registryKey,
            );
        }
        $result = [];
        foreach ($value as $column => $declaration) {
            if (!is_string($column)
                || !is_array($declaration)
                || array_is_list($declaration)
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_secret_registry_invalid',
                    $registryKey,
                );
            }
            self::assertIdentifier($column, $registryKey);
            $policyValue = $declaration['policy'] ?? null;
            $policy = is_string($policyValue)
                ? TenantSecretPolicy::tryFrom($policyValue)
                : null;
            if ($policy === null) {
                throw new CompanyBackupDataSourceException(
                    'data_secret_registry_invalid',
                    $registryKey,
                    $column,
                );
            }
            $result[$column] = $policy;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function runtimeIdentifierList(array $values): array
    {
        if (!array_is_list($values)) {
            throw new CompanyBackupDataSourceException(
                'data_schema_invalid',
                $this->registryKey,
            );
        }
        $result = [];
        $seen = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new CompanyBackupDataSourceException(
                    'data_schema_invalid',
                    $this->registryKey,
                );
            }
            self::assertIdentifier($value, $this->registryKey);
            if (isset($seen[$value])) {
                throw new CompanyBackupDataSourceException(
                    'data_schema_invalid',
                    $this->registryKey,
                    $value,
                );
            }
            $seen[$value] = true;
            $result[] = $value;
        }
        return $result;
    }

    private static function assertIdentifier(string $identifier, string $registryKey): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $identifier) !== 1) {
            throw new CompanyBackupDataSourceException(
                'data_sql_identifier_invalid',
                $registryKey,
            );
        }
    }
}
