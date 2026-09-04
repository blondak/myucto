<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentity;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceKey;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlTargetIdentityMap;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Živá MariaDB kontrola dočasné InnoDB mapy nových ID. */
#[Group('integration')]
final class CompanyBackupTargetIdentityMapTest extends TestCase
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

    public function testMapsAliasesWithoutCommittingCallerTransaction(): void
    {
        $pdo = $this->db->pdo();
        self::assertSame('mysql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $pdo->beginTransaction();
        $identity = new CompanyBackupSourceIdentity(
            TenantDataPolicy::TenantOwned,
            CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['id' => 31],
            ),
            null,
            CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['supplier_id' => 7, 'code' => 'EMP-1'],
            ),
            [],
        );
        $target = CompanyBackupSourceKey::fromValues(
            'table:synthetic_records',
            ['id' => 401],
        );
        $map = new CompanyBackupSqlTargetIdentityMap(
            $pdo,
            new CompanyBackupArchiveLimits(
                maxSourceIdentities: 10,
                maxSourceIndexEntries: 20,
                maxSourceIndexBytes: 65_536,
            ),
        );
        try {
            $map->add($identity, $target);
            $naturalKey = $identity->naturalKey;
            if ($naturalKey === null) {
                throw new \LogicException('Syntetická identita nemá natural key.');
            }
            $mapped = $map->find($naturalKey);
            self::assertNotNull($mapped);
            self::assertTrue($target->equals($mapped));
            $map->seal();
            self::assertTrue($pdo->inTransaction());
        } finally {
            $map->close();
        }

        self::assertTrue($pdo->inTransaction());
    }
}
