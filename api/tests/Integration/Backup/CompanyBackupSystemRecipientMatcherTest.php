<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupSystemRecipientMatcher as Matcher;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class CompanyBackupSystemRecipientMatcherTest extends TestCase
{
    private ?Connection $db = null;

    protected function setUp(): void
    {
        $connection = Bootstrap::buildApp()->getContainer()?->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new \RuntimeException('Test vyžaduje MariaDB spojení.');
        }
        $this->db = $connection;
        $connection->pdo()->exec('CREATE TEMPORARY TABLE submission_recipients (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT UNSIGNED NULL,
            code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            name VARCHAR(190) NOT NULL,
            business_id VARCHAR(8) NULL,
            address VARCHAR(500) NULL,
            kind ENUM(\'tax_office\',\'cssz\',\'health_insurer\',\'other\') NOT NULL,
            isds_box_id CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL,
            source_note VARCHAR(500) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_submission_recipients_code (supplier_id, code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $database = $this->db->pdo();
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            $database->exec('DROP TEMPORARY TABLE IF EXISTS submission_recipients');
            $this->db->close();
        }
    }

    public function testMatchesOnlyExistingGlobalRowAndLeavesCatalogUntouched(): void
    {
        $database = $this->database();
        $this->insert(11, null, 'zp_test', 'health_insurer', 'abc1234', '12345678', 'Jiný název', 0);
        $this->insert(12, 90, 'zp_test', 'other', 'xyz5678', null, 'Vlastní stín', 1);
        $before = $this->rows();

        $database->beginTransaction();
        self::assertSame(11, Matcher::match($database, self::source(), true));
        $database->rollBack();

        self::assertSame($before, $this->rows());
    }

    public function testMissingGlobalRowCannotUseTenantShadow(): void
    {
        $this->insert(12, 90, 'zp_test', 'health_insurer', 'abc1234', '12345678');
        $this->expectErrorCode('system_recipient_target_missing', 'code');
    }

    public function testDuplicateGlobalCodeIsAmbiguousEvenWhenOneIdentityMatches(): void
    {
        $this->insert(11, null, 'zp_test', 'health_insurer', 'abc1234', '12345678');
        $this->insert(13, null, 'zp_test', 'other', null, null);
        $this->expectErrorCode('system_recipient_target_ambiguous', 'code');
    }

    public function testRejectsDifferentKind(): void
    {
        $this->insert(11, null, 'zp_test', 'other', 'abc1234', '12345678');
        $this->expectErrorCode('system_recipient_target_mismatch', 'kind');
    }

    public function testRejectsDifferentBox(): void
    {
        $this->insert(11, null, 'zp_test', 'health_insurer', 'xyz5678', '12345678');
        $this->expectErrorCode('system_recipient_target_mismatch', 'isds_box_id');
    }

    public function testRejectsDifferentBusinessId(): void
    {
        $this->insert(11, null, 'zp_test', 'health_insurer', 'abc1234', '87654321');
        $this->expectErrorCode('system_recipient_target_mismatch', 'business_id');
    }

    public function testNullIdentityFieldsAreComparedWithoutCoalescing(): void
    {
        $source = self::source();
        $source['isds_box_id'] = null;
        $source['business_id'] = null;
        $this->insert(11, null, 'zp_test', 'health_insurer', null, null);
        self::assertSame(11, Matcher::match($this->database(), $source));

        $source['business_id'] = '12345678';
        try {
            Matcher::match($this->database(), $source);
            self::fail('NULL a vyplněné IČ nesmí být zaměněny.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('system_recipient_target_mismatch', $e->errorCode);
            self::assertSame('business_id', $e->column);
        }
    }

    public function testCodeComparisonUsesAsciiBinAndDoesNotNormalizeCase(): void
    {
        $this->insert(11, null, 'ZP_TEST', 'health_insurer', 'abc1234', '12345678');
        $this->expectErrorCode('system_recipient_target_missing', 'code');
    }

    public function testLockFailsClosedOutsideTransaction(): void
    {
        $this->insert(11, null, 'zp_test', 'health_insurer', 'abc1234', '12345678');
        $this->expectErrorCode('system_recipient_lock_unavailable', null, true);
    }

    private function expectErrorCode(string $errorCode, ?string $column, bool $lock = false): void
    {
        $before = $this->rows();
        try {
            Matcher::match($this->database(), self::source(), $lock);
            self::fail('Neplatný cílový systémový příjemce musí být odmítnut.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame($errorCode, $e->errorCode);
            self::assertSame(Matcher::REGISTRY_KEY, $e->registryKey);
            self::assertSame($column, $e->column);
            self::assertStringNotContainsString('abc1234', $e->getMessage());
            self::assertStringNotContainsString('12345678', $e->getMessage());
            self::assertStringNotContainsString('90', $e->getMessage());
        }
        self::assertSame($before, $this->rows());
    }

    private function insert(int $id, ?int $supplierId, string $code, string $kind,
        ?string $box, ?string $businessId, string $name = 'Cílová instituce', int $active = 1): void
    {
        $statement = $this->database()->prepare(
            'INSERT INTO submission_recipients
                (id, supplier_id, code, name, business_id, address, kind,
                 isds_box_id, source_note, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([$id, $supplierId, $code, $name, $businessId,
            'Jiná adresa', $kind, $box, 'Jiný zdroj', $active]);
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        $query = $this->database()->query('SELECT * FROM submission_recipients ORDER BY id');
        self::assertNotFalse($query);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function database(): PDO
    {
        return $this->db?->pdo() ?? throw new \LogicException('Chybí testovací DB.');
    }

    /** @return array<string,mixed> */
    private static function source(): array
    {
        return [
            'id' => 42, 'supplier_id' => null, 'code' => 'zp_test',
            'name' => 'Zdrojová instituce', 'address' => 'Zdrojová adresa',
            'kind' => 'health_insurer', 'isds_box_id' => 'abc1234',
            'business_id' => '12345678', 'source_note' => 'Jiný auditní zdroj',
            'is_active' => 1,
        ];
    }
}
