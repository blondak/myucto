<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataObject;
use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflightResult;
use MyInvoice\Service\Backup\Company\CompanyBackupDataRowSource;
use MyInvoice\Service\Backup\Company\CompanyBackupDatabaseImport;
use MyInvoice\Service\Backup\Company\CompanyBackupDatabaseImportResult;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFileAreaRootResolver;
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFilePublicationPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupFilePublisher;
use MyInvoice\Service\Backup\Company\CompanyBackupFileRestoreException;
use MyInvoice\Service\Backup\Company\CompanyBackupFileRestoreStager;
use MyInvoice\Service\Backup\Company\CompanyBackupFileStagingRootResolver;
use MyInvoice\Service\Backup\Company\CompanyBackupImportSource;
use MyInvoice\Service\Backup\Company\CompanyBackupPostImportValidationResult;
use MyInvoice\Service\Backup\Company\CompanyBackupPostImportException;
use MyInvoice\Service\Backup\Company\CompanyBackupPostImportValidator;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupRegistryPostImportValidator;
use MyInvoice\Service\Backup\Company\CompanyBackupRestoreCoordinator;
use MyInvoice\Service\Backup\Company\CompanyBackupRestoreException;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayload;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class CompanyBackupRestoreCoordinatorTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';
    private const INSTANCE_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const TECHNICAL_BINDING =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private PDO $database;

    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro orchestration test.');
        }
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->database->exec(
            'CREATE TABLE restore_marker (id INTEGER PRIMARY KEY, value TEXT NOT NULL)',
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeTree($root);
        }
    }

    public function testCommitsDatabaseAndReleasesPublishedFilesOnlyAfterValidation(): void
    {
        [$source, $preflight, $decisions, $plan, $archivePath] = $this->context();
        $importer = new CoordinatorDatabaseImporter($this->database, $plan);
        $validator = new CoordinatorPostImportValidator();
        [$coordinator, $stagingRoot, $liveRoot] = $this->coordinator(
            $importer,
            $validator,
        );

        $result = $coordinator->restore(
            $source,
            self::BACKUP_ID,
            $preflight,
            $decisions,
            $this->sensitiveData(),
        );

        self::assertFalse($this->database->inTransaction());
        self::assertSame(1, $this->markerCount());
        self::assertSame(41, $result->database->supplierId);
        self::assertSame(1, $result->publishedFileCount);
        self::assertSame(1, $validator->calls);
        self::assertSame(1, $source->closes);
        self::assertSame(1, $source->fileReads[$archivePath] ?? null);
        self::assertSame([], $this->entries($stagingRoot));
        self::assertSame(
            'synthetic-logo',
            file_get_contents($this->target($liveRoot)),
        );
    }

    public function testPartialDatabaseImportFailureRollsBackAndCleansStaging(): void
    {
        [$source, $preflight, $decisions, $plan] = $this->context();
        $failure = new \DomainException('synthetic_import_failure');
        $importer = new CoordinatorDatabaseImporter(
            $this->database,
            $plan,
            $failure,
        );
        [$coordinator, $stagingRoot, $liveRoot] = $this->coordinator(
            $importer,
            new CoordinatorPostImportValidator(),
        );

        try {
            $coordinator->restore(
                $source,
                self::BACKUP_ID,
                $preflight,
                $decisions,
                $this->sensitiveData(),
            );
            self::fail('Částečný import nesmí být commitnut.');
        } catch (\DomainException $e) {
            self::assertSame($failure, $e);
        }

        self::assertFalse($this->database->inTransaction());
        self::assertSame(0, $this->markerCount());
        self::assertSame([], $this->entries($stagingRoot));
        self::assertSame([], $this->entries($liveRoot));
        self::assertSame(1, $source->closes);
    }

    public function testRejectsNestedTransactionWithoutChangingCallerState(): void
    {
        [$source, $preflight, $decisions, $plan] = $this->context();
        [$coordinator, $stagingRoot, $liveRoot] = $this->coordinator(
            new CoordinatorDatabaseImporter($this->database, $plan),
            new CoordinatorPostImportValidator(),
        );
        self::assertTrue($this->database->beginTransaction());

        try {
            $coordinator->restore(
                $source,
                self::BACKUP_ID,
                $preflight,
                $decisions,
                $this->sensitiveData(),
            );
            self::fail('Koordinátor nesmí převzít cizí otevřenou transakci.');
        } catch (CompanyBackupRestoreException $e) {
            self::assertSame('restore_transaction_nested', $e->errorCode);
        }

        self::assertTrue($this->database->inTransaction());
        self::assertSame(0, $this->markerCount());
        self::assertSame([], $this->entries($stagingRoot));
        self::assertSame([], $this->entries($liveRoot));
        self::assertSame(0, $source->closes);
        self::assertTrue($this->database->rollBack());
        $source->close();
    }

    public function testPublicationCollisionRollsBackDatabaseAndKeepsForeignTarget(): void
    {
        [$source, $preflight, $decisions, $plan] = $this->context();
        $importer = new CoordinatorDatabaseImporter($this->database, $plan);
        [$coordinator, $stagingRoot, $liveRoot] = $this->coordinator(
            $importer,
            new CoordinatorPostImportValidator(),
        );
        $target = $this->target($liveRoot);
        self::assertTrue(mkdir(dirname($target), 0750, true));
        self::assertSame(7, file_put_contents($target, 'foreign'));

        try {
            $coordinator->restore(
                $source,
                self::BACKUP_ID,
                $preflight,
                $decisions,
                $this->sensitiveData(),
            );
            self::fail('Kolize živého souboru musí obnovu zastavit.');
        } catch (CompanyBackupFileRestoreException $e) {
            self::assertSame('file_restore_destination_exists', $e->errorCode);
        }

        self::assertSame(0, $this->markerCount());
        self::assertSame('foreign', file_get_contents($target));
        self::assertSame([], $this->entries($stagingRoot));
        self::assertSame(1, $source->closes);
    }

    public function testPostImportFailureRollsBackDatabaseAndPublishedFile(): void
    {
        [$source, $preflight, $decisions, $plan] = $this->context();
        $failure = new \DomainException('synthetic_post_import_failure');
        [$coordinator, $stagingRoot, $liveRoot] = $this->coordinator(
            new CoordinatorDatabaseImporter($this->database, $plan),
            new CoordinatorPostImportValidator($failure),
        );

        try {
            $coordinator->restore(
                $source,
                self::BACKUP_ID,
                $preflight,
                $decisions,
                $this->sensitiveData(),
            );
            self::fail('Neplatný post-import stav nesmí být commitnut.');
        } catch (\DomainException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame(0, $this->markerCount());
        self::assertFileDoesNotExist($this->target($liveRoot));
        self::assertSame([], $this->entries($stagingRoot));
        self::assertSame(1, $source->closes);
    }

    public function testArchiveCloseFailureRollsBackBeforeCommit(): void
    {
        [$source, $preflight, $decisions, $plan] = $this->context();
        $failure = new \DomainException('synthetic_archive_changed');
        $source->closeFailure = $failure;
        [$coordinator, $stagingRoot, $liveRoot] = $this->coordinator(
            new CoordinatorDatabaseImporter($this->database, $plan),
            new CoordinatorPostImportValidator(),
        );

        try {
            $coordinator->restore(
                $source,
                self::BACKUP_ID,
                $preflight,
                $decisions,
                $this->sensitiveData(),
            );
            self::fail('Změněný archiv nesmí být commitnut.');
        } catch (\DomainException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame(0, $this->markerCount());
        self::assertFileDoesNotExist($this->target($liveRoot));
        self::assertSame([], $this->entries($stagingRoot));
        self::assertSame(1, $source->closes);
    }

    public function testLostTransactionKeepsFilesForPossiblyCommittedDatabase(): void
    {
        [$source, $preflight, $decisions, $plan] = $this->context();
        $validator = new CoordinatorPostImportValidator(commitTransaction: true);
        [$coordinator, $stagingRoot, $liveRoot] = $this->coordinator(
            new CoordinatorDatabaseImporter($this->database, $plan),
            $validator,
        );

        try {
            $coordinator->restore(
                $source,
                self::BACKUP_ID,
                $preflight,
                $decisions,
                $this->sensitiveData(),
            );
            self::fail('Koordinátor musí odhalit cizí ukončení transakce.');
        } catch (CompanyBackupRestoreException $e) {
            self::assertSame('restore_transaction_outcome_unknown', $e->errorCode);
        }

        self::assertSame(1, $this->markerCount());
        self::assertSame(
            'synthetic-logo',
            file_get_contents($this->target($liveRoot)),
        );
        self::assertSame([], $this->entries($stagingRoot));
        self::assertSame(1, $source->closes);
    }

    public function testRegistryPostImportValidatorReplaysEntireTargetTenant(): void
    {
        [$source, $preflight, , $plan] = $this->context();
        $rows = new CoordinatorPostImportRowSource(1);
        $validator = new CompanyBackupRegistryPostImportValidator($rows);
        $result = $this->databaseResult($plan);
        self::assertTrue($this->database->beginTransaction());

        $validation = $validator->validate(
            $this->database,
            $source,
            $preflight,
            $result,
        );

        self::assertSame(1, $validation->checkedTableCount);
        self::assertSame(1, $validation->checkedTenantRows);
        self::assertSame(0, $validation->mappedGlobalRows);
        self::assertSame(1, $validation->presentFileCount);
        self::assertSame(0, $validation->missingFileCount);
        self::assertSame([41], $rows->supplierIds);
        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRegistryPostImportValidatorRejectsMissingTargetRow(): void
    {
        [$source, $preflight, , $plan] = $this->context();
        $validator = new CompanyBackupRegistryPostImportValidator(
            new CoordinatorPostImportRowSource(0),
        );
        self::assertTrue($this->database->beginTransaction());

        try {
            $validator->validate(
                $this->database,
                $source,
                $preflight,
                $this->databaseResult($plan),
            );
            self::fail('Chybějící cílový řádek musí post-import kontrola odmítnout.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame('post_import_row_count_mismatch', $e->errorCode);
            self::assertSame('table:supplier', $e->registryKey);
        }

        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRegistryPostImportValidatorRejectsLostTransaction(): void
    {
        [$source, $preflight, , $plan] = $this->context();
        $validator = new CompanyBackupRegistryPostImportValidator(
            new CoordinatorPostImportRowSource(1, commitTransaction: true),
        );
        self::assertTrue($this->database->beginTransaction());

        try {
            $validator->validate(
                $this->database,
                $source,
                $preflight,
                $this->databaseResult($plan),
            );
            self::fail('Post-import reader nesmí ukončit transakci.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame('post_import_transaction_lost', $e->errorCode);
            self::assertSame('table:supplier', $e->registryKey);
        }

        self::assertFalse($this->database->inTransaction());
    }

    public function testRegistryPostImportValidatorRejectsChangedSourceRegistry(): void
    {
        [$source, $preflight, , $plan] = $this->context();
        $source->sourceRegistryOverride = $this->snapshot(2);
        $validator = new CompanyBackupRegistryPostImportValidator(
            new CoordinatorPostImportRowSource(1),
        );
        self::assertTrue($this->database->beginTransaction());

        try {
            $validator->validate(
                $this->database,
                $source,
                $preflight,
                $this->databaseResult($plan),
            );
            self::fail('Změněný zdrojový registry kontrakt musí kontrola odmítnout.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame('post_import_context_mismatch', $e->errorCode);
        }

        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    public function testRegistryPostImportValidatorRejectsUnboundPublicationPlan(): void
    {
        [$source, $preflight] = $this->context();
        $snapshot = $source->targetRegistry();
        $content = 'synthetic-logo';
        $sha256 = hash('sha256', $content);
        $archivePath = 'files/supplier-logos/' . $sha256 . '.png';
        $otherInventory = CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [[
                'registry_key' => 'file-area:supplier-logos',
                'order' => 1,
                'entries' => [[
                    'source_path' =>
                        'sup-7-brand-11-aaaaaaaaaaaa.png',
                    'archive_path' => $archivePath,
                    'state' => 'present',
                    'bytes' => strlen($content),
                    'sha256' => $sha256,
                    'owners' => [[
                        'registry_key' => 'table:supplier',
                        'primary_key' => ['id' => 7],
                        'column' => 'logo_path',
                        'path' => [],
                    ]],
                ]],
            ]],
        ], $snapshot);
        $otherPlan = CompanyBackupFilePublicationPlan::fromInventory(
            $otherInventory,
            $snapshot,
            7,
            41,
        );
        $validator = new CompanyBackupRegistryPostImportValidator(
            new CoordinatorPostImportRowSource(1),
        );
        self::assertTrue($this->database->beginTransaction());

        try {
            $validator->validate(
                $this->database,
                $source,
                $preflight,
                $this->databaseResult($otherPlan),
            );
            self::fail('Publication plán musí být odvozený ze zdrojového inventáře.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame(
                'post_import_publication_plan_mismatch',
                $e->errorCode,
            );
        }

        self::assertTrue($this->database->inTransaction());
        self::assertTrue($this->database->rollBack());
    }

    /**
     * @return array{
     *   CoordinatorImportSource,
     *   CompanyBackupDataPreflightResult,
     *   CompanyBackupReferenceDecisionPlan,
     *   CompanyBackupFilePublicationPlan,
     *   string
     * }
     */
    private function context(): array
    {
        $snapshot = $this->snapshot();
        $supplier = $snapshot->registry->definition('table:supplier');
        self::assertInstanceOf(TenantDataDefinition::class, $supplier);
        $dataInventory = CompanyBackupDataInventory::fromObjects([
            CompanyBackupDataObject::fromWrittenPayload(
                $supplier,
                1,
                1,
                0,
                hash('sha256', ''),
            ),
        ], $snapshot);
        $content = 'synthetic-logo';
        $sha256 = hash('sha256', $content);
        $archivePath = 'files/supplier-logos/' . $sha256 . '.png';
        $fileInventory = CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [[
                'registry_key' => 'file-area:supplier-logos',
                'order' => 1,
                'entries' => [[
                    'source_path' => 'sup-7.png',
                    'archive_path' => $archivePath,
                    'state' => 'present',
                    'bytes' => strlen($content),
                    'sha256' => $sha256,
                    'owners' => [[
                        'registry_key' => 'table:supplier',
                        'primary_key' => ['id' => 7],
                        'column' => 'logo_path',
                        'path' => [],
                    ]],
                ]],
            ]],
        ], $snapshot);
        $preflight = new CompanyBackupDataPreflightResult(
            new CompanyBackupExternalReferenceInventory([]),
            1,
            1,
            1,
            128,
            0,
            $snapshot->fingerprint,
            self::TECHNICAL_BINDING,
        );
        $decisions = CompanyBackupReferenceDecisionPlan::fromArray([
            'format' => CompanyBackupReferenceDecisionPlan::FORMAT,
            'version' => CompanyBackupReferenceDecisionPlan::VERSION,
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'decisions' => [],
        ], $preflight, $snapshot, self::INSTANCE_ID, 91);
        $plan = CompanyBackupFilePublicationPlan::fromInventory(
            $fileInventory,
            $snapshot,
            7,
            41,
        );
        return [
            new CoordinatorImportSource(
                $snapshot,
                $dataInventory,
                $fileInventory,
                [$archivePath => $content],
                self::TECHNICAL_BINDING,
            ),
            $preflight,
            $decisions,
            $plan,
            $archivePath,
        ];
    }

    private function snapshot(int $version = 1): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            $version,
            [
                new TenantDataDefinition(
                    'table:supplier',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::TenantRoot,
                    [$profile],
                    [
                        'primary_key' => ['id'],
                        'ownership' => [
                            'strategy' => 'selected_supplier',
                            'column' => 'id',
                        ],
                        'secrets' => [],
                        'company_backup' => [
                            'data_columns' => ['id', 'logo_path'],
                            'embedded_references' => [],
                            'generated_columns' => [],
                            'omit_columns' => [],
                            'references' => [],
                            'restore_overrides' => [],
                        ],
                    ],
                ),
                new TenantDataDefinition(
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
                        ]],
                        'ownership' => ['strategy' => 'database_references'],
                        'storage_subdirectory' => 'supplier-logos',
                    ],
                ),
            ],
            [$profile],
        ), $profile);
    }

    /**
     * @return array{
     *   CompanyBackupRestoreCoordinator,
     *   string,
     *   string
     * }
     */
    private function coordinator(
        CompanyBackupDatabaseImport $importer,
        CompanyBackupPostImportValidator $validator,
    ): array {
        $stagingRoot = $this->root('staging');
        $liveRoot = $this->root('live');
        return [
            new CompanyBackupRestoreCoordinator(
                $this->database,
                $importer,
                $validator,
                new CompanyBackupFileRestoreStager(
                    new CoordinatorStagingRootResolver($stagingRoot),
                ),
                new CompanyBackupFilePublisher(
                    new CoordinatorFileAreaRootResolver($liveRoot),
                ),
            ),
            $stagingRoot,
            $liveRoot,
        ];
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

    private function databaseResult(
        CompanyBackupFilePublicationPlan $plan,
    ): CompanyBackupDatabaseImportResult {
        return new CompanyBackupDatabaseImportResult(
            41,
            0,
            1,
            0,
            0,
            1,
            1,
            0,
            0,
            $plan,
        );
    }

    private function markerCount(): int
    {
        $statement = $this->database->query(
            'SELECT COUNT(*) FROM restore_marker',
        );
        if (!$statement instanceof PDOStatement) {
            throw new \RuntimeException('Syntetický marker nelze přečíst.');
        }
        return (int) $statement->fetchColumn();
    }

    private function target(string $root): string
    {
        return $root . DIRECTORY_SEPARATOR . 'supplier-logos'
            . DIRECTORY_SEPARATOR . 'sup-41.png';
    }

    private function root(string $label): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-restore-coordinator-' . $label . '-'
            . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $this->roots[] = $root;
        return $root;
    }

    /** @return list<string> */
    private function entries(string $root): array
    {
        $entries = scandir($root);
        self::assertIsArray($entries);
        return array_values(array_diff($entries, ['.', '..']));
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root) || is_link($root)) {
            @unlink($root);
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($root);
    }
}

