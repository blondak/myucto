<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Service\Backup\Company\CompanyBackupPurchaseInvoicesProjection as Projection;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Read-only kontrola projekce proti schématu izolované testovací MariaDB. */
#[Group('integration')]
final class CompanyBackupPurchaseInvoicesProjectionSchemaTest extends TestCase
{
    public function testProjectionCoversEveryStoredColumnAndPhysicalReference(): void
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
            'root',
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $columns = $pdo->query(
            "SELECT COLUMN_NAME, IS_NULLABLE, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_invoices'
             ORDER BY ORDINAL_POSITION",
        );
        self::assertNotFalse($columns);
        $runtimeColumns = $columns->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(81, $runtimeColumns);
        $stored = [];
        $generated = [];
        $nullable = [];
        foreach ($runtimeColumns as $column) {
            $name = $column['COLUMN_NAME'];
            $nullable[$name] = $column['IS_NULLABLE'] === 'YES';
            if (str_contains(strtoupper((string) $column['EXTRA']), 'GENERATED')) {
                $generated[] = $name;
            } else {
                $stored[] = $name;
            }
        }
        self::assertSame(Projection::dataColumns(), $stored);
        self::assertSame(Projection::generatedColumns(), $generated);

        $keys = $pdo->query(
            "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_invoices'
               AND REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY COLUMN_NAME",
        );
        self::assertNotFalse($keys);
        $physical = [];
        foreach ($keys->fetchAll(PDO::FETCH_ASSOC) as $key) {
            $physical[$key['COLUMN_NAME']] =
                'table:' . $key['REFERENCED_TABLE_NAME'] . ':' . $key['REFERENCED_COLUMN_NAME'];
        }
        self::assertCount(10, $physical);

        $declared = [];
        foreach (Projection::references() as $reference) {
            $column = $reference['columns'][0];
            self::assertArrayNotHasKey($column, $declared);
            $declared[$column] = $reference;
            self::assertArrayHasKey($column, $nullable);
            self::assertSame(
                $nullable[$column] ? [$column] : [],
                $reference['nullable_columns'],
                $column,
            );
        }
        foreach ($physical as $column => $target) {
            self::assertArrayHasKey($column, $declared);
            self::assertSame(
                $target,
                $declared[$column]['target'] . ':' . $declared[$column]['target_columns'][0],
                $column,
            );
            self::assertSame(
                CompanyBackupReferenceConstraint::Required->value,
                $declared[$column]['constraint'],
                $column,
            );
        }
        $soft = array_values(array_diff(array_keys($declared), array_keys($physical)));
        sort($soft);
        self::assertSame(['booked_by', 'expense_category_id'], $soft);
        self::assertSame(
            CompanyBackupReferenceMapping::Actor->value,
            $declared['booked_by']['mapping'],
        );
        self::assertSame(
            'table:expense_categories',
            $declared['expense_category_id']['target'],
        );
        foreach ($soft as $column) {
            self::assertSame(
                CompanyBackupReferenceConstraint::Optional->value,
                $declared[$column]['constraint'],
            );
        }
    }
}
