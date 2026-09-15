<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinksProjection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Read-only metadata kontrola izolované MariaDB; nečte žádný business řádek. */
#[Group('integration')]
final class CompanyBackupWorkReportLinksProjectionSchemaTest extends TestCase
{
    public function testStoredColumnsLogicalReferencesAndGlobalTokenUniqueIndex(): void
    {
        if (getenv('MYINVOICE_DB_HOST') !== '127.0.0.1'
            || getenv('MYINVOICE_DB_PORT') !== '33070'
            || getenv('MYINVOICE_DB_NAME') !== 'myucto_codex_test'
            || getenv('MYINVOICE_DB_USER') !== 'root'
            || !is_string(getenv('MYINVOICE_DB_PASS'))
        ) {
            $this->markTestSkipped('Izolovaná testovací MariaDB není nakonfigurována.');
        }
        $password = getenv('MYINVOICE_DB_PASS');
        self::assertIsString($password);
        $pdo = new PDO(
            'mysql:host=127.0.0.1;port=33070;dbname=myucto_codex_test;charset=utf8mb4',
            'root', $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $columns = $pdo->query(
            "SELECT COLUMN_NAME, IS_NULLABLE, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_report_links'
             ORDER BY ORDINAL_POSITION",
        );
        self::assertNotFalse($columns);
        $runtimeColumns = $columns->fetchAll(PDO::FETCH_ASSOC);
        $storedColumns = CompanyBackupWorkReportLinksProjection::dataColumns();
        array_splice($storedColumns, 5, 0, array_keys(CompanyBackupWorkReportLinksProjection::secrets()));
        self::assertSame(
            $storedColumns,
            array_column($runtimeColumns, 'COLUMN_NAME'),
        );
        self::assertNotContains('token', CompanyBackupWorkReportLinksProjection::dataColumns());
        self::assertSame('NO', $runtimeColumns[5]['IS_NULLABLE']);
        self::assertSame([], array_keys(array_filter(
            $runtimeColumns,
            static fn (array $column): bool =>
                str_contains(strtoupper((string) $column['EXTRA']), 'GENERATED'),
        )));
        $nullable = [];
        foreach ($runtimeColumns as $column) {
            if ($column['IS_NULLABLE'] === 'YES') {
                $nullable[] = $column['COLUMN_NAME'];
            }
        }
        self::assertSame(
            ['project_id', 'created_by_user_id', 'last_sent_at', 'last_viewed_at', 'revoked_at'],
            $nullable,
        );
        foreach (CompanyBackupWorkReportLinksProjection::references() as $reference) {
            $column = $reference['columns'][0];
            self::assertContains($column, CompanyBackupWorkReportLinksProjection::dataColumns());
            self::assertSame(
                in_array($column, $nullable, true) ? [$column] : [],
                $reference['nullable_columns'], $column,
            );
            self::assertSame(CompanyBackupReferenceConstraint::Optional->value,
                $reference['constraint'], $column);
        }

        $foreignKeys = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_report_links'
               AND REFERENCED_TABLE_NAME IS NOT NULL",
        );
        self::assertNotFalse($foreignKeys);
        self::assertSame([], $foreignKeys->fetchAll(PDO::FETCH_COLUMN));

        $indexes = $pdo->query(
            "SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_report_links'
               AND INDEX_NAME = 'uq_wrl_token'
             ORDER BY SEQ_IN_INDEX",
        );
        self::assertNotFalse($indexes);
        self::assertSame([['INDEX_NAME' => 'uq_wrl_token', 'COLUMN_NAME' => 'token',
            'NON_UNIQUE' => 0, 'SEQ_IN_INDEX' => 1]], $indexes->fetchAll(PDO::FETCH_ASSOC));
    }
}
