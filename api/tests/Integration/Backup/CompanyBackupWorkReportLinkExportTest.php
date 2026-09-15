<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlProtectedSecretSource;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlRowSource;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinksProjection;
use MyInvoice\Service\Backup\Registry\CompanyBackupWorkReportLinksDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** SQL export nad izolovanou DB: vlastní syntetický odkaz v transakci + rollback. */
#[Group('integration')]
final class CompanyBackupWorkReportLinkExportTest extends TestCase
{
    private static function isolatedPdo(): PDO
    {
        if (getenv('MYINVOICE_DB_HOST') !== '127.0.0.1'
            || getenv('MYINVOICE_DB_PORT') !== '33070'
            || getenv('MYINVOICE_DB_NAME') !== 'myucto_codex_test'
            || getenv('MYINVOICE_DB_USER') !== 'root'
            || !is_string(getenv('MYINVOICE_DB_PASS'))
        ) {
            self::markTestSkipped('Izolovaná testovací MariaDB není nakonfigurována.');
        }
        $password = getenv('MYINVOICE_DB_PASS');
        self::assertIsString($password);
        return new PDO(
            'mysql:host=127.0.0.1;port=33070;dbname=myucto_codex_test;charset=utf8mb4',
            'root', $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    public function testActualSqlRowSourceExportsHistoryWithoutProtectedToken(): void
    {
        $pdo = self::isolatedPdo();
        $supplierId = random_int(3_000_000_000, 4_000_000_000);
        self::createSyntheticTables($pdo, $supplierId);
        $pdo->beginTransaction();
        try {
            self::insertLink($pdo, $supplierId, 11, 21);
            $rows = array_values(iterator_to_array(
                (new CompanyBackupSqlRowSource())->rows($pdo, $supplierId, self::definition()),
            ));
            self::assertCount(1, $rows);
            $row = $rows[0];
            self::assertSame(CompanyBackupWorkReportLinksProjection::dataColumns(), array_keys($row));
            self::assertArrayNotHasKey('token', $row);
            self::assertSame('project', $row['scope']);
            self::assertSame(11, $row['client_id']);
            self::assertSame(21, $row['project_id']);
            self::assertSame('2025-12-31 23:59:00', $row['created_at']);
            self::assertSame('2026-01-01 00:01:00', $row['last_sent_at']);
            self::assertSame('2026-01-02 01:02:03', $row['last_viewed_at']);
            self::assertSame('2026-01-03 04:05:06', $row['revoked_at']);
        } finally {
            $pdo->rollBack();
        }
    }

    #[DataProvider('invalidRelations')]
    public function testActualSqlRowSourceRejectsMissingAndCrossTenantRelations(
        int $clientId,
        int $projectId,
    ): void
    {
        $pdo = self::isolatedPdo();
        $supplierId = random_int(3_000_000_000, 4_000_000_000);
        self::createSyntheticTables($pdo, $supplierId);
        $pdo->beginTransaction();
        try {
            self::insertLink($pdo, $supplierId, $clientId, $projectId);
            try {
                iterator_to_array((new CompanyBackupSqlRowSource())->rows($pdo, $supplierId, self::definition()));
                self::fail('Neplatná vazba odkazu nesmí být exportována.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame('work_report_link_relation_mismatch', $e->errorCode);
                self::assertSame('table:work_report_links', $e->registryKey);
                self::assertSame('scope', $e->column);
            }
        } finally {
            $pdo->rollBack();
        }
    }

    /** @return iterable<string,array{int,int}> */
    public static function invalidRelations(): iterable
    {
        yield 'missing client' => [99, 21];
        yield 'cross tenant client and project' => [31, 32];
        yield 'other client project' => [11, 22];
        yield 'cross tenant project' => [11, 32];
    }

    #[DataProvider('invalidTokens')]
    public function testActualProtectedSqlCollectorRejectsMissingOrMalformedRequiredToken(
        string $invalidToken,
    ): void {
        $pdo = self::isolatedPdo();
        $pdo->beginTransaction();
        try {
            $supplierId = random_int(3_000_000_000, 4_000_000_000);
            self::insertLink($pdo, $supplierId, 11, 21, $invalidToken);
            $definition = self::definition();
            $registry = TenantDataRegistrySnapshot::fromRegistry(
                new TenantDataRegistry(1, [$definition], [TenantDataRegistry::COMPANY_BACKUP_PROFILE]),
                TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            );
            $config = new Config(['app' => [
                'secret_encryption_key' => base64_encode(str_repeat('s', 32)),
            ]]);
            $collector = new CompanyBackupSecretEnvelopeCollector(
                new CompanyBackupSqlProtectedSecretSource(new SecretEncryption($config)),
            );
            try {
                $collector->collect($pdo, $registry, $supplierId,
                    'synthetic-backup-password-42',
                    '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1');
                self::fail('Neplatný povinný token nesmí tiše zmizet ze zálohy.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('secret_source_value_invalid', $e->errorCode);
                self::assertSame('table:work_report_links', $e->registryKey);
                self::assertSame('token', $e->column);
                if ($invalidToken !== '') {
                    self::assertStringNotContainsString($invalidToken, $e->getMessage());
                }
            }
        } finally {
            $pdo->rollBack();
        }
    }

    /** @return iterable<string,array{string}> */
    public static function invalidTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed 48 bytes' => [str_repeat('G', 48)];
    }

    private static function createSyntheticTables(PDO $pdo, int $supplierId): void
    {
        $pdo->exec('CREATE TEMPORARY TABLE clients (
            id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL
        )');
        $pdo->exec('CREATE TEMPORARY TABLE projects (
            id BIGINT UNSIGNED PRIMARY KEY, client_id BIGINT UNSIGNED NOT NULL
        )');
        $clients = $pdo->prepare('INSERT INTO clients (id, supplier_id) VALUES (?, ?)');
        $clients->execute([11, $supplierId]);
        $clients->execute([12, $supplierId]);
        $clients->execute([31, 3]);
        $pdo->exec('INSERT INTO projects (id, client_id) VALUES (21, 11), (22, 12), (32, 31)');
    }

    private static function insertLink(
        PDO $pdo,
        int $supplierId,
        int $clientId,
        int $projectId,
        ?string $token = null,
    ): void
    {
        $insert = $pdo->prepare('INSERT INTO work_report_links
            (id, supplier_id, scope, client_id, project_id, token, created_by_user_id,
             created_at, last_sent_at, last_viewed_at, revoked_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([
            random_int(2_000_000_000_000, 3_000_000_000_000), $supplierId,
            'project', $clientId, $projectId, $token ?? bin2hex(random_bytes(24)), null,
            '2025-12-31 23:59:00', '2026-01-01 00:01:00',
            '2026-01-02 01:02:03', '2026-01-03 04:05:06',
        ]);
    }

    private static function definition(): TenantDataDefinition
    {
        return CompanyBackupWorkReportLinksDefinition::definition();
    }
}
