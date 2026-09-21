<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupFilePathPolicy;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlFileReferenceSource;
use MyInvoice\Service\Backup\Company\CompanyBackupFileSourceException;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupImportedInvoicePdfTest extends TestCase
{
    public function testPreservesBothLayoutsWhileRemappingTenant(): void
    {
        $policy = CompanyBackupFilePathPolicy::SupplierImportedInvoicePdf;
        foreach (['abcdef0123456789.pdf', 'ab/abcdef0123456789.pdf'] as $suffix) {
            $source = 'supplier-7/' . $suffix;
            self::assertTrue($policy->accepts($source, 7));
            self::assertSame($source, $policy->sourcePath($source, 7));
            self::assertSame($source, $policy->storedRelativePath($source, 7));
            self::assertSame('supplier-81/' . $suffix, $policy->restoreTargetPath($source, 7, 81));
            self::assertNull($policy->expectedContentSha256($source, 7));
        }
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsUnsafeOrForeignPaths(string $path): void
    {
        $policy = CompanyBackupFilePathPolicy::SupplierImportedInvoicePdf;
        self::assertFalse($policy->accepts($path, 7));
        $this->expectException(\InvalidArgumentException::class);
        $policy->sourcePath($path, 7);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'foreign' => ['supplier-8/abcdef0123456789.pdf'];
        yield 'prefix' => ['supplier-70/abcdef0123456789.pdf'];
        yield 'leading zero' => ['supplier-07/abcdef0123456789.pdf'];
        yield 'wrong shard' => ['supplier-7/ac/abcdef0123456789.pdf'];
        yield 'traversal' => ['supplier-7/../abcdef0123456789.pdf'];
        yield 'backslash' => ['supplier-7/ab\\abcdef0123456789.pdf'];
        yield 'uppercase' => ['supplier-7/ABCDEF0123456789.pdf'];
        yield 'ads' => ['supplier-7/abcdef0123456789.pdf:stream'];
        yield 'short hash' => ['supplier-7/abcdef.pdf'];
        yield 'trailing newline' => ["supplier-7/abcdef0123456789.pdf\n"];
    }
    public function testSqlSourceKeepsTenantIsolationAndAbsentOptionalPaths(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, imported_pdf_path TEXT)');
        $pdo->exec("INSERT INTO invoices VALUES (11,7,'supplier-7/ab/abcdef0123456789.pdf'),"
            . "(12,8,'supplier-8/abcdef0123456789.pdf'),(13,7,NULL),(14,7,'')");
        $registry = $this->registry();
        $area = $registry->definition('file-area:invoice-imported-pdfs');
        self::assertNotNull($area);
        $source = new CompanyBackupSqlFileReferenceSource();
        $own = iterator_to_array($source->references($pdo, 7, $area, $registry));
        self::assertCount(1, $own);
        self::assertSame('supplier-7/ab/abcdef0123456789.pdf', $own[0]->sourcePath);
        self::assertSame(['id' => 11], $own[0]->primaryKey);
        $foreign = iterator_to_array($source->references($pdo, 8, $area, $registry));
        self::assertCount(1, $foreign);
        self::assertSame(['id' => 12], $foreign[0]->primaryKey);
        $pdo->exec("UPDATE invoices SET imported_pdf_path='supplier-8/abcdef0123456789.pdf' WHERE id=11");
        $this->expectException(CompanyBackupFileSourceException::class);
        iterator_to_array($source->references($pdo, 7, $area, $registry));
    }

    public function testInvalidMetadataStopsBeforeSql(): void
    {
        foreach (['root', 'column', 'reference', 'ownership'] as $fault) {
            $registry = $this->registry($fault);
            $area = $registry->definition('file-area:invoice-imported-pdfs');
            self::assertNotNull($area);
            $pdo = $this->createMock(PDO::class);
            $pdo->expects(self::never())->method('prepare');
            try {
                iterator_to_array((new CompanyBackupSqlFileReferenceSource())->references($pdo, 7, $area, $registry));
                self::fail('Neplatný kontrakt nesmí vytvořit SQL dotaz.');
            } catch (CompanyBackupFileSourceException $e) {
                self::assertSame('file_area_metadata_invalid', $e->errorCode);
            }
        }
    }

    private function registry(string $fault = ''): TenantDataRegistry
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return new TenantDataRegistry(1, [
            new TenantDataDefinition('table:invoices', TenantDataObjectKind::Table,
                TenantDataPolicy::TenantOwned, [$profile], [
                    'primary_key' => ['id'],
                    'ownership' => ['strategy' => 'supplier_id', 'column' => $fault === 'ownership' ? 'other_id' : 'supplier_id'],
                    'secrets' => [],
                    'company_backup' => [
                        'data_columns' => ['id', 'supplier_id', 'imported_pdf_path'],
                        'embedded_references' => [], 'generated_columns' => [],
                        'omit_columns' => [], 'restore_overrides' => [],
                        'references' => [[
                            'columns' => ['supplier_id'], 'target' => 'table:supplier',
                            'target_columns' => ['id'], 'mapping' => 'tenant_id',
                            'constraint' => 'required',
                            'nullable_columns' => $fault === 'reference' ? ['supplier_id'] : [],
                            'fallbacks' => [],
                        ]],
                    ],
                ]),
            new TenantDataDefinition('file-area:invoice-imported-pdfs', TenantDataObjectKind::FileArea,
                TenantDataPolicy::TenantOwned, [$profile], [
                    'file_policy' => 'historical_optional',
                    'path_policy' => 'supplier_imported_invoice_pdf',
                    'storage_subdirectory' => $fault === 'root' ? 'invoices' : 'invoices-imported',
                    'ownership' => ['strategy' => 'database_references'],
                    'file_owners' => [[
                        'registry_key' => 'table:invoices',
                        'column' => $fault === 'column' ? 'pdf_path' : 'imported_pdf_path',
                        'path' => [], 'stored_prefix' => '',
                    ]],
                ]),
        ]);
    }

}
