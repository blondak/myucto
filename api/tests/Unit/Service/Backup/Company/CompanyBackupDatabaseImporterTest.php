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
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFileRestoreException;
use MyInvoice\Service\Backup\Company\CompanyBackupImportSchemaSource;
use MyInvoice\Service\Backup\Company\CompanyBackupImportSource;
use MyInvoice\Service\Backup\Company\CompanyBackupImportTableMetadata;
use MyInvoice\Service\Backup\Company\CompanyBackupImportWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollStatutoryResultSetAssembler;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
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
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,'
                . ' logo_path TEXT NULL)',
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
                . 'supplier_id INTEGER NOT NULL, node_hash_json TEXT NOT NULL,'
                . ' node_hash TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE synthetic_secrets ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'supplier_id INTEGER NOT NULL, label TEXT NOT NULL,'
                . 'contact_ciphertext TEXT NULL, contact_hash BLOB NULL,'
                . 'contact_masked TEXT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE synthetic_revisions ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'supplier_id INTEGER NOT NULL, snapshot_json TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE synthetic_results ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'supplier_id INTEGER NOT NULL, revision_id INTEGER NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE payroll_run_revisions ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'supplier_id INTEGER NOT NULL, snapshot_json TEXT NOT NULL,'
                . 'snapshot_hash TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE payroll_statutory_results ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'supplier_id INTEGER NOT NULL, revision_id INTEGER NOT NULL,'
                . 'calculation_kind TEXT NOT NULL, schema_version TEXT NOT NULL,'
                . 'result_status TEXT NOT NULL, ruleset_id TEXT NOT NULL,'
                . 'ruleset_hash TEXT NOT NULL, input_snapshot_json TEXT NOT NULL,'
                . 'input_snapshot_hash TEXT NOT NULL,'
                . 'result_snapshot_json TEXT NOT NULL,'
                . 'result_snapshot_hash TEXT NOT NULL,'
                . 'result_set_hash TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE payroll_statutory_person_results ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'supplier_id INTEGER NOT NULL,'
                . 'statutory_result_id INTEGER NOT NULL,'
                . 'revision_id INTEGER NOT NULL,'
                . 'calculation_kind TEXT NOT NULL, employee_id INTEGER NOT NULL,'
                . 'result_status TEXT NOT NULL,'
                . 'input_snapshot_json TEXT NOT NULL,'
                . 'input_snapshot_hash TEXT NOT NULL,'
                . 'result_snapshot_json TEXT NOT NULL,'
                . 'result_snapshot_hash TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE payroll_statutory_relationship_results ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'supplier_id INTEGER NOT NULL,'
                . 'statutory_result_id INTEGER NOT NULL,'
                . 'person_result_id INTEGER NOT NULL,'
                . 'revision_id INTEGER NOT NULL,'
                . 'calculation_kind TEXT NOT NULL, employee_id INTEGER NOT NULL,'
                . 'employment_id INTEGER NOT NULL, result_status TEXT NOT NULL,'
                . 'input_snapshot_json TEXT NOT NULL,'
                . 'input_snapshot_hash TEXT NOT NULL,'
                . 'result_snapshot_json TEXT NOT NULL,'
                . 'result_snapshot_hash TEXT NOT NULL)',
        );
        $this->database->exec(
            "INSERT INTO countries (id, iso2, name) VALUES (10, 'CZ', 'Czechia')",
        );
        $this->database->exec(
            "INSERT INTO users (id, email) VALUES (91, 'restore@example.test')",
        );
        $this->database->exec(
            "INSERT INTO supplier (id, name, logo_path)"
                . " VALUES (40, 'Existing tenant', NULL)",
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
                . " (id, supplier_id, node_hash_json, node_hash)"
                . " VALUES (200, 40, '{\"node_hash\":null}', '"
                . str_repeat('c', 64) . "')",
        );
        $this->database->exec(
            "INSERT INTO synthetic_secrets"
                . " (id, supplier_id, label, contact_ciphertext,"
                . " contact_hash, contact_masked)"
                . " VALUES (300, 40, 'Existing', NULL, NULL, NULL)",
        );
        $this->database->exec(
            "INSERT INTO synthetic_revisions"
                . " (id, supplier_id, snapshot_json)"
                . " VALUES (400, 40, '{\"result_id\":500}')",
        );
        $this->database->exec(
            'INSERT INTO synthetic_results (id, supplier_id, revision_id)'
                . ' VALUES (500, 40, 400)',
        );
        $this->database->exec(
            "INSERT INTO payroll_run_revisions"
                . " (id, supplier_id, snapshot_json, snapshot_hash)"
                . " VALUES (600, 40, '{}', '" . str_repeat('a', 64) . "')",
        );
        $this->database->exec(
            'INSERT INTO payroll_statutory_results'
                . ' (id, supplier_id, revision_id, calculation_kind,'
                . ' schema_version, result_status, ruleset_id, ruleset_hash,'
                . ' input_snapshot_json, input_snapshot_hash,'
                . ' result_snapshot_json, result_snapshot_hash, result_set_hash)'
                . " VALUES (700, 40, 600, 'social_insurance', 'synthetic.v1',"
                . " 'calculated', 'synthetic-ruleset', '" . str_repeat('b', 64)
                . "', '{}', '" . str_repeat('c', 64) . "', '{}', '"
                . str_repeat('d', 64) . "', '" . str_repeat('e', 64) . "')",
        );
        $this->database->exec(
            'INSERT INTO payroll_statutory_person_results'
                . ' (id, supplier_id, statutory_result_id, revision_id,'
                . ' calculation_kind, employee_id, result_status,'
                . ' input_snapshot_json, input_snapshot_hash,'
                . ' result_snapshot_json, result_snapshot_hash)'
                . " VALUES (800, 40, 700, 600, 'social_insurance', 17,"
                . " 'calculated', '{}', '" . str_repeat('f', 64) . "', '{}', '"
                . str_repeat('1', 64) . "')",
        );
        $this->database->exec(
            'INSERT INTO payroll_statutory_relationship_results'
                . ' (id, supplier_id, statutory_result_id, person_result_id,'
                . ' revision_id, calculation_kind, employee_id, employment_id,'
                . ' result_status, input_snapshot_json, input_snapshot_hash,'
                . ' result_snapshot_json, result_snapshot_hash)'
                . " VALUES (900, 40, 700, 800, 600, 'social_insurance', 17, 19,"
                . " 'calculated', '{}', '" . str_repeat('2', 64) . "', '{}', '"
                . str_repeat('3', 64) . "')",
        );
    }

    public function testRestoresCompleteGraphAndLeavesCommitToCaller(): void
    {
        [$source, $preflight, $decisions] = $this->context(withFiles: true);
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
        self::assertSame(7, $result->filePublicationPlan->sourceSupplierId);
        self::assertSame(41, $result->filePublicationPlan->targetSupplierId);
        self::assertSame(0, $result->filePublicationPlan->presentEntryCount());
        self::assertSame(2, $result->filePublicationPlan->missingEntryCount());

        $suppliers = $this->rows(
            'SELECT id, name, logo_path FROM supplier WHERE id = 41',
        );
        self::assertCount(1, $suppliers);
        self::assertSame(
            'storage/supplier-logos/sup-41.png',
            $suppliers[0]['logo_path'],
        );

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
            if ($node['id'] === 101) {
                self::assertSame(
                    'storage/supplier-logos/'
                        . 'sup-41-brand-11-aaaaaaaaaaaa.png',
                    $payload['logo_path'],
                );
            }
            self::assertSame(
                hash('sha256', (string) $node['payload_json']),
                $node['row_hash'],
            );
        }
        $events = $this->rows(
            'SELECT id, supplier_id, node_hash_json, node_hash'
                . ' FROM synthetic_events'
                . ' WHERE supplier_id = 41',
        );
        self::assertCount(1, $events);
        self::assertSame(201, $events[0]['id']);
        $eventPayload = json_decode((string) $events[0]['node_hash_json'], true);
        self::assertIsArray($eventPayload);
        self::assertSame($nodes[1]['row_hash'], $eventPayload['node_hash']);
        self::assertSame($nodes[1]['row_hash'], $events[0]['node_hash']);
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
        self::assertSame(1, $this->countRows('supplier'));
        self::assertSame(1, $this->countRows('synthetic_nodes'));
        self::assertSame(1, $this->countRows('synthetic_events'));
        self::assertSame(1, $this->countRows('synthetic_secrets'));
        self::assertTrue($this->database->rollBack());
        self::assertSame(1, $this->countRows('supplier'));
        self::assertSame(1, $this->countRows('synthetic_nodes'));
        self::assertSame(1, $this->countRows('synthetic_events'));
        self::assertSame(1, $this->countRows('synthetic_secrets'));
        self::assertSame(0, $this->temporaryTableCount());
    }

    public function testRestoresLogicalIdentityCycleFromPreallocatedIds(): void
    {
        [$source, $preflight, $decisions] = $this->context(
            logicalIdentityCycle: true,
        );
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

        self::assertSame(7, $result->insertedRows);
        self::assertSame(8, $result->identityCount);
        self::assertSame(16, $result->sourceKeyCount);
        $revisions = $this->rows(
            'SELECT id, supplier_id, snapshot_json FROM synthetic_revisions'
                . ' WHERE supplier_id = 41',
        );
        self::assertSame([[
            'id' => 401,
            'supplier_id' => 41,
            'snapshot_json' => '{"result_id":501}',
        ]], $revisions);
        self::assertSame([[
            'id' => 501,
            'supplier_id' => 41,
            'revision_id' => 401,
        ]], $this->rows(
            'SELECT id, supplier_id, revision_id FROM synthetic_results'
                . ' WHERE supplier_id = 41',
        ));

        self::assertTrue($this->database->rollBack());
        self::assertSame(1, $this->countRows('synthetic_revisions'));
        self::assertSame(1, $this->countRows('synthetic_results'));
        self::assertSame(0, $this->temporaryTableCount());
    }

    public function testPreparesStatutoryPersonHashAndResealsImmutableRoot(): void
    {
        [$source, $preflight, $decisions] = $this->context(
            statutoryHashCycle: true,
        );
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

        self::assertSame(9, $result->insertedRows);
        self::assertSame(10, $result->identityCount);
        self::assertSame(21, $result->sourceKeyCount);
        self::assertSame(9, $result->hashMappingCount);
        self::assertSame(3, $result->deferredRows);
        self::assertSame(2, $result->updatedRows);

        $person = $this->rows(
            'SELECT * FROM payroll_statutory_person_results'
                . ' WHERE supplier_id = 41',
        )[0];
        $relationship = $this->rows(
            'SELECT * FROM payroll_statutory_relationship_results'
                . ' WHERE supplier_id = 41',
        )[0];
        $header = $this->rows(
            'SELECT * FROM payroll_statutory_results WHERE supplier_id = 41',
        )[0];
        $revision = $this->rows(
            'SELECT * FROM payroll_run_revisions WHERE supplier_id = 41',
        )[0];

        self::assertSame(601, $revision['id']);
        self::assertSame(701, $header['id']);
        self::assertSame(601, $header['revision_id']);
        self::assertSame(801, $person['id']);
        self::assertSame(701, $person['statutory_result_id']);
        self::assertSame(901, $relationship['id']);
        self::assertSame(801, $relationship['person_result_id']);
        $personSnapshotHash = hash(
            'sha256',
            (string) $person['result_snapshot_json'],
        );
        self::assertSame($personSnapshotHash, $person['result_snapshot_hash']);
        $revisionSnapshot = json_decode(
            (string) $revision['snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(701, $revisionSnapshot['result_id']);
        self::assertSame($personSnapshotHash, $revisionSnapshot['person_hash']);
        self::assertSame(
            hash('sha256', (string) $revision['snapshot_json']),
            $revision['snapshot_hash'],
        );
        self::assertSame(
            CompanyBackupPayrollStatutoryResultSetAssembler::calculate(
                $header,
                [$person],
                [$relationship],
            ),
            $header['result_set_hash'],
        );

        self::assertTrue($this->database->rollBack());
        self::assertSame(1, $this->countRows('payroll_run_revisions'));
        self::assertSame(1, $this->countRows('payroll_statutory_results'));
        self::assertSame(
            1,
            $this->countRows('payroll_statutory_person_results'),
        );
        self::assertSame(
            1,
            $this->countRows('payroll_statutory_relationship_results'),
        );
        self::assertSame(0, $this->temporaryTableCount());
    }

    public function testRejectsChangedStatutoryAggregateBeforeBusinessWrite(): void
    {
        [$source, $preflight, $decisions] = $this->context(
            statutoryHashCycle: true,
            invalidStatutorySeal: true,
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
            self::fail('Změněný agregát musí obnovu zastavit před prvním zápisem.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('data_aggregate_hash_value_invalid', $e->errorCode);
        }

        self::assertTrue($this->database->inTransaction());
        self::assertSame(1, $this->countRows('supplier'));
        self::assertSame(1, $this->countRows('payroll_run_revisions'));
        self::assertSame(1, $this->countRows('payroll_statutory_results'));
        self::assertSame(
            1,
            $this->countRows('payroll_statutory_person_results'),
        );
        self::assertSame(
            1,
            $this->countRows('payroll_statutory_relationship_results'),
        );
        self::assertTrue($this->database->rollBack());
        self::assertSame(0, $this->temporaryTableCount());
    }

    public function testRejectsDatabaseFileReferenceMissingFromInventory(): void
    {
        [$source, $preflight, $decisions] = $this->context(
            withFiles: true,
            omitNestedFileOwner: true,
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
            self::fail(
                'Databázová cesta bez manifestového vlastníka musí obnovu zastavit.',
            );
        } catch (CompanyBackupFileRestoreException $e) {
            self::assertSame(
                'file_restore_inventory_owner_missing',
                $e->errorCode,
            );
            self::assertSame('file-area:supplier-logos', $e->registryKey);
        }

        self::assertTrue($this->database->inTransaction());
        self::assertGreaterThan(1, $this->countRows('supplier'));
        self::assertTrue($this->database->rollBack());
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
        bool $withFiles = false,
        bool $omitNestedFileOwner = false,
        bool $logicalIdentityCycle = false,
        bool $statutoryHashCycle = false,
        bool $invalidStatutorySeal = false,
    ): array
    {
        $snapshot = $this->snapshot(
            $unstableHashTarget,
            $withFiles,
            $logicalIdentityCycle,
            $statutoryHashCycle,
        );
        $rows = $this->sourceRows(
            $withFiles,
            $logicalIdentityCycle,
            $statutoryHashCycle,
            $invalidStatutorySeal,
        );
        $inventory = $this->inventory($snapshot, $rows);
        $fileInventory = $this->fileInventory(
            $snapshot,
            $withFiles,
            $omitNestedFileOwner,
        );
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
        $rowCount = 0;
        $sourceKeyCount = 0;
        foreach ($rows as $registryKey => $objectRows) {
            $definition = $this->definition($snapshot, $registryKey);
            $identity = CompanyBackupSourceIdentityProjection::fromDefinition(
                $definition,
            );
            foreach ($objectRows as $row) {
                $rowCount++;
                $sourceKeyCount += count($identity->identityForRow($row)->keys());
            }
        }
        $preflight = new CompanyBackupDataPreflightResult(
            $externalInventory,
            $rowCount,
            $rowCount,
            $sourceKeyCount,
            1_024,
            $statutoryHashCycle ? 16 : ($logicalIdentityCycle ? 12 : 9),
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
                $fileInventory,
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
        bool $withFiles = false,
        bool $logicalIdentityCycle = false,
        bool $statutoryHashCycle = false,
    ): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $definitions = [
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
                ['id', 'name', 'logo_path'],
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
                ['id', 'supplier_id', 'node_hash_json', 'node_hash'],
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
                hashReferences: [[
                    'column' => 'node_hash',
                    'nullable' => false,
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
        ];
        if ($logicalIdentityCycle) {
            $definitions[] = $this->definitionFor(
                'table:synthetic_revisions',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'snapshot_json'],
                ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
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
                    'target' => 'table:synthetic_results',
                    'target_columns' => ['id'],
                ]],
            );
            $definitions[] = $this->definitionFor(
                'table:synthetic_results',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'revision_id'],
                ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                references: [
                    $this->reference(
                        ['revision_id'],
                        'table:synthetic_revisions',
                    ),
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
                referenceKeys: [[
                    'supplier_id',
                    'id',
                    'revision_id',
                ]],
            );
        }
        if ($withFiles) {
            $definitions[] = new TenantDataDefinition(
                'file-area:supplier-logos',
                TenantDataObjectKind::FileArea,
                TenantDataPolicy::TenantOwned,
                [$profile],
                [
                    'file_policy' => 'historical_optional',
                    'path_policy' => 'supplier_logo',
                    'file_owners' => [[
                        'registry_key' => 'table:supplier',
                        'column' => 'logo_path',
                        'path' => [],
                        'stored_prefix' => 'storage/supplier-logos/',
                    ], [
                        'registry_key' => 'table:synthetic_nodes',
                        'column' => 'payload_json',
                        'path' => ['logo_path'],
                        'stored_prefix' => 'storage/supplier-logos/',
                    ]],
                    'ownership' => ['strategy' => 'database_references'],
                    'storage_subdirectory' => 'supplier-logos',
                ],
            );
        }
        if ($statutoryHashCycle) {
            $definitions[] = $this->definitionFor(
                'table:payroll_run_revisions',
                TenantDataPolicy::TenantOwned,
                ['id', 'supplier_id', 'snapshot_json', 'snapshot_hash'],
                ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
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
                    'nullable' => false,
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
            );
            $definitions[] = $this->definitionFor(
                'table:payroll_statutory_results',
                TenantDataPolicy::TenantOwned,
                [
                    'id',
                    'supplier_id',
                    'revision_id',
                    'calculation_kind',
                    'schema_version',
                    'result_status',
                    'ruleset_id',
                    'ruleset_hash',
                    'input_snapshot_json',
                    'input_snapshot_hash',
                    'result_snapshot_json',
                    'result_snapshot_hash',
                    'result_set_hash',
                ],
                ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                references: [
                    $this->reference(
                        ['revision_id'],
                        'table:payroll_run_revisions',
                    ),
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
                derivedHashes: $this->snapshotHashes(),
                referenceKeys: [[
                    'supplier_id',
                    'id',
                    'revision_id',
                    'calculation_kind',
                ]],
                deferredUpdates: false,
                preservedIdentifiers: ['ruleset_id'],
            );
            $definitions[] = $this->definitionFor(
                'table:payroll_statutory_person_results',
                TenantDataPolicy::TenantOwned,
                [
                    'id',
                    'supplier_id',
                    'statutory_result_id',
                    'revision_id',
                    'calculation_kind',
                    'employee_id',
                    'result_status',
                    'input_snapshot_json',
                    'input_snapshot_hash',
                    'result_snapshot_json',
                    'result_snapshot_hash',
                ],
                ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                references: [
                    $this->reference(
                        ['revision_id'],
                        'table:payroll_run_revisions',
                    ),
                    $this->reference(
                        ['statutory_result_id'],
                        'table:payroll_statutory_results',
                    ),
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
                embeddedReferences: [[
                    'column' => 'result_snapshot_json',
                    'condition' => null,
                    'fallbacks' => [],
                    'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                    'nullable' => false,
                    'path' => ['result_id'],
                    'target' => 'table:payroll_statutory_results',
                    'target_columns' => ['id'],
                ]],
                derivedHashes: $this->snapshotHashes(),
                referenceKeys: [[
                    'supplier_id',
                    'id',
                    'statutory_result_id',
                    'revision_id',
                    'calculation_kind',
                    'employee_id',
                ]],
                deferredUpdates: false,
                preservedIdentifiers: ['employee_id'],
            );
            $definitions[] = $this->definitionFor(
                'table:payroll_statutory_relationship_results',
                TenantDataPolicy::TenantOwned,
                [
                    'id',
                    'supplier_id',
                    'statutory_result_id',
                    'person_result_id',
                    'revision_id',
                    'calculation_kind',
                    'employee_id',
                    'employment_id',
                    'result_status',
                    'input_snapshot_json',
                    'input_snapshot_hash',
                    'result_snapshot_json',
                    'result_snapshot_hash',
                ],
                ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                references: [
                    $this->reference(
                        ['person_result_id'],
                        'table:payroll_statutory_person_results',
                    ),
                    $this->reference(
                        ['revision_id'],
                        'table:payroll_run_revisions',
                    ),
                    $this->reference(
                        ['statutory_result_id'],
                        'table:payroll_statutory_results',
                    ),
                    $this->reference(['supplier_id'], 'table:supplier'),
                ],
                embeddedReferences: [[
                    'column' => 'result_snapshot_json',
                    'condition' => null,
                    'fallbacks' => [],
                    'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                    'nullable' => false,
                    'path' => ['person_id'],
                    'target' => 'table:payroll_statutory_person_results',
                    'target_columns' => ['id'],
                ]],
                derivedHashes: $this->snapshotHashes(),
                deferredUpdates: false,
                preservedIdentifiers: ['employee_id', 'employment_id'],
            );
        }
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            $definitions,
            [$profile],
        ), $profile);
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function sourceRows(
        bool $withFiles = false,
        bool $logicalIdentityCycle = false,
        bool $statutoryHashCycle = false,
        bool $invalidStatutorySeal = false,
    ): array
    {
        $firstPayload = CanonicalJson::encode([
            ...($withFiles ? [
                'logo_path' => 'storage/supplier-logos/'
                    . 'sup-7-brand-11-aaaaaaaaaaaa.png',
            ] : []),
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
                'logo_path' => $withFiles
                    ? 'storage/supplier-logos/sup-7.png'
                    : null,
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
                'node_hash' => $secondHash,
            ]],
            'table:synthetic_secrets' => [[
                'id' => 31,
                'supplier_id' => 7,
                'label' => 'Imported contact',
            ]],
            ...($logicalIdentityCycle ? [
                'table:synthetic_revisions' => [[
                    'id' => 41,
                    'supplier_id' => 7,
                    'snapshot_json' => '{"result_id":51}',
                ]],
                'table:synthetic_results' => [[
                    'id' => 51,
                    'supplier_id' => 7,
                    'revision_id' => 41,
                ]],
            ] : []),
            ...($statutoryHashCycle
                ? $this->statutoryRows($invalidStatutorySeal)
                : []),
        ];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function statutoryRows(bool $invalidSeal = false): array
    {
        $personInput = CanonicalJson::encode(['employee_id' => 17]);
        $personResult = CanonicalJson::encode([
            'person_reference' => 'employee:17',
            'result_id' => 71,
        ]);
        $person = [
            'id' => 81,
            'supplier_id' => 7,
            'statutory_result_id' => 71,
            'revision_id' => 61,
            'calculation_kind' => 'social_insurance',
            'employee_id' => 17,
            'result_status' => 'calculated',
            'input_snapshot_json' => $personInput,
            'input_snapshot_hash' => hash('sha256', $personInput),
            'result_snapshot_json' => $personResult,
            'result_snapshot_hash' => hash('sha256', $personResult),
        ];
        $relationshipInput = CanonicalJson::encode(['employment_id' => 19]);
        $relationshipResult = CanonicalJson::encode([
            'person_id' => 81,
            'relationship_reference' => 'employment:19',
        ]);
        $relationship = [
            'id' => 91,
            'supplier_id' => 7,
            'statutory_result_id' => 71,
            'person_result_id' => 81,
            'revision_id' => 61,
            'calculation_kind' => 'social_insurance',
            'employee_id' => 17,
            'employment_id' => 19,
            'result_status' => 'calculated',
            'input_snapshot_json' => $relationshipInput,
            'input_snapshot_hash' => hash('sha256', $relationshipInput),
            'result_snapshot_json' => $relationshipResult,
            'result_snapshot_hash' => hash('sha256', $relationshipResult),
        ];
        $headerInput = CanonicalJson::encode(['employee_id' => 17]);
        $headerResult = CanonicalJson::encode([
            'person_reference' => 'employee:17',
        ]);
        $header = [
            'id' => 71,
            'supplier_id' => 7,
            'revision_id' => 61,
            'calculation_kind' => 'social_insurance',
            'schema_version' => 'synthetic-statutory-result.v1',
            'result_status' => 'calculated',
            'ruleset_id' => 'synthetic-ruleset-2026.1',
            'ruleset_hash' => str_repeat('a', 64),
            'input_snapshot_json' => $headerInput,
            'input_snapshot_hash' => hash('sha256', $headerInput),
            'result_snapshot_json' => $headerResult,
            'result_snapshot_hash' => hash('sha256', $headerResult),
            'result_set_hash' => str_repeat('0', 64),
        ];
        $header['result_set_hash'] =
            CompanyBackupPayrollStatutoryResultSetAssembler::calculate(
                $header,
                [$person],
                [$relationship],
            );
        if ($invalidSeal) {
            $header['result_set_hash'] = str_repeat('0', 64);
        }
        $revisionSnapshot = CanonicalJson::encode([
            'person_hash' => $person['result_snapshot_hash'],
            'result_id' => 71,
        ]);

        return [
            'table:payroll_run_revisions' => [[
                'id' => 61,
                'supplier_id' => 7,
                'snapshot_json' => $revisionSnapshot,
                'snapshot_hash' => hash('sha256', $revisionSnapshot),
            ]],
            'table:payroll_statutory_results' => [$header],
            'table:payroll_statutory_person_results' => [$person],
            'table:payroll_statutory_relationship_results' => [$relationship],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function snapshotHashes(): array
    {
        return [[
            'algorithm' => 'sha256_canonical_json',
            'hash_column' => 'input_snapshot_hash',
            'nullable' => false,
            'source_column' => 'input_snapshot_json',
        ], [
            'algorithm' => 'sha256_canonical_json',
            'hash_column' => 'result_snapshot_hash',
            'nullable' => false,
            'source_column' => 'result_snapshot_json',
        ]];
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

    private function fileInventory(
        TenantDataRegistrySnapshot $snapshot,
        bool $withFiles,
        bool $omitNestedFileOwner,
    ): CompanyBackupFileInventory {
        $areas = [];
        if ($withFiles) {
            $areas[] = [
                'registry_key' => 'file-area:supplier-logos',
                'order' => 1,
                'entries' => [...($omitNestedFileOwner ? [] : [[
                    'source_path' =>
                        'sup-7-brand-11-aaaaaaaaaaaa.png',
                    'archive_path' => null,
                    'state' => 'missing',
                    'bytes' => null,
                    'sha256' => null,
                    'owners' => [[
                        'registry_key' => 'table:synthetic_nodes',
                        'primary_key' => ['id' => 11],
                        'column' => 'payload_json',
                        'path' => ['logo_path'],
                    ]],
                ]]), [
                    'source_path' => 'sup-7.png',
                    'archive_path' => null,
                    'state' => 'missing',
                    'bytes' => null,
                    'sha256' => null,
                    'owners' => [[
                        'registry_key' => 'table:supplier',
                        'primary_key' => ['id' => 7],
                        'column' => 'logo_path',
                        'path' => [],
                    ]],
                ]],
            ];
        }
        return CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => $areas,
        ], $snapshot);
    }

    /**
     * @param list<string> $columns
     * @param array<string,mixed> $ownership
     * @param list<array<string,mixed>> $references
     * @param list<array<string,mixed>> $embeddedReferences
     * @param list<array<string,mixed>> $embeddedHashReferences
     * @param list<array<string,mixed>> $hashReferences
     * @param list<array<string,mixed>> $derivedHashes
     * @param list<string>|null $naturalKey
     * @param array<string,mixed> $secretPolicies
     * @param array<string,string> $omitColumns
     * @param list<array<string,mixed>> $protectedSecretMaterializations
     * @param list<list<string>> $referenceKeys
     * @param bool $deferredUpdates
     * @param list<string> $preservedIdentifiers
     */
    private function definitionFor(
        string $key,
        TenantDataPolicy $policy,
        array $columns,
        array $ownership,
        array $references = [],
        array $embeddedReferences = [],
        array $embeddedHashReferences = [],
        array $hashReferences = [],
        array $derivedHashes = [],
        ?array $naturalKey = null,
        array $secretPolicies = [],
        array $omitColumns = [],
        array $protectedSecretMaterializations = [],
        array $referenceKeys = [],
        bool $deferredUpdates = true,
        array $preservedIdentifiers = [],
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            $key,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                ...($naturalKey === null ? [] : ['natural_key' => $naturalKey]),
                ...($referenceKeys === [] ? [] : [
                    'reference_keys' => $referenceKeys,
                ]),
                'ownership' => $ownership,
                'secrets' => $secretPolicies,
                'company_backup' => [
                    'data_columns' => $columns,
                    ...($deferredUpdates ? [] : ['deferred_updates' => false]),
                    'derived_hashes' => $derivedHashes,
                    'embedded_hash_references' => $embeddedHashReferences,
                    'embedded_references' => $embeddedReferences,
                    'generated_columns' => [],
                    'hash_references' => $hashReferences,
                    'omit_columns' => $omitColumns,
                    ...($preservedIdentifiers === [] ? [] : [
                        'preserved_identifiers' => $preservedIdentifiers,
                    ]),
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
                . " OR name LIKE 'company_backup_hash_%'"
                . " OR name LIKE 'company_backup_file_path_%'"
                . " OR name LIKE 'company_backup_statutory_rows_%'"
                . " OR name LIKE 'company_backup_statutory_roots_%')",
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
        private CompanyBackupFileInventory $fileInventory,
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

    public function fileInventory(): CompanyBackupFileInventory
    {
        return $this->fileInventory;
    }

    public function consumeFile(string $archivePath, callable $chunkVisitor): int
    {
        throw new \RuntimeException('synthetic_file_unavailable');
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

    public function close(): void
    {
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