/** @internal */
final class CoordinatorDatabaseImporter implements CompanyBackupDatabaseImport
{
    public function __construct(
        private readonly PDO $database,
        private readonly CompanyBackupFilePublicationPlan $plan,
        private readonly ?\Throwable $failure = null,
    ) {}

    public function restore(
        CompanyBackupImportSource $source,
        CompanyBackupDataPreflightResult $preflight,
        CompanyBackupReferenceDecisionPlan $decisions,
        PayrollSensitiveData $sensitiveData,
    ): CompanyBackupDatabaseImportResult {
        if (!$this->database->inTransaction()) {
            throw new \LogicException('Syntetický import nedostal transakci.');
        }
        $statement = $this->database->prepare(
            'INSERT INTO restore_marker (id, value) VALUES (1, ?)',
        );
        if ($statement === false || !$statement->execute(['restored'])) {
            throw new \RuntimeException('Syntetický import nelze zapsat.');
        }
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }
        return new CompanyBackupDatabaseImportResult(
            41,
            0,
            1,
            0,
            0,
            1,
            1,
            0,
            0,
            $this->plan,
        );
    }
}

/** @internal */
final class CoordinatorPostImportValidator implements CompanyBackupPostImportValidator
{
    public int $calls = 0;

    public function __construct(
        private readonly ?\Throwable $failure = null,
        private readonly bool $commitTransaction = false,
    ) {}

