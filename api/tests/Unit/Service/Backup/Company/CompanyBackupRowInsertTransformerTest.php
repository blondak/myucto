<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataObject;
use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflightResult;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupImportDependencyPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupPolymorphicReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupPolymorphicReferenceTransform;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceResolutionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupRowReferenceTransformer;
use MyInvoice\Service\Backup\Company\CompanyBackupRowTransformException;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentity;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceKey;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlTargetIdentityMap;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupRowInsertTransformerTest extends TestCase
{
    private const INSTANCE_ID = '123e4567-e89b-42d3-a456-426614174000';

    private PDO $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro izolovaný SQL test.');
        }
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    public function testMapsEagerReferencesAndNullsOnlyPlannedDeferredShapes(): void
    {
        $snapshot = $this->snapshot(includeSources: true);
        $plan = CompanyBackupImportDependencyPlan::fromRegistry(
            $snapshot,
            $this->inventory($snapshot),
        );
        $map = $this->identityMap();
        $transformer = new CompanyBackupRowReferenceTransformer(
            $map,
            $this->emptyResolutionPlan($snapshot),
        );
        $payload = CanonicalJson::encode([
            'embedded_id' => 99,
            'source_hash' => str_repeat('a', 64),
        ]);
        $source = [
            'id' => 501,
            'supplier_id' => 7,
            'target_id' => 11,
            'next_id' => 99,
            'encoded_ref' => 'target:99',
            'embedded_json' => $payload,
            'embedded_digest' => hash('sha256', $payload),
            'polymorphic_id' => 99,
            'source_type' => 'target',
            'is_active' => 1,
        ];

        $insert = $transformer->transformForInsert(
            $this->projection($snapshot, 'table:sources'),
            $source,
            $plan,
        );

        $expectedPayload = CanonicalJson::encode([
            'embedded_id' => null,
            'source_hash' => null,
        ]);
        self::assertSame([
            'id' => 501,
            'supplier_id' => 71,
            'target_id' => 111,
            'next_id' => null,
            'encoded_ref' => null,
            'embedded_json' => $expectedPayload,
            'embedded_digest' => hash('sha256', $expectedPayload),
            'polymorphic_id' => null,
            'source_type' => 'target',
            'is_active' => 0,
        ], $insert);
        self::assertSame(99, $source['next_id']);

        try {
            $transformer->transform(
                $this->projection($snapshot, 'table:sources'),
                $source,
                static fn ($reference, string $hash): string => $hash,
            );
            self::fail('Plná transformace nesmí skrýt dosud chybějící cíl.');
        } catch (CompanyBackupRowTransformException $e) {
            self::assertSame('row_reference_unresolved', $e->errorCode);
            self::assertSame('next_id', $e->column);
        }
        $map->close();
    }

    public function testRejectsProjectionOutsideDependencyPlan(): void
    {
        $fullSnapshot = $this->snapshot(includeSources: true);
        $reducedSnapshot = $this->snapshot(includeSources: false);
        $map = $this->identityMap();
        $transformer = new CompanyBackupRowReferenceTransformer(
            $map,
            $this->emptyResolutionPlan($fullSnapshot),
        );

        try {
            $transformer->transformForInsert(
                $this->projection($fullSnapshot, 'table:sources'),
                [],
                CompanyBackupImportDependencyPlan::fromRegistry(
                    $reducedSnapshot,
                    $this->inventory($reducedSnapshot),
                ),
            );
            self::fail('Cizí plán nesmí řídit insert transformaci řádku.');
        } catch (CompanyBackupRowTransformException $e) {
            self::assertSame('row_import_plan_mismatch', $e->errorCode);
            self::assertSame('table:sources', $e->registryKey);
            self::assertNull($e->column);
        }
        $map->close();
    }

    private function identityMap(): CompanyBackupSqlTargetIdentityMap
    {
        $map = new CompanyBackupSqlTargetIdentityMap($this->database);
        $map->add(
            $this->identity('table:supplier', TenantDataPolicy::TenantRoot, 7),
            $this->identity('table:supplier', TenantDataPolicy::TenantRoot, 71),
        );
        $map->add(
            $this->identity('table:targets', TenantDataPolicy::TenantOwned, 11, 7),
            $this->identity('table:targets', TenantDataPolicy::TenantOwned, 111, 71),
        );
        $map->seal();
        return $map;
    }

    private function identity(
        string $registryKey,
        TenantDataPolicy $policy,
        int $id,
        ?int $supplierId = null,
    ): CompanyBackupSourceIdentity {
        return new CompanyBackupSourceIdentity(
            $policy,
            CompanyBackupSourceKey::fromValues($registryKey, ['id' => $id]),
            $supplierId === null
                ? null
                : CompanyBackupSourceKey::fromValues($registryKey, [
                    'supplier_id' => $supplierId,
                    'id' => $id,
                ]),
            null,
            [],
        );
    }

    private function snapshot(bool $includeSources): TenantDataRegistrySnapshot
    {
        $definitions = [
            $this->definition(
                'table:supplier',
                TenantDataPolicy::TenantRoot,
                ['id'],
            ),
            $this->definition(
                'table:targets',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'payload_json', 'result_hash'],
                references: [$this->reference(['supplier_id'], 'table:supplier')],
                derivedHashes: [[
                    'algorithm' => 'sha256_canonical_json',
                    'hash_column' => 'result_hash',
                    'nullable' => false,
                    'source_column' => 'payload_json',
                ]],
            ),
        ];
        if ($includeSources) {
            $definitions[] = $this->definition(
                'table:sources',
                TenantDataPolicy::TenantOwned,
                [
                    'id',
                    'supplier_id',
                    'target_id',
                    'next_id',
                    'encoded_ref',
                    'embedded_json',
                    'embedded_digest',
                    'polymorphic_id',
                    'source_type',
                    'is_active',
                ],
                references: [
                    $this->reference(
                        ['next_id'],
                        'table:targets',
                        nullableColumns: ['next_id'],
                    ),
                    $this->reference(['supplier_id'], 'table:supplier'),
                    $this->reference(['target_id'], 'table:targets'),
                ],
                encodedReferences: [[
                    'column' => 'encoded_ref',
                    'condition' => null,
                    'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                    'nullable' => true,
                    'target' => 'table:targets',
                    'target_columns' => ['id'],
                    'value_prefix' => 'target:',
                    'value_suffix_separator' => null,
                ]],
                embeddedReferences: [[
                    'column' => 'embedded_json',
                    'condition' => null,
                    'fallbacks' => [],
                    'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                    'nullable' => true,
                    'path' => ['embedded_id'],
                    'target' => 'table:targets',
                    'target_columns' => ['id'],
                ]],
                embeddedHashReferences: [[
                    'column' => 'embedded_json',
                    'nullable' => true,
                    'path' => ['source_hash'],
                    'target' => 'table:targets',
                    'target_hash_column' => 'result_hash',
                ]],
                derivedHashes: [[
                    'algorithm' => 'sha256_canonical_json',
                    'hash_column' => 'embedded_digest',
                    'nullable' => false,
                    'source_column' => 'embedded_json',
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
                    'nullable' => true,
                ]],
                restoreOverrides: [
                    'is_active' => [
                        'value' => 0,
                        'reason' => 'disable_after_restore',
                    ],
                ],
            );
        }
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(
            new TenantDataRegistry(1, $definitions, [$profile]),
            $profile,
        );
    }

    private function inventory(
        TenantDataRegistrySnapshot $snapshot,
    ): CompanyBackupDataInventory {
        $objects = [];
        foreach (CompanyBackupDataInventory::payloadDefinitions($snapshot) as $index => $definition) {
            $objects[] = CompanyBackupDataObject::fromWrittenPayload(
                $definition,
                $index + 1,
                $definition->policy === TenantDataPolicy::TenantRoot ? 1 : 0,
                0,
                hash('sha256', ''),
            );
        }
        return CompanyBackupDataInventory::fromObjects($objects, $snapshot);
    }

    private function projection(
        TenantDataRegistrySnapshot $snapshot,
        string $registryKey,
    ): CompanyBackupTableProjection {
        $definition = $snapshot->registry->definition($registryKey);
        self::assertInstanceOf(TenantDataDefinition::class, $definition);
        return CompanyBackupTableProjection::fromDefinition($definition);
    }

    private function emptyResolutionPlan(
        TenantDataRegistrySnapshot $snapshot,
    ): CompanyBackupReferenceResolutionPlan {
        $external = (new CompanyBackupExternalReferenceCollector())->finish();
        $preflight = new CompanyBackupDataPreflightResult(
            $external,
            0,
            0,
            0,
            0,
            0,
            $snapshot->fingerprint,
            str_repeat('a', 64),
        );
        $decisions = CompanyBackupReferenceDecisionPlan::fromArray([
            'format' => CompanyBackupReferenceDecisionPlan::FORMAT,
            'version' => CompanyBackupReferenceDecisionPlan::VERSION,
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'decisions' => [],
        ], $preflight, $snapshot, self::INSTANCE_ID, 91);
        return CompanyBackupReferenceResolutionPlan::fromResolutions(
            $decisions,
            [],
        );
    }

    /**
     * @param list<string> $dataColumns
     * @param list<array<string,mixed>> $references
     * @param list<array<string,mixed>> $encodedReferences
     * @param list<array<string,mixed>> $embeddedReferences
     * @param list<array<string,mixed>> $embeddedHashReferences
     * @param list<array<string,mixed>> $derivedHashes
     * @param list<array<string,mixed>> $polymorphicReferences
     * @param array<string,mixed> $restoreOverrides
     */
    private function definition(
        string $key,
        TenantDataPolicy $policy,
        array $dataColumns,
        array $references = [],
        array $encodedReferences = [],
        array $embeddedReferences = [],
        array $embeddedHashReferences = [],
        array $derivedHashes = [],
        array $polymorphicReferences = [],
        array $restoreOverrides = [],
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            $key,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => $policy === TenantDataPolicy::TenantRoot
                    ? ['strategy' => 'selected_supplier', 'column' => 'id']
                    : ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
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
                    'restore_overrides' => $restoreOverrides,
                ],
            ],
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
        array $nullableColumns = [],
    ): array {
        return [
            'columns' => $columns,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'fallbacks' => [],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable_columns' => $nullableColumns,
            'target' => $target,
            'target_columns' => ['id'],
        ];
    }
}
