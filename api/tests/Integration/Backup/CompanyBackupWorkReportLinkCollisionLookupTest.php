<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkCollisionLookup;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class CompanyBackupWorkReportLinkCollisionLookupTest extends TestCase
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

    public function testAllTargetLinksIncludingRevokedAndOtherSuppliersBlockOriginalToken(): void
    {
        $pdo = self::isolatedPdo();
        // MariaDB odmítá LIKE se stejným jménem (1066); mezikopie zachová živý index.
        $pdo->exec('CREATE TEMPORARY TABLE work_report_links_template LIKE work_report_links');
        $pdo->exec('CREATE TEMPORARY TABLE work_report_links LIKE work_report_links_template');
        $pdo->exec('DROP TEMPORARY TABLE work_report_links_template');
        $pdo->beginTransaction();
        try {
            $active = str_repeat('a', 48);
            $revoked = str_repeat('b', 48);
            $otherSupplier = str_repeat('c', 48);
            $caseVariant = str_repeat('d', 48);
            $insert = $pdo->prepare(
                'INSERT INTO work_report_links
                 (supplier_id, scope, client_id, project_id, token, revoked_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
            );
            $insert->execute([1, 'client', 11, null, $active, null]);
            $insert->execute([1, 'client', 11, null, $revoked, '2026-01-01 00:00:00']);
            $insert->execute([2, 'project', 12, 21, $otherSupplier, null]);
            $insert->execute([1, 'client', 11, null, strtoupper($caseVariant), null]);

            foreach ([$active, $revoked, $otherSupplier, $caseVariant] as $token) {
                self::assertTrue(CompanyBackupWorkReportLinkCollisionLookup::hasCollision($pdo, $token));
            }
            $free = str_repeat('e', 48);
            self::assertFalse(CompanyBackupWorkReportLinkCollisionLookup::hasCollision($pdo, $free));

            // Preflight je pouze snapshot: mezi kontrolou a INSERT může vzniknout kolize.
            $insert->execute([3, 'client', 31, null, $free, null]);
            try {
                $insert->execute([4, 'client', 41, null, $free, null]);
                self::fail('Globální UNIQUE index musí odmítnout závodní kolizi.');
            } catch (PDOException $e) {
                self::assertSame('23000', $e->getCode());
                self::assertSame(1062, $e->errorInfo[1] ?? null);
                self::assertTrue(CompanyBackupWorkReportLinkCollisionLookup::hasCollision($pdo, $free));
            }
            $target = $pdo->prepare('SELECT supplier_id, revoked_at FROM work_report_links WHERE token = ?');
            $target->execute([$revoked]);
            self::assertSame(['supplier_id' => 1, 'revoked_at' => '2026-01-01 00:00:00'],
                $target->fetch(PDO::FETCH_ASSOC));
        } finally {
            $pdo->rollBack();
        }
    }

    public function testInvalidSourceTokenIsRejectedBeforeDatabaseQuery(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            CompanyBackupWorkReportLinkCollisionLookup::hasCollision($pdo, str_repeat('A', 48));
            self::fail('Neplatný zdrojový token nesmí projít.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('work_report_link_token_invalid', $e->errorCode);
            self::assertSame('table:work_report_links', $e->registryKey);
            self::assertSame('token', $e->column);
            self::assertStringNotContainsString(str_repeat('A', 48), $e->getMessage());
        }
    }

    public function testDatabaseFailureDoesNotExposeTokenOrDriverMessage(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $token = str_repeat('f', 48);
        try {
            CompanyBackupWorkReportLinkCollisionLookup::hasCollision($pdo, $token);
            self::fail('Chybějící tabulka musí zastavit preflight.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('work_report_link_collision_lookup_failed', $e->errorCode);
            self::assertSame('table:work_report_links', $e->registryKey);
            self::assertSame('token', $e->column);
            self::assertStringNotContainsString($token, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testSilentPdoFailureFailsClosed(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        try {
            CompanyBackupWorkReportLinkCollisionLookup::hasCollision($pdo, str_repeat('f', 48));
            self::fail('Tichá databázová chyba nesmí znamenat volný token.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('work_report_link_collision_lookup_failed', $e->errorCode);
            self::assertNull($e->getPrevious());
        }
    }
}
