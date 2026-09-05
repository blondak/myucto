<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupAutoIncrementColumn;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataObject;
use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflightResult;
use MyInvoice\Service\Backup\Company\CompanyBackupDatabaseImporter;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedHashReference;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupImportSchemaSource;
use MyInvoice\Service\Backup\Company\CompanyBackupImportSource;
use MyInvoice\Service\Backup\Company\CompanyBackupImportTableMetadata;
use MyInvoice\Service\Backup\Company\CompanyBackupImportWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionAction;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceOccurrence;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayload;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretScope;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretStorage;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretValue;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentityProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableSchema;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupDatabaseImporterTest extends TestCase
{
    private const INSTANCE_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const TECHNICAL_BINDING =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

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
            'CREATE TABLE countries ('
                . 'id INTEGER PRIMARY KEY, iso2 TEXT NOT NULL, name TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE supplier ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE synthetic_nodes ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'supplier_id INTEGER NOT NULL, country_id INTEGER NOT NULL,'
                . 'parent_id INTEGER NULL, payload_json TEXT NOT NULL,'
                . 'row_hash TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE synthetic_events ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'supplier_id INTEGER NOT NULL, node_hash_json TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE synthetic_secrets ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'supplier_id INTEGER NOT NULL, label TEXT NOT NULL,'
                . 'contact_ciphertext TEXT NULL, contact_hash BLOB NULL,'
                . 'contact_masked TEXT NULL)',
        );
        $this->database->exec(
            "INSERT INTO countries (id, iso2, name) VALUES (10, 'CZ', 'Czechia')",
        );
        $this->database->exec(
            "INSERT INTO users (id, email) VALUES (91, 'restore@example.test')",
        );
        $this->database->exec(
            "INSERT INTO supplier (id, name) VALUES (40, 'Existing tenant')",
        );
        $existingPayload = CanonicalJson::encode([
            'supplier_id' => 40,
            'value' => 'existing',
        ]);
        $statement = $this->database->prepare(
            'INSERT INTO synthetic_nodes'
                . ' (id, supplier_id, country_id, parent_id, payload_json, row_hash)'
                . ' VALUES (100, 40, 10, NULL, ?, ?)',
        );
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute([
            $existingPayload,
            hash('sha256', $existingPayload),
        ]));
        $this->database->exec(
            "INSERT INTO synthetic_events"
                . " (id, supplier_id, node_hash_json)"
                . " VALUES (200, 40, '{\"node_hash\":null}')",
        );
        $this->database->exec(
            "INSERT INTO synthetic_secrets"
                . " (id, supplier_id, label, contact_ciphertext,"
                . " contact_hash, contact_masked)"
                . " VALUES (300, 40, 'Existing', NULL, NULL, NULL)",
        );
    }

    public function testRestoresCompleteGraphAndLeavesCommitToCaller(): void
    {
        [$source, $preflight, $decisions] = $this->context();
        $importer = new CompanyBackupDatabaseImporter(
            $this->database,
            new SyntheticCompanyBackupImportSchemaSource(),
        );
        self::assertTrue($this->database->beginTransaction());

        $result = $importer->restore(
            $source,
            $preflight,
            $decisions,
            $this->sensitiveData(),
        );

        self::assertTrue($this->database->inTransaction());
        self::assertSame(41, $result->supplierId);
        self::assertSame(1, $result->mappedGlobalRows);
        self::assertSame(5, $result->insertedRows);
        self::assertSame(3, $result->deferredRows);
        self::assertSame(2, $result->updatedRows);
        self::assertSame(6, $result->identityCount);
        self::assertSame(11, $result->sourceKeyCount);
        self::assertSame(2, $result->hashMappingCount);
        self::assertSame(1, $result->protectedSecretCount);

        $nodes = $this->rows(
            'SELECT id, supplier_id, country_id, parent_id, payload_json, row_hash'
                . ' FROM synthetic_nodes WHERE supplier_id = 41 ORDER BY id',
        );
        self::assertCount(2, $nodes);
        self::assertSame(101, $nodes[0]['id']);
        self::assertNull($nodes[0]['parent_id']);
        self::assertSame(102, $nodes[1]['id']);
        self::assertSame(101, $nodes[1]['parent_id']);
        foreach ($nodes as $node) {
            self::assertSame(41, $node['supplier_id']);
            self::assertSame(10, $node['country_id']);
            $payload = json_decode((string) $node['payload_json'], true);
            self::assertIsArray($payload);
            self::assertSame(41, $payload['supplier_id']);
            self::assertSame(
                hash('sha256', (string) $node['payload_json']),
                $node['row_hash'],
            );
        }
        $events = $this->rows(
            'SELECT id, supplier_id, node_hash_json FROM synthetic_events'
                . ' WHERE supplier_id = 41',
        );
        self::assertCount(1, $events);
        self::assertSame(201, $events[0]['id']);
        $eventPayload = json_decode((string) $events[0]['node_hash_json'], true);
        self::assertIsArray($eventPayload);
        self::assertSame($nodes[1]['row_hash'], $eventPayload['node_hash']);
        $secretRows = $this->rows(
            'SELECT id, supplier_id, label, contact_ciphertext, contact_hash,'
                . ' contact_masked FROM synthetic_secrets WHERE supplier_id = 41',
        );
        self::assertCount(1, $secretRows);
        self::assertSame(301, $secretRows[0]['id']);
        self::assertSame('Imported contact', $secretRows[0]['label']);
        self::assertIsString($secretRows[0]['contact_ciphertext']);
        self::assertSame(
            'restore@example.invalid',
            $this->sensitiveData()->reveal(
                $secretRows[0]['contact_ciphertext'],
                PayrollSensitiveField::CONTACT_EMAIL,
                41,
                301,
            ),
        );
        self::assertIsString($secretRows[0]['contact_hash']);
        self::assertSame(32, strlen($secretRows[0]['contact_hash']));
        self::assertIsString($secretRows[0]['contact_masked']);

        self::assertTrue($this->database->rollBack());
        self::assertSame(1, $this->countRows('supplier'));
        self::assertSame(1, $this->countRows('synthetic_nodes'));
        self::assertSame(1, $this->countRows('synthetic_events'));
        self::assertSame(1, $this->countRows('synthetic_secrets'));
        self::assertSame(0, $this->temporaryTableCount());
    }

    public function testFailureKeepsTransactionOpenForCompleteCallerRollback(): void
    {
        [$source, $preflight, $decisions] = $this->context(
            'table:synthetic_events',
        );
        $importer = new CompanyBackupDatabaseImporter(
            $this->database,
            new SyntheticCompanyBackupImportSchemaSource(),
        );
        self::assertTrue($this->database->beginTransaction());

        try {
            $importer->restore(
                $source,
                $preflight,
                $decisions,
                $this->sensitiveData(),
            );
            self::fail('Chyba zdrojového streamu musí databázový import zastavit.');
        } catch (\RuntimeException $e) {
            self::assertSame('synthetic_source_failure', $e->getMessage());
        }

        self::assertTrue($this->database->inTransaction());
        self::assertGreaterThan(1, $this->countRows('supplier'));
        self::assertTrue($this->database->rollBack());
        self::assertSame(1, $this->countRows('supplier'));
        self::assertSame(1, $this->countRows('synthetic_nodes'));
        self::assertSame(1, $this->countRows('synthetic_events'));
        self::assertSame(1, $this->countRows('synthetic_secrets'));
        self::assertSame(0, $this->temporaryTableCount());
    }

    public function testRejectsHashTargetChangedByDeferredPassBeforeWriting(): void
    {
        [$source, $preflight, $decisions] = $this->context(
            unstableHashTarget: true,
        );
        $importer = new CompanyBackupDatabaseImporter(
            $this->database,
            new SyntheticCompanyBackupImportSchemaSource(),
        );
        self::assertTrue($this->database->beginTransaction());

        try {
            $importer->restore(
                $source,
                $preflight,
                $decisions,
                $this->sensitiveData(),
            );
            self::fail('Nestabilní cílový hash nesmí vstoupit do importu.');
        } catch (CompanyBackupImportWriteException $e) {
            self::assertSame('import_hash_target_not_stable', $e->errorCode);
            self::assertSame('table:synthetic_nodes', $e->registryKey);
            self::assertSame('row_hash', $e->column);
        }

        self::assertTrue($this->database->inTransaction());
        self::assertSame(1, $this->countRows('supplier'));
        self::assertSame(1, $this->countRows('synthetic_nodes'));
        self::assertSame(0, $this->temporaryTableCount());
        self::assertTrue($this->database->rollBack());
    }

    /**
     * @return array{
     *   SyntheticCompanyBackupImportSource,
     *   CompanyBackupDataPreflightResult,
     *   CompanyBackupReferenceDecisionPlan
     * }
     */
    private function context(
        ?string $failOn = null,
        bool $unstableHashTarget = false,
    ): array
    {
        $snapshot = $this->snapshot($unstableHashTarget);
        $rows = $this->sourceRows();
        $inventory = $this->inventory($snapshot, $rows);
        $external = new CompanyBackupExternalReferenceCollector();
        $country = $this->definition($snapshot, 'table:countries');
        $naturalKey = CompanyBackupSourceIdentityProjection::fromDefinition(
            $country,
        )->identityForRow($rows['table:countries'][0])->naturalKey;
        self::assertNotNull($naturalKey);
        $nodeProjection = CompanyBackupTableProjection::fromDefinition(
            $this->definition($snapshot, 'table:synthetic_nodes'),
        );
        $countryReference = null;
        foreach ($nodeProjection->references->references as $reference) {
            if ($reference->mapping
                === CompanyBackupReferenceMapping::GlobalNaturalKey
            ) {
                $countryReference = $reference;
            }
        }
        self::assertNotNull($countryReference);
        foreach ($rows['table:synthetic_nodes'] as $row) {
            $external->accept(CompanyBackupReferenceOccurrence::column(
                'table:synthetic_nodes',
                $countryReference,
                [$row['country_id']],
            )->withSourceKey($naturalKey));
        }
        $externalInventory = $external->finish();
        $preflight = new CompanyBackupDataPreflightResult(
            $externalInventory,
            6,
            6,
            11,
            1_024,
            9,
            $snapshot->fingerprint,
            self::TECHNICAL_BINDING,
        );
        $requirement = $externalInventory->requirements[0];
        $decisions = CompanyBackupReferenceDecisionPlan::fromArray([
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
        $secretPayload = CompanyBackupSecretPayload::fromValues([
            CompanyBackupSecretValue::fromPlaintext(
                'table:synthetic_secrets',
                CompanyBackupSecretScope::Column,
                'contact_ciphertext',
                ['id' => 31],
                'restore@example.invalid',
            ),
        ], $snapshot);
        return [
            new SyntheticCompanyBackupImportSource(
                $snapshot,
                $inventory,
                $rows,
                self::TECHNICAL_BINDING,
                $failOn,
                $secretPayload,
            ),
            $preflight,
            $decisions,
        ];
    }

    private function snapshot(
        bool $unstableHashTarget = false,
    ): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                $this->definitionFor(
                    'table:countries',
                    TenantDataPolicy::GlobalReference,
                    ['id', 'iso2', 'name'],
                    ['strategy' => 'global'],
                    naturalKey: ['iso2'],
                ),
                $this->definitionFor(
                    'table:supplier',
                    TenantDataPolicy::TenantRoot,
                    ['id', 'name'],
                    ['strategy' => 'selected_supplier', 'column' => 'id'],
                ),
                $this->definitionFor(
                    'table:synthetic_nodes',
                    TenantDataPolicy::TenantOwned,
                    [
                        'id',
                        'supplier_id',
                        'country_id',
                        'parent_id',
                        'payload_json',
                        'row_hash',
                    ],
                    ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                    references: [
                        $this->reference(
                            ['country_id'],
                            'table:countries',
                            CompanyBackupReferenceMapping::GlobalNaturalKey,
                        ),
                        $this->reference(
                            ['parent_id'],
                            'table:synthetic_nodes',
                            nullableColumns: ['parent_id'],
                        ),
                        $this->reference(['supplier_id'], 'table:supplier'),
                    ],
                    embeddedReferences: [[
                        'column' => 'payload_json',
                        'condition' => null,
                        'fallbacks' => [],
                        'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                        'nullable' => false,
                        'path' => ['supplier_id'],
                        'target' => 'table:supplier',
                        'target_columns' => ['id'],
                    ]],
                    embeddedHashReferences: $unstableHashTarget ? [[
                        'column' => 'payload_json',
                        'nullable' => true,
                        'path' => ['previous_hash'],
                        'target' => 'table:synthetic_nodes',
                        'target_hash_column' => 'row_hash',
                    ]] : [],
                    derivedHashes: [[
                        'algorithm' => 'sha256_canonical_json',
                        'hash_column' => 'row_hash',
                        'nullable' => false,
                        'source_column' => 'payload_json',
                    ]],
                ),
                $this->definitionFor(
                    'table:synthetic_events',
                    TenantDataPolicy::TenantOwned,
                    ['id', 'supplier_id', 'node_hash_json'],
                    ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                    references: [
                        $this->reference(['supplier_id'], 'table:supplier'),
                    ],
                    embeddedHashReferences: [[
                        'column' => 'node_hash_json',
                        'nullable' => true,
                        'path' => ['node_hash'],
                        'target' => 'table:synthetic_nodes',
                        'target_hash_column' => 'row_hash',
                    ]],
                ),
                $this->definitionFor(
                    'table:synthetic_secrets',
                    TenantDataPolicy::TenantOwned,
                    ['id', 'supplier_id', 'label'],
                    ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                    references: [
                        $this->reference(['supplier_id'], 'table:supplier'),
                    ],
                    secretPolicies: [
                        'contact_ciphertext' => [
                            'policy' =>
                                TenantSecretPolicy::ProtectedDomainSecret->value,
                            'storage' =>
                                CompanyBackupSecretStorage::ApplicationEncryptedContext->value,
                            'context' =>
                                'payroll:{supplier_id}:{id}:contact_email',
                        ],
                    ],
                    omitColumns: [
                        'contact_hash' => 'rederived_from_protected_secret',
                        'contact_masked' => 'rederived_from_protected_secret',
                    ],
                    protectedSecretMaterializations: [[
                        'entity_id_column' => 'id',
                        'field' => 'contact_email',
                        'materializer' => 'payroll_sensitive_v1',
                        'nullable' => false,
                        'secret_column' => 'contact_ciphertext',
                        'target_columns' => [
                            'ciphertext' => 'contact_ciphertext',
                            'lookup_hash' => 'contact_hash',
                            'masked' => 'contact_masked',
                        ],
                        'tenant_id_column' => 'supplier_id',
                    ]],
                ),
                new TenantDataDefinition(
                    'table:users',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::InstanceOwned,
                    [$profile],
                    [
                        'primary_key' => ['id'],
                        'ownership' => ['strategy' => 'instance'],
                    ],
                ),
            ],
            [$profile],
        ), $profile);
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function sourceRows(): array
    {
        $firstPayload = CanonicalJson::encode([
            'supplier_id' => 7,
            'value' => 'first',
        ]);
        $secondPayload = CanonicalJson::encode([
            'supplier_id' => 7,
            'value' => 'second',
        ]);
        $secondHash = hash('sha256', $secondPayload);
        return [
            'table:countries' => [[
                'id' => 1,
                'iso2' => 'CZ',
                'name' => 'Česko',
            ]],
            'table:supplier' => [[
                'id' => 7,
                'name' => 'Restored tenant',
            ]],
            'table:synthetic_nodes' => [[
                'id' => 11,
                'supplier_id' => 7,
                'country_id' => 1,
                'parent_id' => null,
                'payload_json' => $firstPayload,
                'row_hash' => hash('sha256', $firstPayload),
            ], [
                'id' => 12,
                'supplier_id' => 7,
                'country_id' => 1,
                'parent_id' => 11,
                'payload_json' => $secondPayload,
                'row_hash' => $secondHash,
            ]],
            'table:synthetic_events' => [[
                'id' => 21,
                'supplier_id' => 7,
                'node_hash_json' => CanonicalJson::encode([
                    'node_hash' => $secondHash,
                ]),
            ]],
            'table:synthetic_secrets' => [[
                'id' => 31,
                'supplier_id' => 7,
                'label' => 'Imported contact',
            ]],
        ];
    }

    /**
     * @param array<string,list<array<string,mixed>>> $rows
     */
    private function inventory(
        TenantDataRegistrySnapshot $snapshot,
        array $rows,
    ): CompanyBackupDataInventory {
        $objects = [];
        foreach (CompanyBackupDataInventory::payloadDefinitions($snapshot) as $index => $definition) {
            $objects[] = CompanyBackupDataObject::fromWrittenPayload(
                $definition,
                $index + 1,
                count($rows[$definition->key]),
                0,
                hash('sha256', ''),
            );
        }
        return CompanyBackupDataInventory::fromObjects($objects, $snapshot);
    }

    /**
     * @param list<string> $columns
     * @param array<string,mixed> $ownership
     * @param list<array<string,mixed>> $references
     * @param list<array<string,mixed>> $embeddedReferences
     * @param list<array<string,mixed>> $embeddedHashReferences
     * @param list<array<string,mixed>> $derivedHashes
     * @param list<string>|null $naturalKey
     * @param array<string,mixed> $secretPolicies
     * @param array<string,string> $omitColumns
     * @param list<array<string,mixed>> $protectedSecretMaterializations
     */
    private function definitionFor(
        string $key,
        TenantDataPolicy $policy,
        array $columns,
        array $ownership,
        array $references = [],
        array $embeddedReferences = [],
        array $embeddedHashReferences = [],
        array $derivedHashes = [],
        ?array $naturalKey = null,
        array $secretPolicies = [],
        array $omitColumns = [],
        array $protectedSecretMaterializations = [],
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            $key,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                ...($naturalKey === null ? [] : ['natural_key' => $naturalKey]),
                'ownership' => $ownership,
                'secrets' => $secretPolicies,
                'company_backup' => [
                    'data_columns' => $columns,
                    'derived_hashes' => $derivedHashes,
                    'embedded_hash_references' => $embeddedHashReferences,
                    'embedded_references' => $embeddedReferences,
                    'generated_columns' => [],
                    'omit_columns' => $omitColumns,
                    'protected_secret_materializations' =>
                        $protectedSecretMaterializations,
                    'references' => $references,
                    'restore_overrides' => [],
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
        CompanyBackupReferenceMapping $mapping =
            CompanyBackupReferenceMapping::TenantId,
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

    private function definition(
        TenantDataRegistrySnapshot $snapshot,
        string $key,
    ): TenantDataDefinition {
        $definition = $snapshot->registry->definition($key);
        self::assertInstanceOf(TenantDataDefinition::class, $definition);
        return $definition;
    }

    private function sensitiveData(): PayrollSensitiveData
    {
        $config = new Config([
            'app' => [
                'secret_encryption_key' => base64_encode(str_repeat('s', 32)),
                'payroll_hash_key' => base64_encode(str_repeat('h', 32)),
            ],
        ]);
        return new PayrollSensitiveData(new SecretEncryption($config), $config);
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        $statement = $this->database->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Syntetický SELECT selhal.');
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!array_is_list($rows)) {
            throw new \RuntimeException('Syntetický SELECT nevrátil seznam.');
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \RuntimeException(
                    'Syntetický SELECT nevrátil asociativní řádek.',
                );
            }
            $result[] = $row;
        }
        return $result;
    }

    private function countRows(string $table): int
    {
        $statement = $this->database->query('SELECT COUNT(*) FROM ' . $table);
        if ($statement === false) {
            throw new \RuntimeException('Syntetický COUNT selhal.');
        }
        return (int) $statement->fetchColumn();
    }

    private function temporaryTableCount(): int
    {
        $statement = $this->database->query(
            "SELECT COUNT(*) FROM sqlite_temp_master"
                . " WHERE type = 'table'"
                . " AND (name LIKE 'company_backup_target_%'"
                . " OR name LIKE 'company_backup_hash_%')",
        );
        if ($statement === false) {
            throw new \RuntimeException('Nelze ověřit dočasné tabulky.');
        }
        return (int) $statement->fetchColumn();
    }
}

