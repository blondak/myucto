<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlPrimaryKeyReservation;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableSchemaReader;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Živá MariaDB kontrola rezervace rozsahu nad InnoDB primárním klíčem. */
#[Group('integration')]
final class CompanyBackupPrimaryKeyReservationTest extends TestCase
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

    public function testReadsMetadataAndKeepsReservationTransactionOpen(): void
    {
        $pdo = $this->db->pdo();
        self::assertSame('mysql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $definition = TenantDataRegistryFactory::draftV1()->definition(
            'table:accounting_periods',
        );
        self::assertInstanceOf(TenantDataDefinition::class, $definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $metadata = (new CompanyBackupTableSchemaReader())->readImportMetadata(
            $pdo,
            $projection,
        );
        self::assertNotNull($metadata->autoIncrement);
        self::assertSame('id', $metadata->autoIncrement->column);

        self::assertNotFalse($pdo->exec(
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
        ));
        self::assertTrue($pdo->beginTransaction());
        $reservation = CompanyBackupSqlPrimaryKeyReservation::reserve(
            $pdo,
            $projection,
            $metadata->autoIncrement,
            2,
        );
        $first = $reservation->next();
        $second = $reservation->next();
        self::assertSame($first + 1, $second);
        $reservation->finish();
        self::assertTrue($pdo->inTransaction());
    }
}
