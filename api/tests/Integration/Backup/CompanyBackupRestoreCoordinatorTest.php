<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataObject;
use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflightResult;
use MyInvoice\Service\Backup\Company\CompanyBackupDatabaseImport;
use MyInvoice\Service\Backup\Company\CompanyBackupDatabaseImportResult;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFilePublicationPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupFileRestoreStager;
use MyInvoice\Service\Backup\Company\CompanyBackupFileStagingRootResolver;
use MyInvoice\Service\Backup\Company\CompanyBackupImportSource;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupRestoreCoordinator;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayload;
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

/** Živá MariaDB kontrola commit pořadí a výchozí post-import validace. */
#[Group('integration')]
final class CompanyBackupRestoreCoordinatorTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';
    private const INSTANCE_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const TECHNICAL_BINDING =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private Connection $db;

    private string $table = '';

    private string $stagingRoot = '';

    private bool $connected = false;

    protected function setUp(): void
    {
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
            $this->db = $connection;
            $this->connected = true;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Testovací DB není dostupná: ' . $e->getMessage());
        }

        $this->table = 'company_backup_restore_' . bin2hex(random_bytes(4));
        $this->stagingRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-restore-mariadb-' . bin2hex(random_bytes(8));
        if (!mkdir($this->stagingRoot, 0700)) {
            throw new \RuntimeException('Nelze vytvořit restore staging testu.');
        }
        $pdo->exec(
            'CREATE TABLE `' . $this->table . '` ('
            . '`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,'
            . '`supplier_id` BIGINT UNSIGNED NOT NULL,'
            . '`value` VARCHAR(64) NOT NULL'
            . ') ENGINE=InnoDB',
        );
    }

    protected function tearDown(): void
    {
        if ($this->connected) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($this->table !== '') {
                $pdo->exec('DROP TABLE IF EXISTS `' . $this->table . '`');
            }
            $this->db->close();
        }
        if ($this->stagingRoot !== '') {
            $this->removeTree($this->stagingRoot);
        }
    }

    public function testCommitsUnderRepeatableReadAfterRegistryReplay(): void
    {
        $pdo = $this->db->pdo();
        self::assertSame('mysql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertNotFalse($pdo->exec(
            'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED',
        ));
        [$source, $preflight, $decisions, $plan] = $this->context();
        $importer = new MariaDbCoordinatorDatabaseImporter(
            $pdo,
            $this->table,
            $plan,
        );
        $coordinator = new CompanyBackupRestoreCoordinator(
            $pdo,
            importer: $importer,
            stager: new CompanyBackupFileRestoreStager(
                new MariaDbCoordinatorStagingRootResolver($this->stagingRoot),
            ),
        );

        $result = $coordinator->restore(
            $source,
            self::BACKUP_ID,
            $preflight,
            $decisions,
            $this->sensitiveData(),
        );

        self::assertFalse($pdo->inTransaction());
        self::assertSame('REPEATABLE-READ', $importer->isolation);
        self::assertSame('READ-COMMITTED', $this->sessionIsolation($pdo));
        self::assertSame(41, $result->database->supplierId);
        self::assertSame(1, $result->postImport->checkedTenantRows);
        self::assertSame(0, $result->postImport->invariantReport->invariantCount);
        self::assertSame(0, $result->publishedFileCount);
        self::assertSame(1, $source->closes);
        self::assertSame(1, $this->rowCount($pdo));
        self::assertSame([], $this->entries($this->stagingRoot));
    }

    public function testRollbackAlsoRestoresPreviousSessionIsolation(): void
    {
        $pdo = $this->db->pdo();
        self::assertNotFalse($pdo->exec(
            'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED',
        ));
        [$source, $preflight, $decisions, $plan] = $this->context();
        $failure = new \DomainException('synthetic_archive_changed');
        $source->closeFailure = $failure;
        $importer = new MariaDbCoordinatorDatabaseImporter(
            $pdo,
            $this->table,
            $plan,
        );
        $coordinator = new CompanyBackupRestoreCoordinator(
            $pdo,
            importer: $importer,
            stager: new CompanyBackupFileRestoreStager(
                new MariaDbCoordinatorStagingRootResolver($this->stagingRoot),
            ),
        );

        try {
            $coordinator->restore(
                $source,
                self::BACKUP_ID,
                $preflight,
                $decisions,
                $this->sensitiveData(),
            );
            self::fail('Chybná závěrečná kontrola archivu nesmí být commitnuta.');
        } catch (\DomainException $e) {
            self::assertSame($failure, $e);
        }

        self::assertFalse($pdo->inTransaction());
        self::assertSame('REPEATABLE-READ', $importer->isolation);
        self::assertSame('READ-COMMITTED', $this->sessionIsolation($pdo));
        self::assertSame(0, $this->rowCount($pdo));
        self::assertSame(1, $source->closes);
        self::assertSame([], $this->entries($this->stagingRoot));
    }

    /**
     * @return array{
     *   MariaDbCoordinatorImportSource,
     *   CompanyBackupDataPreflightResult,
     *   CompanyBackupReferenceDecisionPlan,
     *   CompanyBackupFilePublicationPlan
     * }
     */
    private function context(): array
    {
        $snapshot = $this->snapshot();
        $definition = $snapshot->registry->definition(
            'table:' . $this->table,
        );
        self::assertInstanceOf(TenantDataDefinition::class, $definition);
        $dataInventory = CompanyBackupDataInventory::fromObjects([
            CompanyBackupDataObject::fromWrittenPayload(
                $definition,
                1,
                1,
                0,
                hash('sha256', ''),
            ),
        ], $snapshot);
        $fileInventory = CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [],
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
            new MariaDbCoordinatorImportSource(
                $snapshot,
                $dataInventory,
                $fileInventory,
                self::TECHNICAL_BINDING,
            ),
            $preflight,
            $decisions,
            $plan,
        ];
    }

    private function snapshot(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [new TenantDataDefinition(
                'table:' . $this->table,
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantOwned,
                [$profile],
                [
                    'primary_key' => ['id'],
                    'ownership' => [
                        'strategy' => 'supplier_id',
                        'column' => 'supplier_id',
                    ],
                    'secrets' => [],
                    'company_backup' => [
                        'data_columns' => ['id', 'supplier_id', 'value'],
                        'embedded_references' => [],
                        'generated_columns' => [],
                        'omit_columns' => [],
                        'preserved_identifiers' => ['supplier_id'],
                        'references' => [],
                        'restore_overrides' => [],
                    ],
                ],
            )],
            [$profile],
        ), $profile);
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

    private function rowCount(PDO $pdo): int
    {
        $statement = $pdo->query(
            'SELECT COUNT(*) FROM `' . $this->table . '`',
        );
        if (!$statement instanceof PDOStatement) {
            throw new \RuntimeException('Výsledek restore testu nelze přečíst.');
        }
        return (int) $statement->fetchColumn();
    }

    private function sessionIsolation(PDO $pdo): string
    {
        $statement = $pdo->query('SELECT @@SESSION.transaction_isolation');
        if (!$statement instanceof PDOStatement) {
            throw new \RuntimeException('Izolaci testovací session nelze přečíst.');
        }
        $isolation = $statement->fetchColumn();
        if (!is_string($isolation)) {
            throw new \RuntimeException('Izolace testovací session není platná.');
        }
        return strtoupper($isolation);
    }

    /** @return list<string> */
    private function entries(string $root): array
    {
        $entries = scandir($root);
        if (!is_array($entries)) {
            throw new \RuntimeException('Restore staging testu nelze přečíst.');
        }
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
final class MariaDbCoordinatorDatabaseImporter implements CompanyBackupDatabaseImport
{
    public string $isolation = '';

    public function __construct(
        private readonly PDO $database,
        private readonly string $table,
        private readonly CompanyBackupFilePublicationPlan $plan,
    ) {}

    public function restore(
        CompanyBackupImportSource $source,
        CompanyBackupDataPreflightResult $preflight,
        CompanyBackupReferenceDecisionPlan $decisions,
        PayrollSensitiveData $sensitiveData,
    ): CompanyBackupDatabaseImportResult {
        if (!$this->database->inTransaction()) {
            throw new \RuntimeException('MariaDB import nedostal transakci.');
        }
        $statement = $this->database->query('SELECT @@transaction_isolation');
        if (!$statement instanceof PDOStatement) {
            throw new \RuntimeException('Nelze přečíst izolaci restore transakce.');
        }
        $isolation = $statement->fetchColumn();
        if (!is_string($isolation)) {
            throw new \RuntimeException('Izolace restore transakce není platná.');
        }
        $this->isolation = strtoupper($isolation);
        $insert = $this->database->prepare(
            'INSERT INTO `' . $this->table . '`'
            . ' (`id`, `supplier_id`, `value`) VALUES (?, ?, ?)',
        );
        if (!$insert instanceof PDOStatement
            || !$insert->execute([101, 41, 'restored'])
        ) {
            throw new \RuntimeException('Syntetický restore řádek nelze vložit.');
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
final class MariaDbCoordinatorImportSource implements CompanyBackupImportSource
{
    public int $closes = 0;

    public ?\Throwable $closeFailure = null;

    private bool $closed = false;

    public function __construct(
        private readonly TenantDataRegistrySnapshot $snapshot,
        private readonly CompanyBackupDataInventory $dataInventory,
        private readonly CompanyBackupFileInventory $fileInventory,
        private readonly string $technicalBinding,
    ) {}

    public function sourceRegistry(): TenantDataRegistrySnapshot
    {
        return $this->snapshot;
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
        $rowVisitor(['id' => 11, 'supplier_id' => 7, 'value' => 'source']);
        return 1;
    }

    public function secretPayload(): ?CompanyBackupSecretPayload
    {
        return null;
    }

    public function consumeFile(string $archivePath, callable $chunkVisitor): int
    {
        throw new \RuntimeException('Prázdný inventář nesmí číst soubor.');
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
final readonly class MariaDbCoordinatorStagingRootResolver implements
    CompanyBackupFileStagingRootResolver
{
    public function __construct(private string $root) {}

    public function root(): string
    {
        return $this->root;
    }
}