    public function validate(
        PDO $database,
        CompanyBackupImportSource $source,
        CompanyBackupDataPreflightResult $preflight,
        CompanyBackupDatabaseImportResult $result,
    ): CompanyBackupPostImportValidationResult {
        $this->calls++;
        if ($this->commitTransaction) {
            if (!$database->commit()) {
                throw new \RuntimeException(
                    'Syntetické ukončení transakce selhalo.',
                );
            }
        }
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }
        return new CompanyBackupPostImportValidationResult(
            $result->supplierId,
            $source->targetRegistry()->fingerprint,
            $preflight->bindingSha256,
            $result->filePublicationPlan->bindingSha256,
            1,
            1,
            0,
            1,
            0,
        );
    }
}

/** @internal */
final class CoordinatorPostImportRowSource implements CompanyBackupDataRowSource
{
    /** @var list<int> */
    public array $supplierIds = [];

    public function __construct(
        private readonly int $rowCount,
        private readonly bool $commitTransaction = false,
    ) {}

    public function rows(
        PDO $snapshot,
        int $supplierId,
        TenantDataDefinition $definition,
    ): iterable {
        $this->supplierIds[] = $supplierId;
        if ($this->commitTransaction && !$snapshot->commit()) {
            throw new \RuntimeException('Syntetický reader neukončil transakci.');
        }
        for ($index = 0; $index < $this->rowCount; $index++) {
            yield [
                'id' => 41 + $index,
                'logo_path' => 'storage/supplier-logos/sup-41.png',
            ];
        }
    }
}

