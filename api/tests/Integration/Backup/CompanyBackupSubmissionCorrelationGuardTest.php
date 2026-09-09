<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupSubmissionCorrelationGuard as Guard;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class CompanyBackupSubmissionCorrelationGuardTest extends TestCase
{
    private ?Connection $db = null;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $connection = $container?->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new \RuntimeException('Test vyžaduje databázové spojení.');
        }
        $this->db = $connection;
        // Dočasná tabulka stíní produkční jméno jen na tomto spojení.
        $connection->pdo()->exec('CREATE TEMPORARY TABLE submission_outbox (
            id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL,
            correlation_reference VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE
        ) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->pdo()->exec('DROP TEMPORARY TABLE IF EXISTS submission_outbox');
            $this->db->close();
        }
    }

    public function testCollisionAcrossTenantsRejectsWithoutChangingOrDisclosingTarget(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO submission_outbox VALUES (11, 90, 'SYNTHETIC-ISDS-001')");
        $statement = $pdo->query('SELECT * FROM submission_outbox');
        self::assertNotFalse($statement);
        $before = $statement->fetchAll(PDO::FETCH_ASSOC);
        $source = ['supplier_id' => 7, 'correlation_reference' => 'SYNTHETIC-ISDS-001'];
        try {
            Guard::assertAvailable($pdo, Guard::REGISTRY_KEY, $source);
            self::fail('Globální kolize musí obnovu zastavit.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame(Guard::COLLISION, $e->errorCode);
            self::assertSame('submission_correlation_collision: table:submission_outbox.correlation_reference', $e->getMessage());
        }
        $statement = $pdo->query('SELECT * FROM submission_outbox');
        self::assertNotFalse($statement);
        self::assertSame($before, $statement->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame('SYNTHETIC-ISDS-001', $source['correlation_reference']);
    }

    public function testFreshCheckDetectsCollisionCreatedAfterPreflight(): void
    {
        $pdo = $this->database();
        $source = ['correlation_reference' => 'SYNTHETIC-ISDS-002'];
        Guard::assertAvailable($pdo, Guard::REGISTRY_KEY, $source);
        $pdo->exec("INSERT INTO submission_outbox VALUES (12, 91, 'SYNTHETIC-ISDS-002')");
        $this->expectException(CompanyBackupPreflightException::class);
        $this->expectExceptionMessage(Guard::COLLISION);
        Guard::assertAvailable($pdo, Guard::REGISTRY_KEY, $source);
    }

    public function testUnrelatedRowsAndDistinctCaseSensitiveReferencesAreAllowed(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO submission_outbox VALUES (13, 92, 'SYNTHETIC-ISDS-003')");
        Guard::assertAvailable($pdo, 'table:documents', []);
        Guard::assertAvailable($pdo, Guard::REGISTRY_KEY, ['correlation_reference' => 'synthetic-isds-003']);
        Guard::assertAvailable($pdo, Guard::REGISTRY_KEY, ['correlation_reference' => 'SYNTHETIC-ISDS-004']);
        $statement = $pdo->query('SELECT COUNT(*) FROM submission_outbox');
        self::assertNotFalse($statement);
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    public function testMalformedReferenceIsRejected(): void
    {
        $this->expectException(CompanyBackupPreflightException::class);
        $this->expectExceptionMessage('submission_correlation_invalid');
        Guard::assertAvailable($this->database(), Guard::REGISTRY_KEY, ['correlation_reference' => []]);
    }

    private function database(): PDO
    {
        return $this->db?->pdo() ?? throw new \LogicException('Chybí testovací DB.');
    }
}
