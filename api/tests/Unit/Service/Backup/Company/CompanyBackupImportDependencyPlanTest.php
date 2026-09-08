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
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
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

    public function testRequiredSelfReferenceDoesNotCreateTableGraphCycle(): void
    {
        $snapshot = $this->snapshot([
            $this->table('table:supplier', TenantDataPolicy::TenantRoot, ['id']),
            $this->table(
                'table:append_only_chain',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'previous_id'],
                references: [
                    $this->reference(
                        ['previous_id'],
                        'table:append_only_chain',
                    ),
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
            ['table:append_only_chain'],
        ], $plan->insertBatches());
        $selfDependencies = array_values(array_filter(
            $plan->dependencies(),
            static fn ($dependency): bool =>
                $dependency->sourceRegistryKey === 'table:append_only_chain'
                && $dependency->targetRegistryKey === 'table:append_only_chain',
        ));
        self::assertCount(1, $selfDependencies);
        self::assertFalse($selfDependencies[0]->deferred);
    }

    public function testSeparatesLogicalIdentityEdgeFromPhysicalInsertOrder(): void
    {
        $snapshot = $this->snapshot([
            $this->table('table:supplier', TenantDataPolicy::TenantRoot, ['id']),
            $this->table(
                'table:run_revisions',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'snapshot_json'],
                references: [
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
                embeddedReferences: [[
                    'column' => 'snapshot_json',
                    'condition' => null,
                    'fallbacks' => [],
                    'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                    'nullable' => false,
                    'path' => ['result_id'],
                    'target' => 'table:statutory_results',
                    'target_columns' => ['id'],
                ]],
            ),
            $this->table(
                'table:statutory_results',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'revision_id'],
                references: [
                    $this->reference(
                        ['revision_id'],
                        'table:run_revisions',
                    ),
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
                referenceKeys: [[
                    'supplier_id',
                    'id',
                    'revision_id',
                ]],
            ),
        ]);

        $plan = CompanyBackupImportDependencyPlan::fromRegistry(
            $snapshot,
            $this->inventory($snapshot, ['table:supplier' => 1]),
        );

        self::assertSame([
            ['table:supplier'],
            ['table:run_revisions'],
            ['table:statutory_results'],
        ], $plan->identityBatches());
        self::assertSame([
            ['table:supplier'],
            ['table:run_revisions'],
            ['table:statutory_results'],
        ], $plan->insertBatches());
        self::assertSame(
            $plan->identityBatches(),
            $plan->toArray()['identity_batches'],
        );
    }

    public function testPreparesImmutableStatutoryHashesBeforeInsertGraph(): void
    {
        $snapshot = $this->snapshot([
            $this->table('table:supplier', TenantDataPolicy::TenantRoot, ['id']),
            $this->table(
                'table:payroll_run_revisions',
                TenantDataPolicy::TenantOwned,
                [
                    'id',
                    'supplier_id',
                    'snapshot_json',
                    'snapshot_hash',
                ],
                references: [
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
                embeddedReferences: [[
                    'column' => 'snapshot_json',
                    'condition' => null,
                    'fallbacks' => [],
                    'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                    'nullable' => false,
                    'path' => ['result_id'],
                    'target' => 'table:payroll_statutory_results',
                    'target_columns' => ['id'],
                ]],
                embeddedHashReferences: [[
                    'column' => 'snapshot_json',
                    'nullable' => true,
                    'path' => ['person_hash'],
                    'target' => 'table:payroll_statutory_person_results',
                    'target_hash_column' => 'result_snapshot_hash',
                ]],
                derivedHashes: [[
                    'algorithm' => 'sha256_canonical_json',
                    'hash_column' => 'snapshot_hash',
                    'nullable' => false,
                    'source_column' => 'snapshot_json',
                ]],
                deferredUpdates: false,
            ),
            $this->table(
                'table:payroll_statutory_results',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'revision_id'],
                references: [
                    $this->reference(
                        ['revision_id'],
                        'table:payroll_run_revisions',
                    ),
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
                referenceKeys: [[
                    'supplier_id',
                    'id',
                    'revision_id',
                ]],
                deferredUpdates: false,
            ),
            $this->table(
                'table:payroll_statutory_person_results',
                TenantDataPolicy::TenantOwned,
                [
                    'id',
                    'supplier_id',
                    'statutory_result_id',
                    'payload_json',
                    'result_snapshot_hash',
                ],
                references: [
                    $this->reference(
                        ['statutory_result_id'],
                        'table:payroll_statutory_results',
                    ),
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
                derivedHashes: [[
                    'algorithm' => 'sha256_canonical_json',
                    'hash_column' => 'result_snapshot_hash',
                    'nullable' => false,
                    'source_column' => 'payload_json',
                ]],
                referenceKeys: [[
                    'supplier_id',
                    'id',
                    'statutory_result_id',
                ]],
                deferredUpdates: false,
            ),
            $this->table(
                'table:payroll_statutory_relationship_results',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'person_result_id'],
                references: [
                    $this->reference(
                        ['person_result_id'],
                        'table:payroll_statutory_person_results',
                    ),
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
                deferredUpdates: false,
            ),
        ]);

        $plan = CompanyBackupImportDependencyPlan::fromRegistry(
            $snapshot,
            $this->inventory($snapshot, ['table:supplier' => 1]),
        );

        self::assertSame([
            ['table:supplier'],
            ['table:payroll_run_revisions'],
            ['table:payroll_statutory_results'],
            ['table:payroll_statutory_person_results'],
            ['table:payroll_statutory_relationship_results'],
        ], $plan->insertBatches());
        $hashDependency = $plan->dependency(
            'table:payroll_run_revisions',
            'table:payroll_statutory_person_results',
            CompanyBackupImportDependencyKind::EmbeddedHash,
            'snapshot_json:person_hash->payroll_statutory_person_results:'
                . 'result_snapshot_hash?',
        );
        self::assertNotNull($hashDependency);
        self::assertFalse($hashDependency->deferred);
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
                    'source_hash',
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
                hashReferences: [[
                    'column' => 'source_hash',
                    'nullable' => false,
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
            CompanyBackupImportDependencyKind::Hash->value => false,
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

    public function testSupplierCurrencyCycleKeepsFutureIdentityButInsertsSupplierFirst(): void
    {
        $snapshot = $this->snapshot([
            $this->table('table:supplier', TenantDataPolicy::TenantRoot, ['id', 'default_currency_id'],
                references: [$this->reference(['default_currency_id'], 'table:currencies')]),
            $this->table('table:currencies', TenantDataPolicy::TenantOwned, ['id', 'supplier_id'],
                references: [$this->reference(['supplier_id'], 'table:supplier')]),
        ]);
        $plan = CompanyBackupImportDependencyPlan::fromRegistry($snapshot,
            $this->inventory($snapshot, ['table:supplier' => 1, 'table:currencies' => 1]));
        self::assertSame([['table:supplier'], ['table:currencies']], $plan->insertBatches());
        self::assertSame([], $plan->deferredDependencies());
        self::assertCount(2, $plan->dependencies());
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

    public function testProductionStockMediaCycleDefersOnlyNullableBackEdges(): void
    {
        $production = TenantDataRegistryFactory::draftV1();
        $definitions = [
            $this->table(
                'table:supplier',
                TenantDataPolicy::TenantRoot,
                ['id'],
            ),
        ];
        foreach ([
            'table:vat_rates',
            'table:manufacturers',
            'table:stock_items',
            'table:stock_media',
        ] as $key) {
            $definition = $production->definition($key);
            self::assertNotNull($definition);
            $definitions[] = $definition;
        }
        $snapshot = $this->snapshot($definitions);

        $plan = CompanyBackupImportDependencyPlan::fromRegistry(
            $snapshot,
            $this->inventory($snapshot, ['table:supplier' => 1]),
        );
        $cycle = array_values(array_filter(
            $plan->dependencies(),
            static fn ($dependency): bool => in_array(
                $dependency->sourceRegistryKey . '->'
                    . $dependency->targetRegistryKey,
                [
                    'table:manufacturers->table:stock_media',
                    'table:stock_items->table:manufacturers',
                    'table:stock_media->table:stock_items',
                ],
                true,
            ),
        ));

        self::assertSame(
            [
                'table:manufacturers->table:stock_media' => true,
                'table:stock_items->table:manufacturers' => true,
                'table:stock_media->table:stock_items' => false,
            ],
            array_column(array_map(
                static fn ($dependency): array => [
                    'edge' => $dependency->sourceRegistryKey . '->'
                        . $dependency->targetRegistryKey,
                    'deferred' => $dependency->deferred,
                ],
                $cycle,
            ), 'deferred', 'edge'),
        );
        $positions = [];
        foreach ($plan->insertBatches() as $index => $batch) {
            foreach ($batch as $registryKey) {
                $positions[$registryKey] = $index;
            }
        }
        self::assertLessThan(
            $positions['table:stock_media'],
            $positions['table:stock_items'],
        );
    }

    public function testPurchaseOrdersRestoreBeforeLinesAndInvoiceLinks(): void
    {
        $production = TenantDataRegistryFactory::draftV1();
        $definitions = [
            $this->table('table:supplier', TenantDataPolicy::TenantRoot, ['id']),
            $this->table('table:users', TenantDataPolicy::InstanceOwned, ['id']),
        ];
        foreach (['clients', 'currencies', 'warehouses', 'stock_items', 'purchase_invoices'] as $table) {
            $definitions[] = $this->table(
                'table:' . $table,
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id'],
                [$this->reference(['supplier_id'], 'table:supplier')],
            );
        }
        foreach (['vat_rates', 'purchase_orders', 'purchase_order_lines',
            'purchase_order_invoice_links', 'stock_item_promo_prices'] as $table) {
            $definition = $production->definition('table:' . $table);
            self::assertNotNull($definition);
            $definitions[] = $definition;
        }
        $snapshot = $this->snapshot($definitions);
        $plan = CompanyBackupImportDependencyPlan::fromRegistry(
            $snapshot,
            $this->inventory($snapshot, ['table:supplier' => 1]),
        );
        $positions = [];
        foreach ($plan->insertBatches() as $index => $batch) {
            foreach ($batch as $key) {
                $positions[$key] = $index;
            }
        }
        foreach (['clients', 'currencies', 'warehouses'] as $parent) {
            self::assertLessThan($positions['table:purchase_orders'], $positions['table:' . $parent]);
        }
        foreach (['purchase_order_lines', 'purchase_order_invoice_links'] as $child) {
            self::assertLessThan($positions['table:' . $child], $positions['table:purchase_orders']);
        }
        self::assertLessThan(
            $positions['table:purchase_order_invoice_links'],
            $positions['table:purchase_invoices'],
        );
        self::assertSame(['table:vat_rates'], $plan->globalRegistryKeys());
        self::assertLessThan(
            $positions['table:stock_item_promo_prices'],
            $positions['table:stock_items'],
        );
    }

    public function testStockInventoryCyclesAreDeferredButLinesFollowTheirDocuments(): void
    {
        $production = TenantDataRegistryFactory::draftV1();
        $definitions = [
            $this->table('table:supplier', TenantDataPolicy::TenantRoot, ['id']),
            $this->table('table:users', TenantDataPolicy::InstanceOwned, ['id']),
        ];
        foreach (['warehouses', 'stock_items', 'invoices', 'invoice_items',
            'purchase_invoices', 'purchase_invoice_items', 'purchase_orders',
            'purchase_order_lines', 'journal_entries'] as $table) {
            $definitions[] = $this->table(
                'table:' . $table, TenantDataPolicy::TenantOwned, ['id', 'supplier_id'],
                [$this->reference(['supplier_id'], 'table:supplier')],
            );
        }
        foreach (['stock_documents', 'stock_document_lines', 'stock_landed_costs',
            'stock_takes', 'stock_take_lines'] as $table) {
            $definition = $production->definition('table:' . $table);
            self::assertNotNull($definition);
            $definitions[] = $definition;
        }
        $snapshot = $this->snapshot($definitions);
        $plan = CompanyBackupImportDependencyPlan::fromRegistry(
            $snapshot, $this->inventory($snapshot, ['table:supplier' => 1]),
        );
        $positions = [];
        foreach ($plan->insertBatches() as $index => $batch) {
            foreach ($batch as $key) {
                $positions[$key] = $index;
            }
        }
        foreach (['stock_document_lines', 'stock_landed_costs'] as $child) {
            self::assertLessThan($positions['table:' . $child], $positions['table:stock_documents']);
        }
        self::assertLessThan($positions['table:stock_take_lines'], $positions['table:stock_takes']);
        $deferred = array_map(
            static fn ($dependency): string => $dependency->toArray()['signature'],
            $plan->deferredDependencies(),
        );
        foreach (['stock_take_id->stock_takes:id', 'reversal_document_id->stock_documents:id',
            'receipt_document_id->stock_documents:id', 'issue_document_id->stock_documents:id'] as $signature) {
            self::assertContains($signature, $deferred);
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
     * @param list<array<string,mixed>> $hashReferences
     * @param list<array<string,mixed>> $derivedHashes
     * @param list<array<string,mixed>> $polymorphicReferences
     * @param list<string>|null $naturalKey
     * @param list<list<string>> $referenceKeys
     * @param bool $deferredUpdates
     */
    private function table(
        string $key,
        TenantDataPolicy $policy,
        array $dataColumns,
        array $references = [],
        array $encodedReferences = [],
        array $embeddedReferences = [],
        array $embeddedHashReferences = [],
        array $hashReferences = [],
        array $derivedHashes = [],
        array $polymorphicReferences = [],
        ?array $naturalKey = null,
        array $referenceKeys = [],
        bool $deferredUpdates = true,
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
                ...($deferredUpdates ? [] : ['deferred_updates' => false]),
                'derived_hashes' => $derivedHashes,
                'embedded_hash_references' => $embeddedHashReferences,
                'embedded_references' => $embeddedReferences,
                'encoded_references' => $encodedReferences,
                'generated_columns' => [],
                'hash_references' => $hashReferences,
                'omit_columns' => [],
                'polymorphic_references' => $polymorphicReferences,
                'references' => $references,
                'restore_overrides' => [],
            ],
        ];
        if ($naturalKey !== null) {
            $details['natural_key'] = $naturalKey;
        }
        if ($referenceKeys !== []) {
            $details['reference_keys'] = $referenceKeys;
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
