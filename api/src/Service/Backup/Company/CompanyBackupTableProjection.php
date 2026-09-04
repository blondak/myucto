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

    /** @var array<string,CompanyBackupColumnCodec> */
    public array $columnCodecs;

    public CompanyBackupReferenceSet $references;

    public CompanyBackupEncodedReferenceSet $encodedReferences;

    public CompanyBackupEmbeddedReferenceSet $embeddedReferences;

    public CompanyBackupEmbeddedHashReferenceSet $embeddedHashReferences;

    public CompanyBackupEmbeddedHashSet $embeddedHashes;

    public CompanyBackupDerivedHashSet $derivedHashes;

    public CompanyBackupPolymorphicReferenceSet $polymorphicReferences;

    public CompanyBackupPreservedIdentifierSet $preservedIdentifiers;

    public CompanyBackupProtectedSecretMaterializationSet $protectedSecretMaterializations;

    public CompanyBackupRestoreOverrideSet $restoreOverrides;

    /**
     * @param list<string> $primaryKey
     * @param array<string,mixed> $ownership
     * @param list<string> $dataColumns
     * @param list<string> $generatedColumns
     * @param array<string,string> $omitColumns
     * @param array<string,TenantSecretPolicy> $secretPolicies
     * @param array<string,CompanyBackupColumnCodec> $columnCodecs
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
        array $columnCodecs,
        CompanyBackupReferenceSet $references,
        CompanyBackupEncodedReferenceSet $encodedReferences,
        CompanyBackupEmbeddedReferenceSet $embeddedReferences,
        CompanyBackupEmbeddedHashReferenceSet $embeddedHashReferences,
        CompanyBackupEmbeddedHashSet $embeddedHashes,
        CompanyBackupDerivedHashSet $derivedHashes,
        CompanyBackupPolymorphicReferenceSet $polymorphicReferences,
        CompanyBackupPreservedIdentifierSet $preservedIdentifiers,
        CompanyBackupProtectedSecretMaterializationSet $protectedSecretMaterializations,
        CompanyBackupRestoreOverrideSet $restoreOverrides,
    ) {
        $this->primaryKey = $primaryKey;
        $this->ownership = $ownership;
        $this->dataColumns = $dataColumns;
        $this->generatedColumns = $generatedColumns;
        $this->omitColumns = $omitColumns;
        $this->secretPolicies = $secretPolicies;
        $this->columnCodecs = $columnCodecs;
        $this->references = $references;
        $this->encodedReferences = $encodedReferences;
        $this->embeddedReferences = $embeddedReferences;
        $this->embeddedHashReferences = $embeddedHashReferences;
        $this->embeddedHashes = $embeddedHashes;
        $this->derivedHashes = $derivedHashes;
        $this->polymorphicReferences = $polymorphicReferences;
        $this->preservedIdentifiers = $preservedIdentifiers;
        $this->protectedSecretMaterializations = $protectedSecretMaterializations;
        $this->restoreOverrides = $restoreOverrides;
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
        $baseMetadataKeys = [
            'data_columns',
            'embedded_references',
            'generated_columns',
            'omit_columns',
            'references',
            'restore_overrides',
        ];
        $allowedMetadataKeys = [
            ...$baseMetadataKeys,
            'column_codecs',
            'derived_hashes',
            'encoded_references',
            'embedded_hash_references',
            'embedded_hashes',
            'polymorphic_references',
            'preserved_identifiers',
            'protected_secret_materializations',
        ];
        sort($allowedMetadataKeys, SORT_STRING);
        if (array_diff($baseMetadataKeys, $metadataKeys) !== []
            || array_diff($metadataKeys, $allowedMetadataKeys) !== []
        ) {
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
        $secretMetadata = $definition->details['secrets'] ?? null;
        $secretPolicies = CompanyBackupSecretColumnSet::fromArray(
            $secretMetadata,
            $registryKey,
        )->policies;
        if (!is_array($secretMetadata)) {
            throw new CompanyBackupDataSourceException(
                'data_secret_registry_invalid',
                $registryKey,
            );
        }
        $columnCodecs = self::columnCodecs(
            $metadata['column_codecs'] ?? [],
            $dataColumns,
            $registryKey,
        );
        $references = CompanyBackupReferenceSet::fromArray(
            $metadata['references'],
            $registryKey,
        );
        $encodedReferences = CompanyBackupEncodedReferenceSet::fromArray(
            $metadata['encoded_references'] ?? [],
            $registryKey,
            $dataColumns,
        );
        $polymorphicReferences = CompanyBackupPolymorphicReferenceSet::fromArray(
            $metadata['polymorphic_references'] ?? [],
            $registryKey,
            $dataColumns,
        );
        $preservedIdentifiers = CompanyBackupPreservedIdentifierSet::fromArray(
            $metadata['preserved_identifiers'] ?? [],
            $registryKey,
            $dataColumns,
        );
        $additionalClassifiedColumns = $encodedReferences->classifiedColumns();
        foreach ($polymorphicReferences->classifiedColumns() as $column) {
            if (in_array($column, $additionalClassifiedColumns, true)) {
                throw new CompanyBackupDataSourceException(
                    'data_reference_column_classification_duplicate',
                    $registryKey,
                    $column,
                );
            }
            $additionalClassifiedColumns[] = $column;
        }
        foreach ($preservedIdentifiers->columns as $column) {
            if (in_array($column, $additionalClassifiedColumns, true)) {
                throw new CompanyBackupDataSourceException(
                    'data_reference_column_classification_duplicate',
                    $registryKey,
                    $column,
                );
            }
            $additionalClassifiedColumns[] = $column;
        }
        $references->assertProjectionColumns(
            $dataColumns,
            $additionalClassifiedColumns,
        );
        $embeddedReferences = CompanyBackupEmbeddedReferenceSet::fromArray(
            $metadata['embedded_references'],
            $registryKey,
            $dataColumns,
        );
        $embeddedHashReferences = CompanyBackupEmbeddedHashReferenceSet::fromArray(
            $metadata['embedded_hash_references'] ?? [],
            $registryKey,
            $dataColumns,
        );
        $embeddedHashes = CompanyBackupEmbeddedHashSet::fromArray(
            $metadata['embedded_hashes'] ?? [],
            $registryKey,
            $dataColumns,
        );
        $derivedHashes = CompanyBackupDerivedHashSet::fromArray(
            $metadata['derived_hashes'] ?? [],
            $registryKey,
            $dataColumns,
        );
        $protectedSecretMaterializations =
            CompanyBackupProtectedSecretMaterializationSet::fromArray(
                $metadata['protected_secret_materializations'] ?? [],
                $registryKey,
                $dataColumns,
                $primaryKey,
                $ownership,
                $omitColumns,
                $secretPolicies,
                $secretMetadata,
            );
        $restoreOverrides = CompanyBackupRestoreOverrideSet::fromArray(
            $metadata['restore_overrides'],
            $registryKey,
            $dataColumns,
            $primaryKey,
            $references,
        );

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
            $columnCodecs,
            $references,
            $encodedReferences,
            $embeddedReferences,
            $embeddedHashReferences,
            $embeddedHashes,
            $derivedHashes,
            $polymorphicReferences,
            $preservedIdentifiers,
            $protectedSecretMaterializations,
            $restoreOverrides,
        );
    }

    /**
     * @param array<mixed> $columns
     * @param array<mixed> $generatedColumns
     * @param array<mixed> $primaryKey
     * @param array<mixed> $binaryColumns
     */
    public function assertRuntimeSchema(
        array $columns,
        array $generatedColumns,
        array $primaryKey,
        array $binaryColumns = [],
    ): void {
        $columns = $this->runtimeIdentifierList($columns);
        $generatedColumns = $this->runtimeIdentifierList($generatedColumns);
        $primaryKey = $this->runtimeIdentifierList($primaryKey);
        $binaryColumns = $this->runtimeIdentifierList($binaryColumns);

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

        $runtimeBinaryDataColumns = array_values(array_filter(
            $binaryColumns,
            fn (string $column): bool => $this->hasDataColumn($column),
        ));
        sort($runtimeBinaryDataColumns, SORT_STRING);
        $declaredBinaryDataColumns = array_keys($this->columnCodecs);
        sort($declaredBinaryDataColumns, SORT_STRING);
        if ($runtimeBinaryDataColumns !== $declaredBinaryDataColumns) {
            $missingCodec = array_values(array_diff(
                $runtimeBinaryDataColumns,
                $declaredBinaryDataColumns,
            ));
            $staleCodec = array_values(array_diff(
                $declaredBinaryDataColumns,
                $runtimeBinaryDataColumns,
            ));
            throw new CompanyBackupDataSourceException(
                'data_binary_columns_mismatch',
                $this->registryKey,
                $missingCodec[0] ?? $staleCodec[0] ?? null,
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

    /** @param array<string,mixed> $row */
    public function assertExportRow(array $row): void
    {
        $this->encodedReferences->assertSourceRow($row);
        $this->embeddedHashReferences->assertSourceRow($row);
        $this->embeddedHashes->assertSourceRow($row);
        $this->derivedHashes->assertSourceRow($row);
    }

    public function assertRegistryTargets(TenantDataRegistry $registry): void
    {
        $this->references->assertRegistryTargets($registry);
        $this->encodedReferences->assertRegistryTargets($registry);
        $this->embeddedReferences->assertRegistryTargets($registry);
        $this->embeddedHashReferences->assertRegistryTargets($registry);
        $this->polymorphicReferences->assertRegistryTargets($registry);
    }

    /** @param array<string,mixed> $row */
    public function assertCompleteSourceRow(array $row): void
    {
        $this->inspectCompleteSourceRow(
            $row,
            static function (CompanyBackupReferenceOccurrence $occurrence): void {},
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param callable(CompanyBackupReferenceOccurrence):void $visitor
     */
    public function inspectCompleteSourceRow(array $row, callable $visitor): void
    {
        $this->visitSourceReferences($row, $visitor);
        $this->embeddedHashReferences->assertSourceRow($row);
        $this->embeddedHashes->assertSourceRow($row);
        $this->derivedHashes->assertSourceRow($row);
    }

    /**
     * Projde všechny remapovatelné reprezentace přes stejné parsery jako obnova.
     *
     * @param array<string,mixed> $row
     * @param callable(CompanyBackupReferenceOccurrence):void $visitor
     */
    public function visitSourceReferences(array $row, callable $visitor): void
    {
        $this->references->visitSourceRow(
            $row,
            function (CompanyBackupReference $reference, array $values) use (
                $visitor,
            ): void {
                $visitor(CompanyBackupReferenceOccurrence::column(
                    $this->registryKey,
                    $reference,
                    $values,
                ));
            },
        );
        $this->encodedReferences->remap(
            $row,
            function (
                CompanyBackupEncodedReference $reference,
                int $value,
            ) use ($visitor): int {
                $visitor(CompanyBackupReferenceOccurrence::encoded(
                    $this->registryKey,
                    $reference,
                    $value,
                ));
                return $value;
            },
        );
        $this->embeddedReferences->remap(
            $row,
            function (
                CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ) use ($visitor): int|string {
                $visitor(CompanyBackupReferenceOccurrence::embedded(
                    $this->registryKey,
                    $reference,
                    $value,
                ));
                return $value;
            },
        );
        $this->polymorphicReferences->remap(
            $row,
            function (
                CompanyBackupPolymorphicReferenceCase $case,
                int $value,
            ) use ($visitor): int {
                foreach ($this->polymorphicReferences->references as $reference) {
                    if (!in_array($case, $reference->cases, true)) {
                        continue;
                    }
                    $visitor(CompanyBackupReferenceOccurrence::polymorphic(
                        $this->registryKey,
                        $reference,
                        $case,
                        $value,
                    ));
                    return $value;
                }
                throw new \LogicException(
                    'Polymorfní varianta nepatří do projekce.',
                );
            },
        );
    }

    /**
     * Přemapuje JSON reference a ve stejném kroku obnoví jejich odvozené pečetě.
     *
     * @param array<string,mixed> $row
     * @param callable(CompanyBackupEmbeddedReference,int|string):(int|string|null) $mapper
     * @param null|callable(CompanyBackupEmbeddedHashReference,string):mixed $hashMapper
     * @return array<string,mixed>
     */
    public function remapEmbeddedReferences(
        array $row,
        callable $mapper,
        ?callable $hashMapper = null,
    ): array {
        if ($hashMapper === null && $this->embeddedHashReferences->references !== []) {
            throw new CompanyBackupDataSourceException(
                'data_embedded_hash_reference_mapper_missing',
                $this->registryKey,
                $this->embeddedHashReferences->references[0]->column,
            );
        }
        $resolvedHashMapper = $hashMapper ?? static fn (): never => throw new \LogicException(
            'Prázdná sada hashových referencí nesmí vyžádat mapování.',
        );
        return $this->derivedHashes->transform(
            $row,
            fn (array $source): array =>
                $this->embeddedHashes->transform(
                    $source,
                    fn (array $payload): array =>
                        $this->embeddedHashReferences->remap(
                            $this->embeddedReferences->remap($payload, $mapper),
                            $resolvedHashMapper,
                        ),
                ),
        );
    }

    /**
     * Přemapuje skalární i strukturované payload reference jedním ID mapperem.
     *
     * @param array<string,mixed> $row
     * @param callable(CompanyBackupEncodedReference|CompanyBackupEmbeddedReference,int|string):(int|string|null) $mapper
     * @param null|callable(CompanyBackupEmbeddedHashReference,string):mixed $hashMapper
     * @return array<string,mixed>
     */
    public function remapPayloadReferences(
        array $row,
        callable $mapper,
        ?callable $hashMapper = null,
    ): array {
        $row = $this->encodedReferences->remap(
            $row,
            static function (
                CompanyBackupEncodedReference $reference,
                int $value,
            ) use ($mapper): ?int {
                $mapped = $mapper($reference, $value);
                return is_int($mapped) ? $mapped : null;
            },
        );
        return $this->remapEmbeddedReferences(
            $row,
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ): int|string|null => $mapper($reference, $value),
            $hashMapper,
        );
    }

    /**
     * Přemapuje všechny deklarované tvary referencí jedním normalizovaným
     * mapperem a vnější pečetě ověří před první změnou a obnoví až nakonec.
     *
     * @param array<string,mixed> $row
     * @param callable(CompanyBackupReferenceOccurrence):mixed $mapper
     * @param null|callable(CompanyBackupEmbeddedHashReference,string):mixed $hashMapper
     * @return array<string,mixed>
     */
    public function remapReferences(
        array $row,
        callable $mapper,
        ?callable $hashMapper = null,
    ): array {
        if ($hashMapper === null && $this->embeddedHashReferences->references !== []) {
            throw new CompanyBackupDataSourceException(
                'data_embedded_hash_reference_mapper_missing',
                $this->registryKey,
                $this->embeddedHashReferences->references[0]->column,
            );
        }
        $resolvedHashMapper = $hashMapper ?? static fn (): never => throw new \LogicException(
            'Prázdná sada hashových referencí nesmí vyžádat mapování.',
        );

        return $this->derivedHashes->transform(
            $row,
            function (array $source) use ($mapper, $resolvedHashMapper): array {
                $source = $this->references->remap(
                    $source,
                    fn (
                        CompanyBackupReference $reference,
                        array $values,
                    ): ?array => $this->mappedValues(
                        CompanyBackupReferenceOccurrence::column(
                            $this->registryKey,
                            $reference,
                            $values,
                        ),
                        $mapper,
                    ),
                );
                $source = $this->encodedReferences->remap(
                    $source,
                    function (
                        CompanyBackupEncodedReference $reference,
                        int $value,
                    ) use ($mapper): ?int {
                        $values = $this->mappedValues(
                            CompanyBackupReferenceOccurrence::encoded(
                                $this->registryKey,
                                $reference,
                                $value,
                            ),
                            $mapper,
                        );
                        $mapped = $values[0] ?? null;
                        return is_int($mapped) ? $mapped : null;
                    },
                );
                $source = $this->embeddedHashes->transform(
                    $source,
                    fn (array $payload): array =>
                        $this->embeddedHashReferences->remap(
                            $this->embeddedReferences->remap(
                                $payload,
                                function (
                                    CompanyBackupEmbeddedReference $reference,
                                    int|string $value,
                                ) use ($mapper): int|string|null {
                                    $values = $this->mappedValues(
                                        CompanyBackupReferenceOccurrence::embedded(
                                            $this->registryKey,
                                            $reference,
                                            $value,
                                        ),
                                        $mapper,
                                    );
                                    return $values[0] ?? null;
                                },
                            ),
                            $resolvedHashMapper,
                        ),
                );
                return $this->polymorphicReferences->remap(
                    $source,
                    function (
                        CompanyBackupPolymorphicReferenceCase $case,
                        int $value,
                    ) use ($mapper): ?int {
                        foreach ($this->polymorphicReferences->references as $reference) {
                            if (!in_array($case, $reference->cases, true)) {
                                continue;
                            }
                            $values = $this->mappedValues(
                                CompanyBackupReferenceOccurrence::polymorphic(
                                    $this->registryKey,
                                    $reference,
                                    $case,
                                    $value,
                                ),
                                $mapper,
                            );
                            $mapped = $values[0] ?? null;
                            return is_int($mapped) ? $mapped : null;
                        }
                        throw new \LogicException(
                            'Polymorfní varianta nepatří do projekce.',
                        );
                    },
                );
            },
        );
    }

    /**
     * @param callable(CompanyBackupReferenceOccurrence):mixed $mapper
     * @return list<int|string>|null
     */
    private function mappedValues(
        CompanyBackupReferenceOccurrence $occurrence,
        callable $mapper,
    ): ?array {
        $mapped = $mapper($occurrence);
        if ($mapped === null) {
            return null;
        }
        if (!$mapped instanceof CompanyBackupSourceKey
            || $mapped->registryKey !== $occurrence->targetRegistryKey
            || $mapped->columns !== array_keys($occurrence->sourceKey)
        ) {
            throw new CompanyBackupDataSourceException(
                'data_reference_mapping_invalid',
                $this->registryKey,
                $occurrence->sourceColumn,
            );
        }
        return array_values($mapped->values);
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

    /**
     * @param list<string> $dataColumns
     * @return array<string,CompanyBackupColumnCodec>
     */
    private static function columnCodecs(
        mixed $value,
        array $dataColumns,
        string $registryKey,
    ): array {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw new CompanyBackupDataSourceException(
                'data_projection_invalid',
                $registryKey,
            );
        }
        $result = [];
        foreach ($value as $column => $codecValue) {
            if (!is_string($column) || !is_string($codecValue)) {
                throw new CompanyBackupDataSourceException(
                    'data_projection_invalid',
                    $registryKey,
                );
            }
            self::assertIdentifier($column, $registryKey);
            $codec = CompanyBackupColumnCodec::tryFrom($codecValue);
            if ($codec === null || !in_array($column, $dataColumns, true)) {
                throw new CompanyBackupDataSourceException(
                    'data_projection_invalid',
                    $registryKey,
                    $column,
                );
            }
            $result[$column] = $codec;
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