/** @internal */
final class CoordinatorImportSource implements CompanyBackupImportSource
{
    public int $closes = 0;

    public ?\Throwable $closeFailure = null;

    public ?TenantDataRegistrySnapshot $sourceRegistryOverride = null;

    /** @var array<string,int> */
    public array $fileReads = [];

    private bool $closed = false;

    /** @param array<string,string> $files */
    public function __construct(
        private readonly TenantDataRegistrySnapshot $snapshot,
        private readonly CompanyBackupDataInventory $dataInventory,
        private readonly CompanyBackupFileInventory $fileInventory,
        private readonly array $files,
        private readonly string $technicalBinding,
    ) {}

    public function sourceRegistry(): TenantDataRegistrySnapshot
    {
        return $this->sourceRegistryOverride ?? $this->snapshot;
    }

    public function targetRegistry(): TenantDataRegistrySnapshot
    {
        return $this->snapshot;
    }

    public function dataInventory(): CompanyBackupDataInventory
    {
        return $this->dataInventory;
    }

    public function fileInventory(): CompanyBackupFileInventory
    {
        return $this->fileInventory;
    }

    public function technicalValidationBindingSha256(): string
    {
        return $this->technicalBinding;
    }

    public function consumeRows(
        string $registryKey,
        callable $rowVisitor,
        ?callable $referenceVisitor = null,
    ): int {
        $rowVisitor([
            'id' => 7,
            'logo_path' => 'storage/supplier-logos/sup-7.png',
        ]);
        return 1;
    }

    public function secretPayload(): ?CompanyBackupSecretPayload
    {
        return null;
    }

    public function consumeFile(string $archivePath, callable $chunkVisitor): int
    {
        if ($this->closed) {
            throw new \RuntimeException('synthetic_source_closed');
        }
        $content = $this->files[$archivePath] ?? null;
        if (!is_string($content)) {
            throw new \RuntimeException('synthetic_file_missing');
        }
        $this->fileReads[$archivePath] = ($this->fileReads[$archivePath] ?? 0) + 1;
        $chunkVisitor($content);
        return strlen($content);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->closes++;
        if ($this->closeFailure instanceof \Throwable) {
            throw $this->closeFailure;
        }
    }
}

/** @internal */
final readonly class CoordinatorStagingRootResolver implements
    CompanyBackupFileStagingRootResolver
{
    public function __construct(private string $root) {}

    public function root(): string
    {
        return $this->root;
    }
}

/** @internal */
final readonly class CoordinatorFileAreaRootResolver implements
    CompanyBackupFileAreaRootResolver
{
    public function __construct(private string $root) {}

    public function resolve(string $storageSubdirectory): string
    {
        return $this->root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $storageSubdirectory);
    }
}
