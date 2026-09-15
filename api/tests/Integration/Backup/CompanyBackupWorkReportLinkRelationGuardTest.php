<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkRelationGuard;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class CompanyBackupWorkReportLinkRelationGuardTest extends TestCase
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

    public function testClientAndProjectMustBelongToTheRestoredSupplierAndEachOther(): void
    {
        $pdo = self::isolatedPdo();
        $this->createSyntheticTables($pdo);
        $this->assertRelations($pdo);
    }

    public function testSamePreparedRelationsOnLocalSyntheticDatabase(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->createSyntheticTables($pdo);
        $this->assertRelations($pdo);
    }

    private function createSyntheticTables(PDO $pdo): void
    {
        // Jen relační sloupce ze živého schématu; session-local tabulky
        // záměrně nemají FK, aby šly ověřit i chybějící vazby.
        $pdo->exec('CREATE TEMPORARY TABLE clients (
            id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL,
            archived_at TIMESTAMP NULL
        )');
        $pdo->exec('CREATE TEMPORARY TABLE projects (
            id BIGINT UNSIGNED PRIMARY KEY, client_id BIGINT UNSIGNED NOT NULL,
            archived_at TIMESTAMP NULL
        )');
        $pdo->exec("INSERT INTO clients (id, supplier_id, archived_at) VALUES
            (11, 1, '2026-01-01 00:00:00'), (12, 1, NULL), (31, 3, NULL)");
        $pdo->exec("INSERT INTO projects (id, client_id, archived_at) VALUES
            (21, 11, '2026-01-02 00:00:00'), (22, 12, NULL), (32, 31, NULL)");
    }

    private function assertRelations(PDO $pdo): void
    {
        $client = ['supplier_id' => 1, 'client_id' => 11, 'project_id' => null, 'scope' => 'client'];
        CompanyBackupWorkReportLinkRelationGuard::assertValid($pdo, $client);
        $project = array_replace($client, ['scope' => 'project', 'project_id' => 21]);
        CompanyBackupWorkReportLinkRelationGuard::assertValid($pdo, $project);

        foreach ([
            array_replace($client, ['client_id' => 31]), // jiná firma
            array_replace($client, ['client_id' => 99]), // neexistující klient
            array_replace($project, ['project_id' => 22]), // jiný klient téže firmy
            array_replace($project, ['project_id' => 32]), // klient jiné firmy
            array_replace($project, ['project_id' => 99]), // neexistující zakázka
            array_replace($project, ['client_id' => 99]), // neexistující klient
        ] as $invalid) {
            try {
                CompanyBackupWorkReportLinkRelationGuard::assertValid($pdo, $invalid);
                self::fail('Cizí nebo chybějící vazba musí zastavit obnovu.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame('work_report_link_relation_mismatch', $e->errorCode);
                self::assertSame('work_report_link_relation_mismatch: table:work_report_links.scope', $e->getMessage());
                self::assertNull($e->getPrevious());
            }
        }
    }
}
