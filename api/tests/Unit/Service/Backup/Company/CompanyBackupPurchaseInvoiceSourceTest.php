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
use MyInvoice\Service\Import\PurchaseInvoiceSourceFormat;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPurchaseInvoiceSourceTest extends TestCase
{
    public function testPreservesBothLayoutsWhileRemappingTenant(): void
    {
        $policy = CompanyBackupFilePathPolicy::SupplierPurchaseInvoiceSource;
        foreach (['abcdef0123456789.pdf', 'ab/abcdef0123456789.pdf', 'ab/abcdef0123456789.isdoc', 'ab/abcdef0123456789.isdocx', 'ab/abcdef0123456789.xml', 'ab/abcdef0123456789.json'] as $suffix) {
            $source = 'sources/supplier-7/' . $suffix;
            self::assertTrue($policy->accepts($source, 7));
            self::assertSame($source, $policy->sourcePath($source, 7));
            self::assertSame($source, $policy->storedRelativePath($source, 7));
            self::assertSame('sources/supplier-81/' . $suffix, $policy->restoreTargetPath($source, 7, 81));
            self::assertNull($policy->expectedContentSha256($source, 7));
        }
    }

    public function testAllowedExtensionsFollowRuntimeFormats(): void
    {
        self::assertSame(['isdoc', 'isdocx', 'pdf', 'xml', 'json'], PurchaseInvoiceSourceFormat::extensions());
        foreach ([
            'isdoc' => 'isdoc', 'isdocx' => 'isdocx', 'pdf' => 'pdf',
            'pohoda_xml' => 'xml', 'idoklad_json' => 'json', 'fakturoid_json' => 'json',
        ] as $format => $extension) {
            self::assertSame($extension, PurchaseInvoiceSourceFormat::extension($format));
            self::assertTrue(CompanyBackupFilePathPolicy::SupplierPurchaseInvoiceSource->accepts(
                'sources/supplier-7/ab/abcdef0123456789.' . $extension, 7,
            ));
        }
        self::assertNull(PurchaseInvoiceSourceFormat::extension('unknown'));
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsUnsafeOrForeignPaths(string $path): void
    {
        $policy = CompanyBackupFilePathPolicy::SupplierPurchaseInvoiceSource;
        self::assertFalse($policy->accepts($path, 7));
        $this->expectException(\InvalidArgumentException::class);
        $policy->sourcePath($path, 7);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'missing sources root' => ['supplier-7/abcdef0123456789.pdf'];
        yield 'unknown extension' => ['sources/supplier-7/abcdef0123456789.exe'];
        yield 'empty sources' => ['sources/'];
        yield 'month shard' => ['sources/supplier-7/2026/09/abcdef0123456789.pdf'];
        yield 'foreign' => ['sources/supplier-8/abcdef0123456789.pdf'];
        yield 'prefix' => ['sources/supplier-70/abcdef0123456789.pdf'];
        yield 'leading zero' => ['sources/supplier-07/abcdef0123456789.pdf'];
        yield 'wrong shard' => ['sources/supplier-7/ac/abcdef0123456789.pdf'];
        yield 'traversal' => ['sources/supplier-7/../abcdef0123456789.pdf'];
        yield 'backslash' => ['sources/supplier-7/ab\\abcdef0123456789.pdf'];
        yield 'uppercase' => ['sources/supplier-7/ABCDEF0123456789.pdf'];
        yield 'ads' => ['sources/supplier-7/abcdef0123456789.pdf:stream'];
        yield 'short hash' => ['sources/supplier-7/abcdef.pdf'];
        yield 'trailing newline' => ["sources/supplier-7/abcdef0123456789.pdf\n"];
    }
    public function testSqlSourceKeepsTenantIsolationAndAbsentOptionalPaths(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE purchase_invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, source_path TEXT)');
        $pdo->exec("INSERT INTO purchase_invoices VALUES (11,7,'sources/supplier-7/ab/abcdef0123456789.pdf'),"
            . "(12,8,'sources/supplier-8/abcdef0123456789.pdf'),(13,7,NULL),(14,7,'')");
        $registry = $this->registry();
        $area = $registry->definition('file-area:purchase-invoice-sources');
        self::assertNotNull($area);
        $source = new CompanyBackupSqlFileReferenceSource();
        $own = iterator_to_array($source->references($pdo, 7, $area, $registry));
        self::assertCount(1, $own);
        self::assertSame('sources/supplier-7/ab/abcdef0123456789.pdf', $own[0]->sourcePath);
        self::assertSame(['id' => 11], $own[0]->primaryKey);
        $foreign = iterator_to_array($source->references($pdo, 8, $area, $registry));
        self::assertCount(1, $foreign);
        self::assertSame(['id' => 12], $foreign[0]->primaryKey);
        $pdo->exec("UPDATE purchase_invoices SET source_path='sources/supplier-8/abcdef0123456789.pdf' WHERE id=11");
        $this->expectException(CompanyBackupFileSourceException::class);
        iterator_to_array($source->references($pdo, 7, $area, $registry));
    }

    public function testInvalidMetadataStopsBeforeSql(): void
    {
        foreach (['root', 'column', 'reference', 'ownership', 'prefix', 'policy'] as $fault) {
            $registry = $this->registry($fault);
            $area = $registry->definition('file-area:purchase-invoice-sources');
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
            new TenantDataDefinition('table:purchase_invoices', TenantDataObjectKind::Table,
                ($fault === 'policy' ? TenantDataPolicy::TenantOwnedIndirect : TenantDataPolicy::TenantOwned), [$profile], [
                    'primary_key' => ['id'],
                    'ownership' => ['strategy' => 'supplier_id', 'column' => $fault === 'ownership' ? 'other_id' : 'supplier_id'],
                    'secrets' => [],
                    'company_backup' => [
                        'data_columns' => ['id', 'supplier_id', 'source_path'],
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
            new TenantDataDefinition('file-area:purchase-invoice-sources', TenantDataObjectKind::FileArea,
                TenantDataPolicy::TenantOwned, [$profile], [
                    'file_policy' => 'historical_optional',
                    'path_policy' => 'supplier_purchase_invoice_source',
                    'storage_subdirectory' => $fault === 'root' ? 'invoices-imported' : 'purchase-invoices',
                    'ownership' => ['strategy' => 'database_references'],
                    'file_owners' => [[
                        'registry_key' => 'table:purchase_invoices',
                        'column' => $fault === 'column' ? 'pdf_path' : 'source_path',
                        'path' => [], 'stored_prefix' => $fault === 'prefix' ? 'sources/supplier-7/' : '',
                    ]],
                ]),
        ]);
    }

}
