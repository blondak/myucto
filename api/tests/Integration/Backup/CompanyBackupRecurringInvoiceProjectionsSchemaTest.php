<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Service\Backup\Company\CompanyBackupRecurringInvoiceTemplateItemsProjection as Items;
use MyInvoice\Service\Backup\Company\CompanyBackupRecurringInvoiceTemplatesProjection as Templates;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupRecurringInvoiceProjectionsSchemaTest extends TestCase
{
    public function testBothProjectionsCoverEveryPhysicalColumnAndForeignKey(): void
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
        $this->compareTable($pdo, 'recurring_invoice_templates', Templates::dataColumns(), Templates::references(), ['revenue_category_id']);
        $this->compareTable($pdo, 'recurring_invoice_template_items', Items::dataColumns(), Items::references(), []);
    }

    /**
     * @param list<string> $dataColumns
     * @param list<array<string,mixed>> $references
     * @param list<string> $softReferences
     */
    private function compareTable(PDO $pdo, string $table, array $dataColumns, array $references, array $softReferences): void
    {
        $statement = $pdo->prepare('SELECT COLUMN_NAME, EXTRA, IS_NULLABLE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $statement->execute([$table]);
        $columns = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertNotEmpty($columns, $table);
        self::assertSame(array_column($columns, 'COLUMN_NAME'), $dataColumns, $table);
        foreach ($columns as $column) {
            self::assertStringNotContainsString('GENERATED', (string) $column['EXTRA'], $table . '.' . $column['COLUMN_NAME']);
        }
        $nullable = [];
        foreach ($columns as $column) {
            $nullable[$column['COLUMN_NAME']] = $column['IS_NULLABLE'] === 'YES';
        }

        $statement = $pdo->prepare('SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY COLUMN_NAME');
        $statement->execute([$table]);
        $keys = $statement->fetchAll(PDO::FETCH_ASSOC);
        $declared = [];
        foreach ($references as $reference) {
            $declared[$reference['columns'][0]] = $reference;
        }
        foreach ($keys as $key) {
            $column = $key['COLUMN_NAME'];
            self::assertArrayHasKey($column, $declared);
            self::assertSame('table:' . $key['REFERENCED_TABLE_NAME'], $declared[$column]['target']);
            self::assertSame([$key['REFERENCED_COLUMN_NAME']], $declared[$column]['target_columns']);
            self::assertSame(CompanyBackupReferenceConstraint::Required->value, $declared[$column]['constraint']);
            self::assertSame($nullable[$column] ? [$column] : [], $declared[$column]['nullable_columns']);
        }
        $extra = array_values(array_diff(array_keys($declared), array_column($keys, 'COLUMN_NAME')));
        sort($extra);
        self::assertSame($softReferences, $extra);
    }
}
