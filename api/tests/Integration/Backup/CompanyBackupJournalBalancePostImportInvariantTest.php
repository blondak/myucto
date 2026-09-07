<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\Company\CompanyBackupJournalBalancePostImportInvariant;
use MyInvoice\Service\Backup\Company\CompanyBackupPostImportException;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Živá MariaDB kontrola vyváženosti nad produkčním registry snapshotem. */
#[Group('integration')]
final class CompanyBackupJournalBalancePostImportInvariantTest extends TestCase
{
    private Connection $db;

    private bool $connected = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped(
                'cfg.php neexistuje — test vyžaduje DB connection.',
            );
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                throw new \RuntimeException('Aplikace nemá DI kontejner.');
            }
            $connection = $container->get(Connection::class);
            if (!$connection instanceof Connection
                || $connection->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME)
                    !== 'mysql'
            ) {
                throw new \RuntimeException('Test vyžaduje MariaDB spojení.');
            }
            $this->db = $connection;
            $this->connected = true;
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'Testovací DB není dostupná: ' . $e->getMessage(),
            );
        }

        $database = $this->db->pdo();
        $database->exec('DROP TEMPORARY TABLE IF EXISTS journal_entry_lines');
        $database->exec('DROP TEMPORARY TABLE IF EXISTS journal_entries');
        $database->exec(
            'CREATE TEMPORARY TABLE journal_entries ('
                . 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY,'
                . 'supplier_id BIGINT UNSIGNED NOT NULL,'
                . 'source_type VARCHAR(32) NOT NULL,'
                . 'source_id BIGINT UNSIGNED NULL,'
                . 'document_no VARCHAR(64) NULL'
                . ') ENGINE=InnoDB',
        );
        $database->exec(
            'CREATE TEMPORARY TABLE journal_entry_lines ('
                . 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY,'
                . 'entry_id BIGINT UNSIGNED NOT NULL,'
                . 'supplier_id BIGINT UNSIGNED NOT NULL,'
                . "side ENUM('debit','credit') NOT NULL,"
                . 'amount DECIMAL(15,2) NOT NULL'
                . ') ENGINE=InnoDB',
        );
    }

    protected function tearDown(): void
    {
        if (!$this->connected) {
            return;
        }
        $database = $this->db->pdo();
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        $database->exec('DROP TEMPORARY TABLE IF EXISTS journal_entry_lines');
        $database->exec('DROP TEMPORARY TABLE IF EXISTS journal_entries');
        $this->db->close();
    }

    public function testMariaDbRejectsUnbalancedRestoredJournal(): void
    {
        $database = $this->db->pdo();
        $database->exec(
            "INSERT INTO journal_entries"
                . " (id, supplier_id, source_type, source_id, document_no)"
                . " VALUES (31, 7, 'manual', NULL, 'SYNTHETIC-31')",
        );
        $database->exec(
            "INSERT INTO journal_entry_lines"
                . " (id, entry_id, supplier_id, side, amount) VALUES"
                . " (311, 31, 7, 'debit', 100.25),"
                . " (312, 31, 7, 'credit', 100.25)",
        );
        $invariant = new CompanyBackupJournalBalancePostImportInvariant();
        self::assertTrue($database->beginTransaction());

        self::assertSame(
            1,
            $invariant->validate($database, 7, $this->snapshot()),
        );
        $database->exec(
            "UPDATE journal_entry_lines SET amount = 40.00 WHERE side = 'credit'",
        );
        try {
            $invariant->validate($database, 7, $this->snapshot());
            self::fail('MariaDB musí odmítnout nevyvážený obnovený deník.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame(
                'post_import_accounting_journal_unbalanced',
                $e->errorCode,
            );
            self::assertNull($e->getPrevious());
        }

        self::assertTrue($database->inTransaction());
    }

    private function snapshot(): TenantDataRegistrySnapshot
    {
        $draft = TenantDataRegistryFactory::draftV1();
        return TenantDataRegistrySnapshot::fromRegistry(
            new TenantDataRegistry(
                $draft->version,
                $draft->definitions(),
                [
                    TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
                    TenantDataRegistry::COMPANY_BACKUP_PROFILE,
                ],
            ),
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        );
    }
}
