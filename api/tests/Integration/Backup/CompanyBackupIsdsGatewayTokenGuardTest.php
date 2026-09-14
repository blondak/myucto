<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\Company\CompanyBackupIsdsGatewayTokenGuard as Guard;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class CompanyBackupIsdsGatewayTokenGuardTest extends TestCase
{
    private ?Connection $db = null;

    protected function setUp(): void
    {
        $connection = Bootstrap::buildApp()->getContainer()?->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new \RuntimeException('Test vyžaduje databázové spojení.');
        }
        $this->db = $connection;
        // Temp tabulka stíní produkční tabulku pouze na tomto spojení.
        $connection->pdo()->exec('CREATE TEMPORARY TABLE isds_gateway_sessions (
            id BIGINT UNSIGNED PRIMARY KEY,
            supplier_id INT UNSIGNED NOT NULL,
            app_token VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            CONSTRAINT chk_synthetic_app_token CHECK (app_token REGEXP \'^[0-9]{10,20}$\')
        ) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->pdo()->exec('DROP TEMPORARY TABLE IF EXISTS isds_gateway_sessions');
            $this->db->close();
        }
    }

    public function testLeadingZeroesStayDistinctUnderNativeUniqueKey(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO isds_gateway_sessions VALUES (11, 90, '0000000001')");
        Guard::assertAvailable($pdo, Guard::REGISTRY_KEY, ['app_token' => '00000000000000000001']);
        $pdo->exec("INSERT INTO isds_gateway_sessions VALUES (12, 91, '00000000000000000001')");

        $statement = $pdo->query('SELECT app_token FROM isds_gateway_sessions ORDER BY id');
        self::assertNotFalse($statement);
        self::assertSame(['0000000001', '00000000000000000001'],
            $statement->fetchAll(PDO::FETCH_COLUMN));

        try {
            Guard::assertAvailable($pdo, Guard::REGISTRY_KEY,
                ['supplier_id' => 7, 'app_token' => '00000000000000000001']);
            self::fail('Kolize dvacetimístného tokenu mezi tenanty musí být odmítnuta.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame(Guard::COLLISION, $e->errorCode);
            self::assertStringNotContainsString('00000000000000000001', $e->getMessage());
            self::assertStringNotContainsString('91', $e->getMessage());
        }
    }

    public function testDatabaseUniqueKeyRemainsFinalRaceDefense(): void
    {
        $pdo = $this->database();
        $row = ['app_token' => '12345678901234567890'];
        Guard::assertAvailable($pdo, Guard::REGISTRY_KEY, $row);
        $pdo->exec("INSERT INTO isds_gateway_sessions VALUES (13, 92, '12345678901234567890')");

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO isds_gateway_sessions VALUES (14, 93, '12345678901234567890')");
    }

    private function database(): PDO
    {
        return $this->db?->pdo() ?? throw new \LogicException('Chybí testovací DB.');
    }
}
