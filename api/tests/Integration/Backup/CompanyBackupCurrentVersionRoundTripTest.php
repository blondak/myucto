<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveInspector;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupAutoIncrementColumn;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflight;
use MyInvoice\Service\Backup\Company\CompanyBackupDataRowSource;
use MyInvoice\Service\Backup\Company\CompanyBackupDatabaseImporter;
use MyInvoice\Service\Backup\Company\CompanyBackupFileAreaRootResolver;
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFilePublisher;
use MyInvoice\Service\Backup\Company\CompanyBackupFileRestoreStager;
use MyInvoice\Service\Backup\Company\CompanyBackupFileStagingRootResolver;
use MyInvoice\Service\Backup\Company\CompanyBackupFormat;
use MyInvoice\Service\Backup\Company\CompanyBackupImportSchemaSource;
use MyInvoice\Service\Backup\Company\CompanyBackupImportTableMetadata;
use MyInvoice\Service\Backup\Company\CompanyBackupJsonlWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupMachineArchiveWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupMachineSnapshot;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupRegistryPostImportValidator;
use MyInvoice\Service\Backup\Company\CompanyBackupRestoreCoordinator;
use MyInvoice\Service\Backup\Company\CompanyBackupRestoreService;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeDescriptor;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableSchema;
use MyInvoice\Service\Backup\Company\CompanyBackupTechnicalValidation;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** Aktuální formát musí projít celou obnovou nad skutečným AES ZIPem. */
#[Group('integration')]
final class CompanyBackupCurrentVersionRoundTripTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';
    private const INSTANCE_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const PASSWORD = 'synthetic-round-trip-password-42';
    private const APP_VERSION = '5.28.1';

    private Connection $db;

    private string $recordsTable = '';

    private string $root = '';

    private bool $connected = false;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)
            || !defined('ZipArchive::EM_AES_256')
        ) {
            $this->markTestSkipped('Round-trip vyžaduje ZIP s AES-256.');
        }
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                throw new \RuntimeException('Aplikace nemá DI kontejner.');
            }
            $connection = $container->get(Connection::class);
            if (!$connection instanceof Connection) {
                throw new \RuntimeException('DI nevrátilo databázové spojení.');
            }
            $pdo = $connection->pdo();
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                throw new \RuntimeException('Test vyžaduje MariaDB.');
            }
            $this->db = $connection;
            $this->connected = true;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Testovací DB není dostupná: ' . $e->getMessage());
        }

        $this->recordsTable = 'company_backup_roundtrip_' . bin2hex(random_bytes(4));
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-company-roundtrip-' . bin2hex(random_bytes(8));
        if (!mkdir($this->root, 0700)) {
            throw new \RuntimeException('Nelze vytvořit pracovní adresář testu.');
        }

        $pdo = $this->db->pdo();
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS supplier');
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS users');
        $pdo->exec(
            'CREATE TEMPORARY TABLE supplier ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'company_name VARCHAR(190) NOT NULL,'
            . 'logo_path VARCHAR(255) NULL'
            . ') ENGINE=InnoDB',
        );
        $pdo->exec(
            'CREATE TEMPORARY TABLE users ('
            . 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY'
            . ') ENGINE=InnoDB',
        );
        $pdo->exec(
            'CREATE TABLE `' . $this->recordsTable . '` ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'supplier_id BIGINT UNSIGNED NOT NULL,'
            . 'parent_id BIGINT UNSIGNED NULL,'
            . 'code VARCHAR(64) NOT NULL,'
            . 'UNIQUE KEY uq_roundtrip_supplier_code (supplier_id, code)'
            . ') ENGINE=InnoDB',
        );
        $pdo->exec(
            "INSERT INTO supplier (id, company_name, logo_path)"
            . " VALUES (7, 'Existing tenant', NULL)",
        );
        $pdo->exec('INSERT INTO users (id) VALUES (91)');
        $pdo->exec(
            'INSERT INTO `' . $this->recordsTable . '`'
            . ' (id, supplier_id, parent_id, code) VALUES'
            . " (31, 7, NULL, 'existing-parent'),"
            . " (32, 7, 31, 'existing-child')",
        );
    }

    protected function tearDown(): void
    {
        if ($this->connected) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS supplier');
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS users');
            if ($this->recordsTable !== '') {
                $pdo->exec(
                    'DROP TABLE IF EXISTS `' . $this->recordsTable . '`',
                );
            }
            $this->db->close();
        }
        if ($this->root !== '') {
            $this->removeTree($this->root);
        }
    }

    public function testRestoresEncryptedCurrentArchiveWithCollidingIds(): void
    {
        $pdo = $this->db->pdo();
        $registry = $this->registry();
        $archive = $this->archive($registry);
        $limits = $this->limits();
        $inspection = (new CompanyBackupArchiveInspector(
            new CompanyBackupFormat([
                CompanyBackupSecretEnvelopeDescriptor::CAPABILITY,
            ]),
            BackupUpcasterRegistry::empty(),
            $limits,
        ))->inspect(
            $archive,
            self::PASSWORD,
            self::APP_VERSION,
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
        self::assertArrayHasKey('CTI-MNE.txt', $inspection->entryHashes);
        self::assertArrayNotHasKey('README.txt', $inspection->entryHashes);
        $validation = new CompanyBackupTechnicalValidation(
            $inspection,
            $registry,
            self::APP_VERSION,
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
        $preflight = (new CompanyBackupDataPreflight($limits))->inspect(
            $archive,
            self::PASSWORD,
            $validation,
            $pdo,
        );
        self::assertSame(3, $preflight->rowCount);
        self::assertSame(3, $preflight->identityCount);
        self::assertSame([], $preflight->externalReferences->requirements);
        $decisions = CompanyBackupReferenceDecisionPlan::fromArray([
            'format' => CompanyBackupReferenceDecisionPlan::FORMAT,
            'version' => CompanyBackupReferenceDecisionPlan::VERSION,
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'decisions' => [],
        ], $preflight, $registry, self::INSTANCE_ID, 91);
        $coordinator = new CompanyBackupRestoreCoordinator(
            $pdo,
            importer: new CompanyBackupDatabaseImporter(
                $pdo,
                new CurrentRoundTripSchemaSource(),
                $limits,
            ),
            postImport: new CompanyBackupRegistryPostImportValidator(
                new CurrentRoundTripRowSource(),
            ),
            stager: new CompanyBackupFileRestoreStager(
                new CurrentRoundTripStagingRootResolver(
                    $this->root . DIRECTORY_SEPARATOR . 'staging',
                ),
            ),
            publisher: new CompanyBackupFilePublisher(
                new CurrentRoundTripFileAreaRootResolver(
                    $this->root . DIRECTORY_SEPARATOR . 'live',
                ),
            ),
        );

        $result = (new CompanyBackupRestoreService(
            $coordinator,
            $limits,
        ))->restore(
            $archive,
            self::PASSWORD,
            $validation,
            $preflight,
            $decisions,
            $this->sensitiveData(),
        );

        self::assertFalse($pdo->inTransaction());
        self::assertSame(8, $result->database->supplierId);
        self::assertSame(3, $result->database->insertedRows);
        self::assertSame(2, $result->database->deferredRows);
        self::assertSame(1, $result->database->updatedRows);
        self::assertSame(2, $result->postImport->checkedTableCount);
        self::assertSame(3, $result->postImport->checkedTenantRows);
        self::assertSame(0, $result->postImport->invariantReport->invariantCount);
        self::assertSame(0, $result->postImport->invariantReport->checkCount);
        self::assertSame(1, $result->publishedFileCount);

        self::assertSame([
            [
                'id' => 7,
                'company_name' => 'Existing tenant',
                'logo_path' => null,
            ],
            [
                'id' => 8,
                'company_name' => 'Restored tenant',
                'logo_path' => 'storage/supplier-logos/sup-8.png',
            ],
        ], $this->supplierRows($pdo));
        self::assertSame([
            [
                'id' => 31,
                'supplier_id' => 7,
                'parent_id' => null,
                'code' => 'existing-parent',
            ],
            [
                'id' => 32,
                'supplier_id' => 7,
                'parent_id' => 31,
                'code' => 'existing-child',
            ],
            [
                'id' => 33,
                'supplier_id' => 8,
                'parent_id' => null,
                'code' => 'restored-parent',
            ],
            [
                'id' => 34,
                'supplier_id' => 8,
                'parent_id' => 33,
                'code' => 'restored-child',
            ],
        ], $this->recordRows($pdo));
        $restoredLogo = $this->root . DIRECTORY_SEPARATOR . 'live'
            . DIRECTORY_SEPARATOR . 'supplier-logos'
            . DIRECTORY_SEPARATOR . 'sup-8.png';
        self::assertFileExists($restoredLogo);
        self::assertSame(
            "synthetic-round-trip-logo\n",
            file_get_contents($restoredLogo),
        );
        self::assertSame([], $this->entries(
            $this->root . DIRECTORY_SEPARATOR . 'staging',
        ));
    }

    private function archive(TenantDataRegistrySnapshot $registry): string
    {
        $sourceRows = [
            'table:supplier' => [[
                'id' => 7,
                'company_name' => 'Restored tenant',
                'logo_path' => 'storage/supplier-logos/sup-7.png',
            ]],
            'table:' . $this->recordsTable => [[
                'id' => 31,
                'supplier_id' => 7,
                'parent_id' => null,
                'code' => 'restored-parent',
            ], [
                'id' => 32,
                'supplier_id' => 7,
                'parent_id' => 31,
                'code' => 'restored-child',
            ]],
        ];
        $objects = [];
        $sourceFiles = [];
        $temporaryFiles = [];
        $writer = new CompanyBackupJsonlWriter($this->limits());
        foreach (CompanyBackupDataInventory::payloadDefinitions($registry) as $index => $definition) {
            $path = $this->root . DIRECTORY_SEPARATOR
                . 'data-' . ($index + 1) . '.jsonl';
            $object = $writer->write(
                $definition,
                $index + 1,
                $sourceRows[$definition->key],
                $path,
            );
            $objects[] = $object;
            $sourceFiles[$object->path] = $path;
            $temporaryFiles[$object->path] = $path;
        }
        $inventory = CompanyBackupDataInventory::fromObjects(
            $objects,
            $registry,
        );
        $logoContents = "synthetic-round-trip-logo\n";
        $logoPath = $this->root . DIRECTORY_SEPARATOR . 'source-logo.png';
        if (file_put_contents($logoPath, $logoContents) !== strlen($logoContents)) {
            throw new \RuntimeException('Nelze zapsat syntetické logo.');
        }
        $logoHash = hash('sha256', $logoContents);
        $logoArchivePath = 'files/supplier-logos/' . $logoHash . '.png';
        $fileInventory = CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [[
                'registry_key' => 'file-area:supplier-logos',
                'order' => 1,
                'entries' => [[
                    'source_path' => 'sup-7.png',
                    'archive_path' => $logoArchivePath,
                    'state' => 'present',
                    'bytes' => strlen($logoContents),
                    'sha256' => $logoHash,
                    'owners' => [[
                        'registry_key' => 'table:supplier',
                        'primary_key' => ['id' => 7],
                        'column' => 'logo_path',
                        'path' => [],
                    ]],
                ]],
            ]],
        ], $registry);
        $sourceFiles[$logoArchivePath] = $logoPath;
        $snapshot = new CompanyBackupMachineSnapshot(
            7,
            self::BACKUP_ID,
            $registry,
            $inventory,
            $fileInventory,
            CompanyBackupSecretInventory::fromCounts([], $registry),
            null,
            $sourceFiles,
            $temporaryFiles,
        );
        $archive = $this->root . DIRECTORY_SEPARATOR . 'company-backup.zip';
        (new CompanyBackupMachineArchiveWriter($this->limits()))->write(
            $snapshot,
            $archive,
            self::PASSWORD,
            self::APP_VERSION,
            "Syntetická přenositelná záloha.\n",
        );
        return $archive;
    }

    private function registry(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                $this->tableDefinition(
                    'table:' . $this->recordsTable,
                    TenantDataPolicy::TenantOwned,
                    ['id', 'supplier_id', 'parent_id', 'code'],
                    [
                        'strategy' => 'supplier_id',
                        'column' => 'supplier_id',
                    ],
                    [
                        $this->reference(
                            'parent_id',
                            'table:' . $this->recordsTable,
                            true,
                        ),
                        $this->reference('supplier_id', 'table:supplier'),
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
                $this->tableDefinition(
                    'table:supplier',
                    TenantDataPolicy::TenantRoot,
                    ['id', 'company_name', 'logo_path'],
                    ['strategy' => 'selected_supplier', 'column' => 'id'],
                    [],
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

    /**
     * @param list<string> $columns
     * @param array<string,mixed> $ownership
     * @param list<array<string,mixed>> $references
     */
    private function tableDefinition(
        string $key,
        TenantDataPolicy $policy,
        array $columns,
        array $ownership,
        array $references,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            $key,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => $ownership,
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => $columns,
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' => $references,
                    'restore_overrides' => [],
                ],
            ],
        );
    }

    /** @return array<string,mixed> */
    private function reference(
        string $column,
        string $target,
        bool $nullable = false,
    ): array {
        return [
            'columns' => [$column],
            'target' => $target,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }

    private function limits(): CompanyBackupArchiveLimits
    {
        return new CompanyBackupArchiveLimits(
            maxArchiveBytes: 2_000_000,
            maxEntries: 30,
            maxEntryBytes: 200_000,
            maxExpandedBytes: 1_000_000,
            maxCompressionRatio: 1_000,
            maxManifestBytes: 200_000,
            maxChecksumsBytes: 20_000,
        );
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

    /** @return list<array{id:int,company_name:string,logo_path:?string}> */
    private function supplierRows(PDO $pdo): array
    {
        $rows = $this->fetchRows(
            $pdo,
            'SELECT id, company_name, logo_path FROM supplier ORDER BY id',
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'company_name' => (string) $row['company_name'],
            'logo_path' => is_string($row['logo_path'])
                ? $row['logo_path']
                : null,
        ], $rows);
    }

    /** @return list<array{id:int,supplier_id:int,parent_id:?int,code:string}> */
    private function recordRows(PDO $pdo): array
    {
        $rows = $this->fetchRows(
            $pdo,
            'SELECT id, supplier_id, parent_id, code FROM `'
                . $this->recordsTable . '` ORDER BY id',
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'supplier_id' => (int) $row['supplier_id'],
            'parent_id' => $row['parent_id'] === null
                ? null
                : (int) $row['parent_id'],
            'code' => (string) $row['code'],
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function fetchRows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        if (!$statement instanceof PDOStatement) {
            throw new \RuntimeException('Syntetický SELECT selhal.');
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!array_is_list($rows)) {
            throw new \RuntimeException('Syntetický SELECT nevrátil seznam.');
        }
        return $rows;
    }

    /** @return list<string> */
    private function entries(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $entries = scandir($directory);
        if (!is_array($entries)) {
            throw new \RuntimeException('Nelze přečíst testovací adresář.');
        }
        return array_values(array_diff($entries, ['.', '..']));
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir()
                ? @rmdir($item->getPathname())
                : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}

/** @internal Přesné runtime metadata syntetických InnoDB tabulek round-tripu. */
final readonly class CurrentRoundTripSchemaSource implements
    CompanyBackupImportSchemaSource
{
    public function read(
        PDO $database,
        CompanyBackupTableProjection $projection,
    ): CompanyBackupTableSchema {
        return new CompanyBackupTableSchema(
            $projection->dataColumns,
            [],
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

/** @internal Registry replay omezený na tenantové řádky round-trip tabulek. */
final readonly class CurrentRoundTripRowSource implements CompanyBackupDataRowSource
{
    /** @return iterable<int,array<string,mixed>> */
    public function rows(
        PDO $snapshot,
        int $supplierId,
        TenantDataDefinition $definition,
    ): iterable {
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $column = $definition->policy === TenantDataPolicy::TenantRoot
            ? 'id'
            : 'supplier_id';
        $columns = implode(', ', array_map(
            static fn (string $name): string => '`' . $name . '`',
            $projection->dataColumns,
        ));
        $order = implode(', ', array_map(
            static fn (string $name): string => '`' . $name . '`',
            $projection->primaryKey,
        ));
        $statement = $snapshot->prepare(
            'SELECT ' . $columns . ' FROM `' . $projection->name . '`'
                . ' WHERE `' . $column . '` = ? ORDER BY ' . $order,
        );
        if (!$statement instanceof PDOStatement
            || !$statement->execute([$supplierId])
        ) {
            throw new \RuntimeException('Round-trip registry replay selhal.');
        }
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \RuntimeException(
                    'Round-trip registry replay vrátil neplatný řádek.',
                );
            }
            yield $row;
        }
        if (!$statement->closeCursor()) {
            throw new \RuntimeException('Round-trip registry replay nelze uzavřít.');
        }
    }
}

/** @internal */
final readonly class CurrentRoundTripStagingRootResolver implements
    CompanyBackupFileStagingRootResolver
{
    public function __construct(private string $root) {}

    public function root(): string
    {
        return $this->root;
    }
}

/** @internal */
final readonly class CurrentRoundTripFileAreaRootResolver implements
    CompanyBackupFileAreaRootResolver
{
    public function __construct(private string $root) {}

    public function resolve(string $storageSubdirectory): string
    {
        return $this->root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $storageSubdirectory);
    }
}
