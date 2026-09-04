<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataObject;
use MyInvoice\Service\Backup\Company\CompanyBackupImportDependencyKind;
use MyInvoice\Service\Backup\Company\CompanyBackupImportDependencyPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupImportPlanException;
use MyInvoice\Service\Backup\Company\CompanyBackupPolymorphicReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupPolymorphicReferenceTransform;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\TestCase;

final class CompanyBackupImportDependencyPlanTest extends TestCase
{
    public function testBuildsCanonicalBatchesAndDefersNullableSelfReference(): void
    {
        $snapshot = $this->snapshot([
            $this->table('table:supplier', TenantDataPolicy::TenantRoot, ['id']),
            $this->table(
                'table:z_parent',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'parent_id'],
                references: [
                    $this->reference(
                        ['parent_id'],
                        'table:z_parent',
                        nullableColumns: ['parent_id'],
                    ),
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
            ),
            $this->table(
                'table:a_child',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'parent_id'],
                references: [
                    $this->reference(['parent_id'], 'table:z_parent'),
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
            ),
        ]);

        $plan = CompanyBackupImportDependencyPlan::fromRegistry(
            $snapshot,
            $this->inventory($snapshot, ['table:supplier' => 1]),
        );

        self::assertSame([
            ['table:supplier'],
            ['table:z_parent'],
            ['table:a_child'],
        ], $plan->insertBatches());
        self::assertSame([], $plan->globalRegistryKeys());
        self::assertSame(
            [[
                'source_registry_key' => 'table:z_parent',
                'target_registry_key' => 'table:z_parent',
                'kind' => CompanyBackupImportDependencyKind::Column->value,
                'signature' => 'parent_id->z_parent:id',
                'deferred' => true,
            ]],
            array_map(
                static fn ($dependency): array => $dependency->toArray(),
                $plan->deferredDependencies(),
            ),
        );
        self::assertSame($plan->toArray(), CompanyBackupImportDependencyPlan::fromRegistry(
            $snapshot,
            $this->inventory($snapshot, ['table:supplier' => 1]),
        )->toArray());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $plan->bindingSha256);
    }

    public function testClassifiesEveryPayloadReferenceRepresentation(): void
    {
        $snapshot = $this->snapshot([
            $this->table('table:supplier', TenantDataPolicy::TenantRoot, ['id']),
            $this->table(
                'table:targets',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'payload_json', 'row_hash'],
                references: [$this->reference(['supplier_id'], 'table:supplier')],
                derivedHashes: [[
                    'algorithm' => 'sha256_canonical_json',
                    'hash_column' => 'row_hash',
                    'nullable' => false,
                    'source_column' => 'payload_json',
                ]],
            ),
            $this->table(
                'table:sources',
                TenantDataPolicy::TenantOwned,
                [
                    'id',
                    'supplier_id',
                    'direct_id',
                    'encoded_ref',
                    'embedded_json',
                    'polymorphic_id',
                    'source_type',
                ],
                references: [
                    $this->reference(['direct_id'], 'table:targets'),
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
                encodedReferences: [[
                    'column' => 'encoded_ref',
                    'condition' => null,
                    'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                    'nullable' => false,
                    'target' => 'table:targets',
                    'target_columns' => ['id'],
                    'value_prefix' => 'target:',
                    'value_suffix_separator' => null,
                ]],
                embeddedReferences: [[
                    'column' => 'embedded_json',
                    'condition' => null,
                    'document_nullable' => true,
                    'document_path' => ['snapshot_json'],
                    'fallbacks' => [],
                    'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                    'nullable' => false,
                    'path' => ['target_id'],
                    'target' => 'table:targets',
                    'target_columns' => ['id'],
                ]],
                embeddedHashReferences: [[
                    'column' => 'embedded_json',
                    'nullable' => true,
                    'path' => ['target_hash'],
                    'target' => 'table:targets',
                    'target_hash_column' => 'row_hash',
                ]],
                polymorphicReferences: [[
                    'cases' => [[
                        'base' => 0,
                        'equals' => 'target',
                        'mapping' => CompanyBackupPolymorphicReferenceMapping::TenantId->value,
                        'multiplier' => 1,
                        'slots' => [],
                        'target' => 'table:targets',
                        'target_columns' => ['id'],
                        'transform' => CompanyBackupPolymorphicReferenceTransform::Identity->value,
                    ]],
                    'column' => 'polymorphic_id',
                    'discriminator_column' => 'source_type',
                    'nullable' => false,
                ]],
            ),
        ]);

        $plan = CompanyBackupImportDependencyPlan::fromRegistry(
            $snapshot,
            $this->inventory($snapshot, ['table:supplier' => 1]),
        );
        $sourceDependencies = array_values(array_filter(
            $plan->dependencies(),
            static fn ($dependency): bool =>
                $dependency->sourceRegistryKey === 'table:sources'
                && $dependency->targetRegistryKey === 'table:targets',
        ));

        self::assertSame([
            CompanyBackupImportDependencyKind::Column->value => false,
            CompanyBackupImportDependencyKind::Embedded->value => false,
            CompanyBackupImportDependencyKind::EmbeddedHash->value => true,
            CompanyBackupImportDependencyKind::Encoded->value => false,
            CompanyBackupImportDependencyKind::Polymorphic->value => false,
        ], array_column(array_map(
            static fn ($dependency): array => [
                'kind' => $dependency->kind->value,
                'deferred' => $dependency->deferred,
            ],
            $sourceDependencies,
        ), 'deferred', 'kind'));
        self::assertSame([
            ['table:supplier'],
            ['table:targets'],
            ['table:sources'],
        ], $plan->insertBatches());
    }

    public function testSeparatesGlobalPayloadFromTenantInserts(): void
    {
        $snapshot = $this->snapshot([
            $this->table(
                'table:countries',
                TenantDataPolicy::GlobalReference,
                ['id', 'iso2'],
                naturalKey: ['iso2'],
            ),
            $this->table('table:supplier', TenantDataPolicy::TenantRoot, ['id']),
            $this->table(
                'table:clients',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'country_id'],
                references: [
                    $this->reference(
                        ['country_id'],
                        'table:countries',
                        mapping: CompanyBackupReferenceMapping::GlobalNaturalKey,
                    ),
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
            ),
        ]);

        $plan = CompanyBackupImportDependencyPlan::fromRegistry(
            $snapshot,
            $this->inventory($snapshot, ['table:supplier' => 1]),
        );

        self::assertSame(['table:countries'], $plan->globalRegistryKeys());
        self::assertSame([
            ['table:supplier'],
            ['table:clients'],
        ], $plan->insertBatches());
        self::assertSame([], array_values(array_filter(
            $plan->dependencies(),
            static fn ($dependency): bool =>
                $dependency->targetRegistryKey === 'table:countries',
        )));
    }

    public function testRejectsNonDeferrableCycle(): void
    {
        $snapshot = $this->snapshot([
            $this->table('table:supplier', TenantDataPolicy::TenantRoot, ['id']),
            $this->table(
                'table:alpha',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'beta_id'],
                references: [
                    $this->reference(['beta_id'], 'table:beta'),
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
            ),
            $this->table(
                'table:beta',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'alpha_id'],
                references: [
                    $this->reference(['alpha_id'], 'table:alpha'),
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
            ),
        ]);

        try {
            CompanyBackupImportDependencyPlan::fromRegistry(
                $snapshot,
                $this->inventory($snapshot, ['table:supplier' => 1]),
            );
            self::fail('Nenulovatelný cyklus nesmí vytvořit pořadí importu.');
        } catch (CompanyBackupImportPlanException $e) {
            self::assertSame('import_dependency_cycle', $e->errorCode);
            self::assertSame('table:alpha', $e->registryKey);
            self::assertNull($e->targetRegistryKey);
        }
    }

    public function testRequiresExactlyOneTenantRootRow(): void
    {
        $snapshot = $this->snapshot([
            $this->table('table:supplier', TenantDataPolicy::TenantRoot, ['id']),
        ]);

        try {
            CompanyBackupImportDependencyPlan::fromRegistry(
                $snapshot,
                $this->inventory($snapshot),
            );
            self::fail('Prázdný tenant root nesmí vytvořit importní plán.');
        } catch (CompanyBackupImportPlanException $e) {
            self::assertSame('import_tenant_root_invalid', $e->errorCode);
            self::assertSame('table:supplier', $e->registryKey);
        }
    }

    public function testRejectsInventoryFromDifferentRegistrySnapshot(): void
    {
        $source = $this->snapshot([
            $this->table('table:supplier', TenantDataPolicy::TenantRoot, ['id']),
        ]);
        $target = $this->snapshot([
            $this->table(
                'table:supplier',
                TenantDataPolicy::TenantRoot,
                ['id', 'name'],
            ),
        ]);

        try {
            CompanyBackupImportDependencyPlan::fromRegistry(
                $target,
                $this->inventory($source, ['table:supplier' => 1]),
            );
            self::fail('Cizí registry fingerprint nesmí vytvořit importní plán.');
        } catch (CompanyBackupImportPlanException $e) {
            self::assertSame('import_plan_context_mismatch', $e->errorCode);
            self::assertNull($e->registryKey);
        }
    }

    /**
     * @param list<TenantDataDefinition> $definitions
     */
    private function snapshot(array $definitions): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(
            new TenantDataRegistry(1, $definitions, [$profile]),
            $profile,
        );
    }

    /**
     * @param array<string,int> $rows
     */
    private function inventory(
        TenantDataRegistrySnapshot $snapshot,
        array $rows = [],
    ): CompanyBackupDataInventory {
        $objects = [];
        foreach (CompanyBackupDataInventory::payloadDefinitions($snapshot) as $index => $definition) {
            $objects[] = CompanyBackupDataObject::fromWrittenPayload(
                $definition,
                $index + 1,
                $rows[$definition->key] ?? 0,
                0,
                hash('sha256', ''),
            );
        }
        return CompanyBackupDataInventory::fromObjects($objects, $snapshot);
    }

    /**
     * @param list<string> $dataColumns
     * @param list<array<string,mixed>> $references
     * @param list<array<string,mixed>> $encodedReferences
     * @param list<array<string,mixed>> $embeddedReferences
     * @param list<array<string,mixed>> $embeddedHashReferences
     * @param list<array<string,mixed>> $derivedHashes
     * @param list<array<string,mixed>> $polymorphicReferences
     * @param list<string>|null $naturalKey
     */
    private function table(
        string $key,
        TenantDataPolicy $policy,
        array $dataColumns,
        array $references = [],
        array $encodedReferences = [],
        array $embeddedReferences = [],
        array $embeddedHashReferences = [],
        array $derivedHashes = [],
        array $polymorphicReferences = [],
        ?array $naturalKey = null,
    ): TenantDataDefinition {
        $details = [
            'primary_key' => ['id'],
            'ownership' => match ($policy) {
                TenantDataPolicy::TenantRoot => [
                    'strategy' => 'selected_supplier',
                    'column' => 'id',
                ],
                TenantDataPolicy::GlobalReference => [
                    'strategy' => 'tenant_reference_sources',
                    'sources' => [],
                ],
                default => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
            },
            'secrets' => [],
            'company_backup' => [
                'data_columns' => $dataColumns,
                'derived_hashes' => $derivedHashes,
                'embedded_hash_references' => $embeddedHashReferences,
                'embedded_references' => $embeddedReferences,
                'encoded_references' => $encodedReferences,
                'generated_columns' => [],
                'omit_columns' => [],
                'polymorphic_references' => $polymorphicReferences,
                'references' => $references,
                'restore_overrides' => [],
            ],
        ];
        if ($naturalKey !== null) {
            $details['natural_key'] = $naturalKey;
        }
        return new TenantDataDefinition(
            $key,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            $details,
        );
    }

    /**
     * @param list<string> $columns
     * @param list<string> $nullableColumns
     * @return array<string,mixed>
     */
    private function reference(
        array $columns,
        string $target,
        CompanyBackupReferenceMapping $mapping = CompanyBackupReferenceMapping::TenantId,
        array $nullableColumns = [],
    ): array {
        return [
            'columns' => $columns,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'fallbacks' => [],
            'mapping' => $mapping->value,
            'nullable_columns' => $nullableColumns,
            'target' => $target,
            'target_columns' => ['id'],
        ];
    }
}
