<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantSecretColumnDetector;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/**
 * Strojově vynutitelný kontrakt tabulky, jejíž řádky se bez výběru credentialu
 * vůbec nevytvářejí. Citlivý obsah proto nikdy není běžným JSONL payloadem.
 */
final readonly class CompanyBackupCredentialTableProjection
{
    /** @var list<string> */
    public array $primaryKey;

    /** @var array<string,string> */
    public array $ownership;

    /** @var list<string> */
    public array $columns;

    /** @var list<string> */
    public array $nullableColumns;

    /** @var array{file:string,vault:string} */
    public array $sourceColumns;

    /** @var array<string,CompanyBackupCredentialTransport> */
    public array $transportColumns;

    /** @var list<array{name:string,owner:string,policy:TenantSecretPolicy,source:string}> */
    public array $variants;

    /** @var array<string,TenantSecretPolicy> */
    private array $variantPolicies;

    /**
     * @param list<string> $primaryKey
     * @param array<string,string> $ownership
     * @param list<string> $columns
     * @param list<string> $nullableColumns
     * @param array{file:string,vault:string} $sourceColumns
     * @param array<string,CompanyBackupCredentialTransport> $transportColumns
     * @param list<array{name:string,owner:string,policy:TenantSecretPolicy,source:string}> $variants
     * @param array<string,TenantSecretPolicy> $variantPolicies
     */
    private function __construct(
        public string $registryKey,
        public string $name,
        array $primaryKey,
        array $ownership,
        array $columns,
        array $nullableColumns,
        array $sourceColumns,
        array $transportColumns,
        array $variants,
        array $variantPolicies,
        public CompanyBackupReferenceSet $references,
        public CompanyBackupRestoreOverrideSet $restoreOverrides,
    ) {
        $this->primaryKey = $primaryKey;
        $this->ownership = $ownership;
        $this->columns = $columns;
        $this->nullableColumns = $nullableColumns;
        $this->sourceColumns = $sourceColumns;
        $this->transportColumns = $transportColumns;
        $this->variants = $variants;
        $this->variantPolicies = $variantPolicies;
    }

    public static function fromDefinition(TenantDataDefinition $definition): self
    {
        $registryKey = $definition->key;
        if ($definition->kind !== TenantDataObjectKind::Table
            || !$definition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
            || $definition->policy !== TenantDataPolicy::OptionalCredential
            || $definition->policy->hasMachineDataPayload()
        ) {
            throw self::error('credential_object_policy_invalid', $registryKey);
        }
        self::assertIdentifier($definition->name(), $registryKey);

        $primaryKey = self::identifierList(
            $definition->details['primary_key'] ?? null,
            $registryKey,
            false,
        );
        $ownership = self::ownership(
            $definition->details['ownership'] ?? null,
            $registryKey,
        );
        $metadata = $definition->details['company_backup_credential'] ?? null;
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw self::error('credential_projection_missing', $registryKey);
        }
        $keys = array_keys($metadata);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'columns',
            'default_action',
            'nullable_columns',
            'references',
            'restore_overrides',
            'source_columns',
            'transport_columns',
            'variants',
        ] || $metadata['default_action'] !== 'omit_row'
        ) {
            throw self::error('credential_projection_invalid', $registryKey);
        }

        $columns = self::identifierList($metadata['columns'], $registryKey, false);
        foreach ($primaryKey as $column) {
            if (!in_array($column, $columns, true)) {
                throw self::error(
                    'credential_primary_key_not_declared',
                    $registryKey,
                    $column,
                );
            }
        }
        $nullableColumns = self::nullableColumns(
            $metadata['nullable_columns'],
            $columns,
            $registryKey,
        );
        $sourceColumns = self::sourceColumns(
            $metadata['source_columns'],
            $columns,
            $registryKey,
        );
        $transportColumns = self::transportColumns(
            $metadata['transport_columns'],
            $columns,
            $registryKey,
        );
        if (($transportColumns[$sourceColumns['file']] ?? null)
            !== CompanyBackupCredentialTransport::SecretAttachment
            || isset($transportColumns[$sourceColumns['vault']])
        ) {
            throw self::error('credential_transport_invalid', $registryKey);
        }

        $references = CompanyBackupReferenceSet::fromArray(
            $metadata['references'],
            $registryKey,
        );
        $references->assertProjectionColumns(
            $columns,
            array_keys($transportColumns),
        );
        self::assertOwnershipReference($ownership, $references, $registryKey);
        self::assertVaultReference($sourceColumns, $references, $registryKey);

        [$variants, $variantPolicies] = self::variants(
            $metadata['variants'],
            $registryKey,
        );
        $restoreOverrides = CompanyBackupRestoreOverrideSet::fromArray(
            $metadata['restore_overrides'],
            $registryKey,
            $columns,
            $primaryKey,
            $references,
        );

        $classified = array_fill_keys(array_keys($transportColumns), true);
        foreach ($references->references as $reference) {
            foreach ($reference->columns as $column) {
                $classified[$column] = true;
            }
        }
        foreach ($columns as $column) {
            if (TenantSecretColumnDetector::matches($column)
                && !isset($classified[$column])
            ) {
                throw self::error(
                    'credential_sensitive_column_unclassified',
                    $registryKey,
                    $column,
                );
            }
        }

        return new self(
            $registryKey,
            $definition->name(),
            $primaryKey,
            $ownership,
            $columns,
            $nullableColumns,
            $sourceColumns,
            $transportColumns,
            $variants,
            $variantPolicies,
            $references,
            $restoreOverrides,
        );
    }

    public function assertRegistryTargets(TenantDataRegistry $registry): void
    {
        $this->references->assertRegistryTargets($registry);
    }

    public function assertRuntimeSchema(
        CompanyBackupTableSchema $schema,
        CompanyBackupTableReferenceSchema $references,
    ): void {
        if ($schema->columns !== $this->columns) {
            throw self::error('credential_schema_columns_mismatch', $this->registryKey);
        }
        if ($schema->generatedColumns !== [] || $schema->binaryColumns !== []) {
            throw self::error('credential_schema_storage_mismatch', $this->registryKey);
        }
        if ($schema->primaryKey !== $this->primaryKey) {
            throw self::error('credential_schema_primary_key_mismatch', $this->registryKey);
        }
        if ($references->nullableColumns !== $this->nullableColumns) {
            throw self::error('credential_schema_nullability_mismatch', $this->registryKey);
        }
        $this->references->assertRuntimeSchema($references);
    }

    public function policyFor(
        ?int $ownerUserId,
        ?string $certificatePath,
        ?int $vaultCredentialId,
    ): TenantSecretPolicy {
        return $this->variantFor(
            $ownerUserId,
            $certificatePath,
            $vaultCredentialId,
        )['policy'];
    }

    /** @return array{name:string,owner:string,policy:TenantSecretPolicy,source:string} */
    public function variantFor(
        ?int $ownerUserId,
        ?string $certificatePath,
        ?int $vaultCredentialId,
    ): array {
        if (($ownerUserId !== null && $ownerUserId < 1)
            || ($certificatePath !== null && trim($certificatePath) === '')
            || ($vaultCredentialId !== null && $vaultCredentialId < 1)
        ) {
            throw self::error('credential_variant_value_invalid', $this->registryKey);
        }
        $hasFile = $certificatePath !== null;
        $hasVault = $vaultCredentialId !== null;
        if ($hasFile === $hasVault) {
            throw self::error('credential_variant_ambiguous', $this->registryKey);
        }
        $owner = $ownerUserId === null ? 'company' : 'personal';
        $source = $hasFile ? 'file' : 'vault';
        $signature = $owner . ':' . $source;
        if (!isset($this->variantPolicies[$signature])) {
            throw self::error('credential_variant_unsupported', $this->registryKey);
        }
        foreach ($this->variants as $variant) {
            if ($variant['owner'] === $owner && $variant['source'] === $source) {
                return $variant;
            }
        }
        throw new \LogicException('Credential varianta není v interním indexu.');
    }

    /** @return array<string,string> */
    private static function ownership(mixed $value, string $registryKey): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::error('credential_ownership_invalid', $registryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'owner_column',
            'profile_column',
            'profile_table',
            'strategy',
            'supplier_column',
        ] || ($value['strategy'] ?? null) !== 'profile_scope'
        ) {
            throw self::error('credential_ownership_invalid', $registryKey);
        }
        $result = [];
        foreach ($keys as $key) {
            $item = $value[$key];
            if (!is_string($item)) {
                throw self::error('credential_ownership_invalid', $registryKey);
            }
            if ($key !== 'strategy') {
                self::assertIdentifier($item, $registryKey);
            }
            $result[$key] = $item;
        }
        return $result;
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private static function nullableColumns(
        mixed $value,
        array $columns,
        string $registryKey,
    ): array {
        $nullable = self::identifierList($value, $registryKey, true);
        $set = array_fill_keys($nullable, true);
        $canonical = array_values(array_filter(
            $columns,
            static fn (string $column): bool => isset($set[$column]),
        ));
        if ($nullable !== $canonical) {
            throw self::error('credential_projection_invalid', $registryKey);
        }
        return $nullable;
    }

    /**
     * @param list<string> $columns
     * @return array{file:string,vault:string}
     */
    private static function sourceColumns(
        mixed $value,
        array $columns,
        string $registryKey,
    ): array {
        if (!is_array($value) || array_is_list($value)) {
            throw self::error('credential_source_columns_invalid', $registryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['file', 'vault']) {
            throw self::error('credential_source_columns_invalid', $registryKey);
        }
        $file = $value['file'];
        $vault = $value['vault'];
        if (!is_string($file)
            || !is_string($vault)
            || $file === $vault
            || !in_array($file, $columns, true)
            || !in_array($vault, $columns, true)
        ) {
            throw self::error('credential_source_columns_invalid', $registryKey);
        }
        return ['file' => $file, 'vault' => $vault];
    }

    /**
     * @param list<string> $columns
     * @return array<string,CompanyBackupCredentialTransport>
     */
    private static function transportColumns(
        mixed $value,
        array $columns,
        string $registryKey,
    ): array {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw self::error('credential_transport_invalid', $registryKey);
        }
        $result = [];
        foreach ($value as $column => $transportValue) {
            $transport = is_string($transportValue)
                ? CompanyBackupCredentialTransport::tryFrom($transportValue)
                : null;
            if (!is_string($column)
                || !in_array($column, $columns, true)
                || $transport === null
            ) {
                throw self::error(
                    'credential_transport_invalid',
                    $registryKey,
                    is_string($column) ? $column : null,
                );
            }
            $result[$column] = $transport;
        }
        $sorted = $result;
        ksort($sorted, SORT_STRING);
        if ($result !== $sorted) {
            throw self::error('credential_transport_invalid', $registryKey);
        }
        return $result;
    }

    /**
     * @return array{
     *   list<array{name:string,owner:string,policy:TenantSecretPolicy,source:string}>,
     *   array<string,TenantSecretPolicy>
     * }
     */
    private static function variants(mixed $value, string $registryKey): array
    {
        if (!is_array($value)
            || !array_is_list($value)
            || count($value) !== 3
        ) {
            throw self::error('credential_variants_invalid', $registryKey);
        }
        $variants = [];
        $policies = [];
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw self::error('credential_variants_invalid', $registryKey);
            }
            $keys = array_keys($item);
            sort($keys, SORT_STRING);
            if ($keys !== ['name', 'owner', 'policy', 'source']) {
                throw self::error('credential_variants_invalid', $registryKey);
            }
            $name = $item['name'];
            $owner = $item['owner'];
            $source = $item['source'];
            $policyValue = $item['policy'];
            $policy = is_string($policyValue)
                ? TenantSecretPolicy::tryFrom($policyValue)
                : null;
            if (!is_string($name)
                || !is_string($owner)
                || !is_string($source)
                || !in_array($owner, ['company', 'personal'], true)
                || !in_array($source, ['file', 'vault'], true)
                || $name !== $owner . '_' . $source
                || $policy === null
                || ($owner === 'company'
                    && $policy !== TenantSecretPolicy::OptionalCredential)
                || ($owner === 'personal'
                    && $policy !== TenantSecretPolicy::PersonalWithDualConsent)
                || ($owner === 'company' && $source === 'vault')
            ) {
                throw self::error('credential_variants_invalid', $registryKey);
            }
            $signature = $owner . ':' . $source;
            if (isset($policies[$signature])) {
                throw self::error('credential_variants_invalid', $registryKey);
            }
            $policies[$signature] = $policy;
            $variants[] = [
                'name' => $name,
                'owner' => $owner,
                'policy' => $policy,
                'source' => $source,
            ];
        }
        $names = array_column($variants, 'name');
        $sortedNames = $names;
        sort($sortedNames, SORT_STRING);
        if ($names !== $sortedNames
            || array_keys($policies) !== [
                'company:file',
                'personal:file',
                'personal:vault',
            ]
        ) {
            throw self::error('credential_variants_invalid', $registryKey);
        }
        return [$variants, $policies];
    }

    /** @param array<string,string> $ownership */
    private static function assertOwnershipReference(
        array $ownership,
        CompanyBackupReferenceSet $references,
        string $registryKey,
    ): void {
        $profileColumn = $ownership['profile_column'];
        $profileTarget = 'table:' . $ownership['profile_table'];
        foreach ($references->references as $reference) {
            if ($reference->columns === [$profileColumn]
                && $reference->target === $profileTarget
                && $reference->mapping === CompanyBackupReferenceMapping::TenantId
            ) {
                return;
            }
        }
        throw self::error('credential_ownership_invalid', $registryKey, $profileColumn);
    }

    /** @param array{file:string,vault:string} $sourceColumns */
    private static function assertVaultReference(
        array $sourceColumns,
        CompanyBackupReferenceSet $references,
        string $registryKey,
    ): void {
        foreach ($references->references as $reference) {
            if ($reference->columns === [$sourceColumns['vault']]
                && $reference->mapping
                    === CompanyBackupReferenceMapping::CredentialDecision
            ) {
                return;
            }
        }
        throw self::error(
            'credential_source_columns_invalid',
            $registryKey,
            $sourceColumns['vault'],
        );
    }

    /** @return list<string> */
    private static function identifierList(
        mixed $value,
        string $registryKey,
        bool $mayBeEmpty,
    ): array {
        if (!is_array($value)
            || !array_is_list($value)
            || !$mayBeEmpty && $value === []
        ) {
            throw self::error('credential_projection_invalid', $registryKey);
        }
        $result = [];
        $seen = [];
        foreach ($value as $identifier) {
            if (!is_string($identifier) || isset($seen[$identifier])) {
                throw self::error('credential_projection_invalid', $registryKey);
            }
            self::assertIdentifier($identifier, $registryKey);
            $seen[$identifier] = true;
            $result[] = $identifier;
        }
        return $result;
    }

    private static function assertIdentifier(string $value, string $registryKey): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) !== 1) {
            throw self::error('credential_projection_invalid', $registryKey);
        }
    }

    private static function error(
        string $errorCode,
        string $registryKey,
        ?string $column = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            $errorCode,
            $registryKey,
            $column,
        );
    }
}
