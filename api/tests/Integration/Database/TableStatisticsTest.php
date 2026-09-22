<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Database;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TableStatistics;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `ANALYZE TABLE` po převodu: mimo transakci přepočítá statistiky, uvnitř transakce se nesmí
 * pustit, protože v MariaDB implicitně commituje (zkouška nanečisto by se tím zapsala).
 */
#[Group('integration')]
final class TableStatisticsTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testDoesNotCommitOpenTransaction(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('CREATE TEMPORARY TABLE tmp_table_statistics_probe (id INT PRIMARY KEY) ENGINE=InnoDB');
        try {
            $pdo->beginTransaction();
            $pdo->exec('INSERT INTO tmp_table_statistics_probe VALUES (1)');

            TableStatistics::analyze($pdo, TableStatistics::IMPORTED_ACCOUNTING_TABLES);

            $pdo->rollBack();
            $kept = (int) $pdo->query('SELECT COUNT(*) FROM tmp_table_statistics_probe')->fetchColumn();
            self::assertSame(0, $kept, 'ANALYZE TABLE uvnitř transakce ji implicitně commitne.');
        } finally {
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_table_statistics_probe');
        }
    }

    public function testRefreshesStatisticsOutsideTransaction(): void
    {
        $pdo = $this->db->pdo();
        $before = (string) $pdo->query('SELECT NOW()')->fetchColumn();

        (new TableStatistics($this->db))->refreshAfterImport(['pohoda_import_map']);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM mysql.innodb_table_stats
              WHERE database_name = DATABASE() AND table_name IN (?, ?) AND last_update >= ?'
        );
        $stmt->execute(['payment_matches', 'pohoda_import_map', $before]);
        self::assertSame(2, (int) $stmt->fetchColumn());
    }
}
