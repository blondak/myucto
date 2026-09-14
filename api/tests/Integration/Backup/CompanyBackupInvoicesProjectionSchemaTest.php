<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Service\Backup\Company\CompanyBackupInvoicesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupInvoicesProjectionSchemaTest extends TestCase
{
    public function testProjectionCoversEveryStoredInvoiceColumnAndPhysicalReference(): void
    {
        if (getenv('MYINVOICE_DB_NAME') !== 'myucto_codex_test'
            || getenv('MYINVOICE_DB_HOST') !== '127.0.0.1'
            || getenv('MYINVOICE_DB_PORT') !== '33070') {
            self::markTestSkipped('Requires isolated myucto_codex_test database.');
        }
        $pdo = new PDO(
            'mysql:host=127.0.0.1;port=33070;dbname=myucto_codex_test;charset=utf8mb4',
            (string) getenv('MYINVOICE_DB_USER'),
            (string) getenv('MYINVOICE_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $columnsQuery = $pdo->query("SELECT COLUMN_NAME, EXTRA, IS_NULLABLE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' ORDER BY ORDINAL_POSITION");
        self::assertNotFalse($columnsQuery);
        $columns = $columnsQuery->fetchAll(PDO::FETCH_ASSOC);
        self::assertNotEmpty($columns);
        $stored = [];
        $generated = [];
        $nullable = [];
        foreach ($columns as $column) {
            $nullable[$column['COLUMN_NAME']] = $column['IS_NULLABLE'] === 'YES';
            if (str_contains((string) $column['EXTRA'], 'GENERATED')) {
                $generated[] = $column['COLUMN_NAME'];
            } else {
                $stored[] = $column['COLUMN_NAME'];
            }
        }
        $projected = array_merge(CompanyBackupInvoicesProjection::dataColumns(), ['approval_token', 'public_token']);
        sort($projected);
        sort($stored);
        self::assertCount(77, $columns);
        self::assertCount(73, CompanyBackupInvoicesProjection::dataColumns());
        self::assertSame($stored, $projected);
        sort($generated);
        $declaredGenerated = CompanyBackupInvoicesProjection::generatedColumns();
        sort($declaredGenerated);
        self::assertSame($generated, $declaredGenerated);

        $keysQuery = $pdo->query("SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'
              AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY COLUMN_NAME");
        self::assertNotFalse($keysQuery);
        $physicalRows = $keysQuery->fetchAll(PDO::FETCH_ASSOC);
        $physical = [];
        foreach ($physicalRows as $key) {
            $physical[$key['COLUMN_NAME']] = $key;
        }
        $references = [];
        foreach (CompanyBackupInvoicesProjection::references() as $reference) {
            $references[$reference['columns'][0]] = $reference;
        }
        self::assertCount(9, $physical);
        foreach ($physical as $column => $key) {
            self::assertArrayHasKey($column, $references, 'Physical FK ' . $column);
            $reference = $references[$column];
            self::assertSame('table:' . $key['REFERENCED_TABLE_NAME'], $reference['target']);
            self::assertSame([$key['REFERENCED_COLUMN_NAME']], $reference['target_columns']);
            self::assertSame(CompanyBackupReferenceConstraint::Required->value, $reference['constraint']);
            self::assertSame($nullable[$column] ? [$column] : [], $reference['nullable_columns']);
        }
        $soft = array_values(array_diff(array_keys($references), array_keys($physical)));
        sort($soft);
        self::assertSame(['booked_by', 'revenue_category_id'], $soft);
    }
}