/** @internal Pouze syntetický opakovatelný zdroj importního testu. */
final readonly class SyntheticCompanyBackupImportSource implements CompanyBackupImportSource
{
    /** @param array<string,list<array<string,mixed>>> $rows */
    public function __construct(
        private TenantDataRegistrySnapshot $registry,
        private CompanyBackupDataInventory $inventory,
        private array $rows,
        private string $binding,
        private ?string $failOn,
        private ?CompanyBackupSecretPayload $secretPayload,
    ) {}

    public function sourceRegistry(): TenantDataRegistrySnapshot
    {
        return $this->registry;
    }

    public function targetRegistry(): TenantDataRegistrySnapshot
    {
        return $this->registry;
    }

    public function dataInventory(): CompanyBackupDataInventory
    {
        return $this->inventory;
    }

    public function technicalValidationBindingSha256(): string
    {
        return $this->binding;
    }

    public function consumeRows(
        string $registryKey,
        callable $rowVisitor,
        ?callable $referenceVisitor = null,
    ): int {
        if ($registryKey === $this->failOn) {
            throw new \RuntimeException('synthetic_source_failure');
        }
        $rows = $this->rows[$registryKey] ?? null;
        if (!is_array($rows)) {
            throw new \RuntimeException('synthetic_source_missing');
        }
        foreach ($rows as $row) {
            $rowVisitor($row);
        }
        return count($rows);
    }

    public function secretPayload(): ?CompanyBackupSecretPayload
    {
        return $this->secretPayload;
    }
}

/** @internal Runtime metadata odvozená z testovací SQLite projekce. */
final readonly class SyntheticCompanyBackupImportSchemaSource implements CompanyBackupImportSchemaSource
{
    public function read(
        PDO $database,
        CompanyBackupTableProjection $projection,
    ): CompanyBackupTableSchema {
        $columns = $projection->dataColumns;
        foreach ([
            ...$projection->generatedColumns,
            ...array_keys($projection->omitColumns),
            ...array_keys($projection->secretPolicies),
        ] as $column) {
            if (!in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }
        return new CompanyBackupTableSchema(
            $columns,
            $projection->generatedColumns,
            $projection->primaryKey,
            [],
        );
    }

    public function readImportMetadata(
        PDO $database,
        CompanyBackupTableProjection $projection,
    ): CompanyBackupImportTableMetadata {
        return new CompanyBackupImportTableMetadata(
            new CompanyBackupAutoIncrementColumn('id', PHP_INT_MAX),
        );
    }
}
