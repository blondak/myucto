<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupAutoIncrementColumn;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataObject;
use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflightResult;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupGlobalIdentityMapper;
use MyInvoice\Service\Backup\Company\CompanyBackupImportDependencyPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupImportRowPreparer;
use MyInvoice\Service\Backup\Company\CompanyBackupImportTableMetadata;
use MyInvoice\Service\Backup\Company\CompanyBackupImportWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionAction;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceOccurrence;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceResolution;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceResolutionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentityProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceKey;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlPrimaryKeyReservation;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlTargetIdentityMap;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupImportRowPreparerTest extends TestCase
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
        $this->database->exec(
            'CREATE TABLE supplier ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE countries ('
                . 'id INTEGER PRIMARY KEY, iso2 TEXT NOT NULL, name TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE synthetic_records ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'supplier_id INTEGER NOT NULL, country_id INTEGER NOT NULL,'
                . 'code TEXT NOT NULL, is_active INTEGER NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE synthetic_relations ('
                . 'supplier_id INTEGER NOT NULL, code TEXT NOT NULL,'
                . 'label TEXT NOT NULL, PRIMARY KEY (supplier_id, code))',
        );
        $this->database->exec(
            "INSERT INTO supplier (id, name) VALUES (40, 'Existing tenant')",
        );
        $this->database->exec(
            "INSERT INTO countries (id, iso2, name) VALUES (10, 'CZ', 'Czechia')",
        );
        $this->database->exec(
            "INSERT INTO synthetic_records"
                . " (id, supplier_id, country_id, code, is_active)"
                . " VALUES (100, 40, 10, 'existing', 1)",
        );
        $this->database->exec(
            "INSERT INTO synthetic_relations (supplier_id, code, label)"
                . " VALUES (40, 'existing', 'Existing relation')",
        );
    }

    public function testMapsGlobalAndPreparesTopologicalRowsWithoutBusinessWrites(): void
    {
        $snapshot = $this->snapshot();
        $inventory = $this->inventory($snapshot);
        $plan = CompanyBackupImportDependencyPlan::fromRegistry(
            $snapshot,
            $inventory,
        );
        $resolutions = $this->resolutions($snapshot);
        $map = new CompanyBackupSqlTargetIdentityMap($this->database);
        self::assertTrue($this->database->beginTransaction());

        $country = $this->definition($snapshot, 'table:countries');
        (new CompanyBackupGlobalIdentityMapper(
            $country,
            $map,
            $resolutions,
            $plan,
        ))->map([
            'id' => 1,
            'iso2' => 'CZ',
            'name' => 'Česko',
        ]);

        $supplier = $this->definition($snapshot, 'table:supplier');
        $supplierProjection = CompanyBackupTableProjection::fromDefinition($supplier);
        $supplierKeys = CompanyBackupSqlPrimaryKeyReservation::reserve(
            $this->database,
            $supplierProjection,
            new CompanyBackupAutoIncrementColumn('id', PHP_INT_MAX),
            1,
        );
        $supplierPreparer = new CompanyBackupImportRowPreparer(
            $supplier,
            new CompanyBackupImportTableMetadata(
                new CompanyBackupAutoIncrementColumn('id', PHP_INT_MAX),
            ),
            $supplierKeys,
            $map,
            $resolutions,
            $plan,
        );
        $preparedSupplier = $supplierPreparer->prepare([
            'id' => 7,
            'name' => 'Imported tenant',
        ]);
        $supplierPreparer->finish();

        self::assertSame([
            'id' => 41,
            'name' => 'Imported tenant',
        ], $preparedSupplier->row);
        self::assertSame(
            ['id' => 7],
            $preparedSupplier->sourceIdentity->primaryKey->values,
        );
        self::assertSame(
            ['id' => 41],
            $preparedSupplier->targetIdentity->primaryKey->values,
        );

        $records = $this->definition($snapshot, 'table:synthetic_records');
        $recordProjection = CompanyBackupTableProjection::fromDefinition($records);
        $recordKeys = CompanyBackupSqlPrimaryKeyReservation::reserve(
            $this->database,
            $recordProjection,
            new CompanyBackupAutoIncrementColumn('id', PHP_INT_MAX),
            1,
        );
        $recordPreparer = new CompanyBackupImportRowPreparer(
            $records,
            new CompanyBackupImportTableMetadata(
                new CompanyBackupAutoIncrementColumn('id', PHP_INT_MAX),
            ),
            $recordKeys,
            $map,
            $resolutions,
            $plan,
        );
        $sourceRow = [
            'id' => 11,
            'supplier_id' => 7,
            'country_id' => 1,
            'code' => 'ROW-1',
            'is_active' => 1,
        ];
        $preparedRecord = $recordPreparer->prepare($sourceRow);
        $recordPreparer->finish();

        self::assertSame([
            'id' => 101,
            'supplier_id' => 41,
            'country_id' => 10,
            'code' => 'ROW-1',
            'is_active' => 0,
        ], $preparedRecord->row);
        self::assertSame(11, $sourceRow['id']);
        $mappedPrimary = $map->find(CompanyBackupSourceKey::fromValues(
            'table:synthetic_records',
            ['id' => 11],
        ));
        self::assertNotNull($mappedPrimary);
        self::assertSame(['id' => 101], $mappedPrimary->values);
        $mappedAlias = $map->find(CompanyBackupSourceKey::fromValues(
            'table:synthetic_records',
            ['supplier_id' => 7, 'code' => 'ROW-1'],
        ));
        self::assertNotNull($mappedAlias);
        self::assertSame(
            ['supplier_id' => 41, 'code' => 'ROW-1'],
            $mappedAlias->values,
        );

        $relations = $this->definition($snapshot, 'table:synthetic_relations');
        $relationPreparer = new CompanyBackupImportRowPreparer(
            $relations,
            new CompanyBackupImportTableMetadata(null),
            null,
            $map,
            $resolutions,
            $plan,
        );
        $preparedRelation = $relationPreparer->prepare([
            'supplier_id' => 7,
            'code' => 'REL-1',
            'label' => 'Imported relation',
        ]);
        $relationPreparer->finish();
        self::assertSame([
            'supplier_id' => 41,
            'code' => 'REL-1',
            'label' => 'Imported relation',
        ], $preparedRelation->row);
        self::assertSame(
            ['supplier_id' => 41, 'code' => 'REL-1'],
            $preparedRelation->targetIdentity->primaryKey->values,
        );
        $this->assertWriteError(
            'import_row_preparer_closed',
            fn () => $relationPreparer->prepare([
                'supplier_id' => 7,
                'code' => 'REL-2',
                'label' => 'Late relation',
            ]),
        );
        $mappedCountry = $map->find(CompanyBackupSourceKey::fromValues(
            'table:countries',
            ['id' => 1],
        ));
        self::assertNotNull($mappedCountry);
        self::assertSame(['id' => 10], $mappedCountry->values);
        self::assertSame(4, $map->identityCount());
        self::assertSame(1, $this->rowCount('supplier'));
        self::assertSame(1, $this->rowCount('countries'));
        self::assertSame(1, $this->rowCount('synthetic_records'));
        self::assertSame(1, $this->rowCount('synthetic_relations'));
        self::assertTrue($this->database->inTransaction());

        $map->seal();
        self::assertTrue($this->database->rollBack());
        $map->close();
    }

    public function testRejectsMissingOrForeignReservationBeforeConsumingAnId(): void
    {
        $snapshot = $this->snapshot();
        $plan = CompanyBackupImportDependencyPlan::fromRegistry(
            $snapshot,
            $this->inventory($snapshot),
        );
        $resolutions = $this->resolutions($snapshot);
        $map = new CompanyBackupSqlTargetIdentityMap($this->database);
        self::assertTrue($this->database->beginTransaction());
        $records = $this->definition($snapshot, 'table:synthetic_records');
        $metadata = new CompanyBackupImportTableMetadata(
            new CompanyBackupAutoIncrementColumn('id', PHP_INT_MAX),
        );

        $this->assertWriteError(
            'import_primary_key_reservation_missing',
            fn () => new CompanyBackupImportRowPreparer(
                $records,
                $metadata,
                null,
                $map,
                $resolutions,
                $plan,
            ),
        );

        $supplier = $this->definition($snapshot, 'table:supplier');
        $foreign = CompanyBackupSqlPrimaryKeyReservation::reserve(
            $this->database,
            CompanyBackupTableProjection::fromDefinition($supplier),
            new CompanyBackupAutoIncrementColumn('id', PHP_INT_MAX),
            1,
        );
        $this->assertWriteError(
            'import_primary_key_reservation_scope_mismatch',
            fn () => new CompanyBackupImportRowPreparer(
                $records,
                $metadata,
                $foreign,
                $map,
                $resolutions,
                $plan,
            ),
        );
        self::assertSame(1, $foreign->remaining());

        self::assertTrue($this->database->rollBack());
        $map->close();
    }

    public function testRejectsGlobalRowWithoutExactResolvedNaturalKey(): void
    {
        $snapshot = $this->snapshot();
        $mapper = new CompanyBackupGlobalIdentityMapper(
            $this->definition($snapshot, 'table:countries'),
            $map = new CompanyBackupSqlTargetIdentityMap($this->database),
            $this->resolutions($snapshot),
            CompanyBackupImportDependencyPlan::fromRegistry(
                $snapshot,
                $this->inventory($snapshot),
            ),
        );

        $this->assertWriteError(
            'import_global_resolution_invalid',
            fn () => $mapper->map([
                'id' => 2,
                'iso2' => 'SK',
                'name' => 'Slovensko',
            ]),
        );
        self::assertSame(0, $map->identityCount());
        $map->close();
    }

    public function testRejectsSealedIdentityMapForEveryFirstPassProducer(): void
    {
        $snapshot = $this->snapshot();
        $plan = CompanyBackupImportDependencyPlan::fromRegistry(
            $snapshot,
            $this->inventory($snapshot),
        );
        $resolutions = $this->resolutions($snapshot);
        $map = new CompanyBackupSqlTargetIdentityMap($this->database);
        $map->seal();

        $this->assertWriteError(
            'import_global_context_mismatch',
            fn () => new CompanyBackupGlobalIdentityMapper(
                $this->definition($snapshot, 'table:countries'),
                $map,
                $resolutions,
                $plan,
            ),
        );
        $this->assertWriteError(
            'import_row_context_mismatch',
            fn () => new CompanyBackupImportRowPreparer(
                $this->definition($snapshot, 'table:synthetic_records'),
                new CompanyBackupImportTableMetadata(
                    new CompanyBackupAutoIncrementColumn('id', PHP_INT_MAX),
                ),
                null,
                $map,
                $resolutions,
                $plan,
            ),
        );
        self::assertSame(0, $map->identityCount());
        $map->close();
    }

    private function snapshot(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                $this->tableDefinition(
                    'table:countries',
                    TenantDataPolicy::GlobalReference,
                    ['id', 'iso2', 'name'],
                    ['strategy' => 'global'],
                    naturalKey: ['iso2'],
                ),
                $this->tableDefinition(
                    'table:supplier',
                    TenantDataPolicy::TenantRoot,
                    ['id', 'name'],
                    ['strategy' => 'selected_supplier', 'column' => 'id'],
                ),
                $this->tableDefinition(
                    'table:synthetic_records',
                    TenantDataPolicy::TenantOwned,
                    ['id', 'supplier_id', 'country_id', 'code', 'is_active'],
                    ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                    references: [
                        $this->reference(
                            ['country_id'],
                            'table:countries',
                            CompanyBackupReferenceMapping::GlobalNaturalKey,
                        ),
                        $this->reference(
                            ['supplier_id'],
                            'table:supplier',
                            CompanyBackupReferenceMapping::TenantId,
                        ),
                    ],
                    referenceKeys: [['supplier_id', 'code']],
                    restoreOverrides: [
                        'is_active' => [
                            'value' => 0,
                            'reason' => 'disable_after_restore',
                        ],
                    ],
                ),
                $this->tableDefinition(
                    'table:synthetic_relations',
                    TenantDataPolicy::TenantOwned,
                    ['supplier_id', 'code', 'label'],
                    ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                    references: [$this->reference(
                        ['supplier_id'],
                        'table:supplier',
                        CompanyBackupReferenceMapping::TenantId,
                    )],
                    primaryKey: ['supplier_id', 'code'],
                ),
            ],
            [$profile],
        ), $profile);
    }

    private function inventory(
        TenantDataRegistrySnapshot $snapshot,
    ): CompanyBackupDataInventory {
        $rows = [
            'table:countries' => 1,
            'table:supplier' => 1,
            'table:synthetic_records' => 1,
            'table:synthetic_relations' => 1,
        ];
        $objects = [];
        foreach (CompanyBackupDataInventory::payloadDefinitions($snapshot) as $definition) {
            $objects[] = CompanyBackupDataObject::fromWrittenPayload(
                $definition,
                count($objects) + 1,
                $rows[$definition->key],
                0,
                hash('sha256', ''),
            );
        }
        return CompanyBackupDataInventory::fromObjects($objects, $snapshot);
    }

    private function resolutions(
        TenantDataRegistrySnapshot $snapshot,
    ): CompanyBackupReferenceResolutionPlan {
        $countries = $this->definition($snapshot, 'table:countries');
        $sourceIdentity = CompanyBackupSourceIdentityProjection::fromDefinition(
            $countries,
        )->identityForRow([
            'id' => 1,
            'iso2' => 'CZ',
            'name' => 'Česko',
        ]);
        self::assertNotNull($sourceIdentity->naturalKey);
        $records = CompanyBackupTableProjection::fromDefinition(
            $this->definition($snapshot, 'table:synthetic_records'),
        );
        $reference = null;
        foreach ($records->references->references as $candidate) {
            if ($candidate->mapping === CompanyBackupReferenceMapping::GlobalNaturalKey) {
                $reference = $candidate;
            }
        }
        self::assertNotNull($reference);
        $occurrence = CompanyBackupReferenceOccurrence::column(
            'table:synthetic_records',
            $reference,
            [1],
        )->withSourceKey($sourceIdentity->naturalKey);
        $collector = new CompanyBackupExternalReferenceCollector();
        $collector->accept($occurrence);
        $external = $collector->finish();
        $preflight = new CompanyBackupDataPreflightResult(
            $external,
            4,
            4,
            8,
            512,
            2,
            $snapshot->fingerprint,
            str_repeat('a', 64),
        );
        $requirement = $external->requirements[0];
        $decisionPlan = CompanyBackupReferenceDecisionPlan::fromArray([
            'format' => CompanyBackupReferenceDecisionPlan::FORMAT,
            'version' => CompanyBackupReferenceDecisionPlan::VERSION,
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'decisions' => [[
                'requirement_id' => $requirement->id,
                'mapping' => CompanyBackupReferenceMapping::GlobalNaturalKey->value,
                'target_registry_key' => 'table:countries',
                'action' => CompanyBackupReferenceDecisionAction::MapExisting->value,
                'target_primary_key' => ['id' => 10],
            ]],
        ], $preflight, $snapshot, self::INSTANCE_ID, 91);
        $decision = $decisionPlan->decision($requirement->id);
        self::assertNotNull($decision);
        return CompanyBackupReferenceResolutionPlan::fromResolutions(
            $decisionPlan,
            [new CompanyBackupReferenceResolution($decision, ['id' => 10])],
        );
    }

    private function definition(
        TenantDataRegistrySnapshot $snapshot,
        string $registryKey,
    ): TenantDataDefinition {
        $definition = $snapshot->registry->definition($registryKey);
        self::assertInstanceOf(TenantDataDefinition::class, $definition);
        return $definition;
    }

    /**
     * @param list<string> $dataColumns
     * @param array<string,mixed> $ownership
     * @param list<array<string,mixed>> $references
     * @param list<string>|null $naturalKey
     * @param list<list<string>> $referenceKeys
     * @param array<string,mixed> $restoreOverrides
     * @param list<string> $primaryKey
     */
    private function tableDefinition(
        string $key,
        TenantDataPolicy $policy,
        array $dataColumns,
        array $ownership,
        array $references = [],
        ?array $naturalKey = null,
        array $referenceKeys = [],
        array $restoreOverrides = [],
        array $primaryKey = ['id'],
    ): TenantDataDefinition {
        $details = [
            'primary_key' => $primaryKey,
            'ownership' => $ownership,
            'secrets' => [],
            'company_backup' => [
                'data_columns' => $dataColumns,
                'embedded_references' => [],
                'generated_columns' => [],
                'omit_columns' => [],
                'references' => $references,
                'restore_overrides' => $restoreOverrides,
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
     * @return array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private function reference(
        array $columns,
        string $target,
        CompanyBackupReferenceMapping $mapping,
    ): array {
        return [
            'columns' => $columns,
            'target' => $target,
            'target_columns' => ['id'],
            'mapping' => $mapping->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }

    private function rowCount(string $table): int
    {
        $statement = $this->database->query('SELECT COUNT(*) FROM "' . $table . '"');
        if ($statement === false) {
            throw new \RuntimeException('Kontrolní počet řádků nelze načíst.');
        }
        return (int) $statement->fetchColumn();
    }

    /** @param callable():mixed $operation */
    private function assertWriteError(
        string $errorCode,
        callable $operation,
    ): void {
        try {
            $operation();
            self::fail('Neplatná příprava importu musí být odmítnuta.');
        } catch (CompanyBackupImportWriteException $e) {
            self::assertSame($errorCode, $e->errorCode);
        }
    }
}
