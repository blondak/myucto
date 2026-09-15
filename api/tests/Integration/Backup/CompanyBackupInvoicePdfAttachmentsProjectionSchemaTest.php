<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceAttachmentsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupInvoicePdfsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Read-only ověření historie PDF a příloh vůči schématu izolované MariaDB. */
#[Group('integration')]
final class CompanyBackupInvoicePdfAttachmentsProjectionSchemaTest extends TestCase
{
    /** @return array<string,array{class-string,string,array<string,string>,array<string,string>,array<string,string>}> */
    public static function tables(): array
    {
        return [
            'invoice_pdfs' => [
                CompanyBackupInvoicePdfsProjection::class,
                'invoice_pdfs',
                ['sent_to' => 'YES'],
                ['invoice_id' => 'table:invoices:id'],
                ['invoice_id' => 'CASCADE'],
            ],
            'invoice_attachments' => [
                CompanyBackupInvoiceAttachmentsProjection::class,
                'invoice_attachments',
                ['uploaded_by' => 'YES'],
                ['invoice_id' => 'table:invoices:id', 'uploaded_by' => 'table:users:id'],
                ['invoice_id' => 'CASCADE', 'uploaded_by' => 'SET NULL'],
            ],
        ];
    }

    /**
     * @param class-string $projectionClass
     * @param string $table
     * @param array<string,string> $nullable
     * @param array<string,string> $physicalReferences
     * @param array<string,string> $deleteRules
     */
    #[DataProvider('tables')]
    public function testExactColumnsNullabilityAndPhysicalForeignKeys(
        string $projectionClass,
        string $table,
        array $nullable,
        array $physicalReferences,
        array $deleteRules,
    ): void {
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
        $columns = $pdo->prepare(
            'SELECT COLUMN_NAME, IS_NULLABLE, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
        );
        $columns->execute([$table]);
        $runtimeColumns = $columns->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame($projectionClass::dataColumns(),
            array_column($runtimeColumns, 'COLUMN_NAME'));
        self::assertSame([], array_keys(array_filter(
            $runtimeColumns,
            static fn (array $column): bool =>
                str_contains(strtoupper((string) $column['EXTRA']), 'GENERATED'),
        )));
        foreach ($runtimeColumns as $column) {
            $name = $column['COLUMN_NAME'];
            self::assertIsString($name);
            self::assertSame($nullable[$name] ?? 'NO', $column['IS_NULLABLE'], $name);
        }

        $declared = [];
        foreach ($projectionClass::references() as $reference) {
            $column = $reference['columns'][0];
            $declared[$column] = $reference['target'] . ':' . $reference['target_columns'][0];
            self::assertSame(CompanyBackupReferenceConstraint::Required->value,
                $reference['constraint'], $column);
            self::assertSame(isset($nullable[$column]) ? [$column] : [],
                $reference['nullable_columns'], $column);
            self::assertSame($column === 'uploaded_by'
                ? CompanyBackupReferenceMapping::Actor->value
                : CompanyBackupReferenceMapping::TenantId->value,
                $reference['mapping'], $column);
            self::assertSame($column === 'uploaded_by' ? ['null', 'restore_actor'] : [],
                $reference['fallbacks'], $column);
        }
        ksort($declared);
        ksort($physicalReferences);
        self::assertSame($physicalReferences, $declared);

        $keys = $pdo->prepare(
            'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
                    k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE AS k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS AS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.TABLE_NAME = k.TABLE_NAME
              AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ?
               AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.COLUMN_NAME',
        );
        $keys->execute([$table]);
        $runtimeReferences = [];
        $runtimeDeleteRules = [];
        foreach ($keys->fetchAll(PDO::FETCH_ASSOC) as $key) {
            $runtimeReferences[$key['COLUMN_NAME']] =
                'table:' . $key['REFERENCED_TABLE_NAME'] . ':' . $key['REFERENCED_COLUMN_NAME'];
            $runtimeDeleteRules[$key['COLUMN_NAME']] = $key['DELETE_RULE'];
        }
        ksort($runtimeReferences);
        ksort($runtimeDeleteRules);
        ksort($deleteRules);
        self::assertSame($physicalReferences, $runtimeReferences);
        self::assertSame($deleteRules, $runtimeDeleteRules);
    }
}
