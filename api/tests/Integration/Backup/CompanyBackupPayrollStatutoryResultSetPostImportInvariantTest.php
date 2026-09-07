<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\Company\CompanyBackupPayrollStatutoryResultSetPostImportInvariant;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Živá MariaDB kontrola post-import agregátu nad produkčními projekcemi. */
#[Group('integration')]
final class CompanyBackupPayrollStatutoryResultSetPostImportInvariantTest extends
    TestCase
{
    private Connection $db;

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
            $connection->pdo();
            $this->db = $connection;
            $this->connected = true;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Testovací DB není dostupná: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!$this->connected) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->db->close();
    }

    public function testReadsProductionTablesWithoutEndingCallerTransaction(): void
    {
        $pdo = $this->db->pdo();
        self::assertSame('mysql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $draft = TenantDataRegistryFactory::draftV1();
        $complete = new TenantDataRegistry(
            $draft->version,
            $draft->definitions(),
            [
                TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
                TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            ],
        );
        $snapshot = TenantDataRegistrySnapshot::fromRegistry(
            $complete,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        );
        $supplierId = $this->unusedSupplierId($pdo);
        self::assertTrue($pdo->beginTransaction());

        $checks = (new CompanyBackupPayrollStatutoryResultSetPostImportInvariant())
            ->validate($pdo, $supplierId, $snapshot);

        self::assertSame(0, $checks);
        self::assertTrue($pdo->inTransaction());
    }

    private function unusedSupplierId(PDO $database): int
    {
        $statement = $database->query(
            'SELECT COALESCE(MAX(id), 0) + 1 FROM supplier',
        );
        if ($statement === false) {
            throw new \RuntimeException('Nelze vybrat volné syntetické ID firmy.');
        }
        $value = $statement->fetchColumn();
        if (!$statement->closeCursor()
            || (!is_int($value) && !is_string($value))
        ) {
            throw new \RuntimeException('Volné syntetické ID firmy není platné.');
        }
        $supplierId = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if (!is_int($supplierId)) {
            throw new \RuntimeException('Volné syntetické ID firmy není kladné.');
        }
        return $supplierId;
    }
}
