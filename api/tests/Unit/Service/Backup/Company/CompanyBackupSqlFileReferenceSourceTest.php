<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupFileSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupFileAreaRootResolver;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlFileReferenceSource;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSqlFileReferenceSourceTest extends TestCase
{
    public function testReadsScalarAndJsonOwnersWithDirectTenantBoundaries(): void
    {
        $branding = $this->statement([[
            'id' => 11,
            '_file_source_path' =>
                'storage/supplier-logos/sup-7-brand-11-abcdef123456.png',
        ]]);
        $invoice = $this->statement([[
            'id' => 101,
            '_file_source_path' =>
                'storage/supplier-logos/sup-7-brand-11-abcdef123456.png',
        ]]);
        $supplier = $this->statement([[
            'id' => 7,
            '_file_source_path' => 'storage/supplier-logos/sup-7.png',
        ]]);
        $pdo = $this->createMock(PDO::class);
        $queries = [];
        $statements = [$branding, $invoice, $supplier];
        $pdo->expects(self::exactly(3))
            ->method('prepare')
            ->willReturnCallback(static function (string $sql) use (
                &$queries,
                &$statements,
            ): PDOStatement {
                $queries[] = $sql;
                $statement = array_shift($statements);
                if (!$statement instanceof PDOStatement) {
                    throw new \LogicException('Test nemá připravený SQL statement.');
                }
                return $statement;
            });
        $registry = $this->registry();
        $area = $registry->definition('file-area:supplier-logos');
        self::assertNotNull($area);

        $references = iterator_to_array(
            (new CompanyBackupSqlFileReferenceSource(batchSize: 100))->references(
                $pdo,
                7,
                $area,
                $registry,
            ),
        );

        self::assertSame(
            [
                'sup-7-brand-11-abcdef123456.png',
                'sup-7-brand-11-abcdef123456.png',
                'sup-7.png',
            ],
            array_column($references, 'sourcePath'),
        );
        self::assertSame(
            ['table:branding_profiles', 'table:invoices', 'table:supplier'],
            array_column($references, 'registryKey'),
        );
        self::assertSame([[], ['logo_path'], []], array_column($references, 'path'));
        self::assertSame(
            'SELECT `_company_source`.`id`,'
            . ' `_company_source`.`logo_path` AS `_file_source_path`'
            . ' FROM `branding_profiles` AS `_company_source`'
            . ' WHERE `_company_source`.`supplier_id` = ?'
            . ' AND `_company_source`.`logo_path` IS NOT NULL'
            . " AND `_company_source`.`logo_path` <> ''"
            . ' ORDER BY `_company_source`.`id` LIMIT 100 OFFSET 0',
            $queries[0],
        );
        self::assertStringContainsString(
            "JSON_UNQUOTE(JSON_EXTRACT(`_company_source`.`supplier_snapshot`, '$.logo_path'))",
            $queries[1],
        );
        self::assertStringContainsString('`_company_source`.`supplier_id` = ?', $queries[1]);
        self::assertStringContainsString('`_company_source`.`id` = ?', $queries[2]);
    }

    public function testRejectsStoredPathOutsideRegisteredPrefix(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            ->willReturn($this->statement([[
                'id' => 11,
                '_file_source_path' => 'storage/private/outside.png',
            ]]));
        $registry = $this->registry();
        $area = $registry->definition('file-area:supplier-logos');
        self::assertNotNull($area);

        try {
            iterator_to_array(
                (new CompanyBackupSqlFileReferenceSource())->references(
                    $pdo,
                    7,
                    $area,
                    $registry,
                ),
            );
            self::fail('DB cesta mimo registrovaný prefix nesmí přejít na filesystem.');
        } catch (CompanyBackupFileSourceException $e) {
            self::assertSame('file_reference_path_invalid', $e->errorCode);
            self::assertSame('file-area:supplier-logos', $e->registryKey);
        }
    }

    public function testRejectsLogoPathCarryingAnotherSupplierIdentity(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            ->willReturn($this->statement([[
                'id' => 11,
                '_file_source_path' => 'storage/supplier-logos/sup-999.png',
            ]]));
        $registry = $this->registry();
        $area = $registry->definition('file-area:supplier-logos');
        self::assertNotNull($area);

        try {
            iterator_to_array(
                (new CompanyBackupSqlFileReferenceSource())->references(
                    $pdo,
                    7,
                    $area,
                    $registry,
                ),
            );
            self::fail('Cizí supplier identita v basename nesmí projít do zálohy.');
        } catch (CompanyBackupFileSourceException $e) {
            self::assertSame('file_reference_tenant_mismatch', $e->errorCode);
            self::assertSame('sup-999.png', $e->sourcePath);
        }
    }

    public function testExpandsContentHashOwnerIntoTenantShardPath(): void
    {
        $sha256 = str_repeat('a', 64);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            ->willReturn($this->statement([[
                'id' => 31,
                '_file_source_path' => $sha256,
            ]]));
        $registry = $this->contentRegistry();
        $area = $registry->definition('file-area:document-content');
        self::assertNotNull($area);

        $references = iterator_to_array(
            (new CompanyBackupSqlFileReferenceSource())->references(
                $pdo,
                7,
                $area,
                $registry,
            ),
        );

        self::assertCount(1, $references);
        self::assertSame('sup-7/aa/' . $sha256, $references[0]->sourcePath);
        self::assertSame('table:stock_media', $references[0]->registryKey);
        self::assertSame('storage_key', $references[0]->column);
    }

    public function testReadsOnlyIndirectInvoiceFileOwnersOfSelectedSupplierAcrossPages(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER)');
        $pdo->exec('CREATE TABLE invoice_attachments (id INTEGER PRIMARY KEY, invoice_id INTEGER, filename TEXT)');
        $pdo->exec('CREATE TABLE invoice_pdfs (id INTEGER PRIMARY KEY, invoice_id INTEGER, filename TEXT)');
        $pdo->exec('INSERT INTO supplier (id) VALUES (7), (8)');
        $pdo->exec('INSERT INTO invoices (id, supplier_id) VALUES (101, 7), (102, 7), (201, 8)');
        $pdo->exec("INSERT INTO invoice_attachments (id, invoice_id, filename) VALUES
            (11, 101, 'storage/invoices/own-attachment-11.pdf'),
            (12, 102, 'storage/invoices/own-attachment-12.pdf'),
            (13, 201, 'storage/invoices/foreign-attachment.pdf'),
            (14, 999, 'storage/invoices/orphan-attachment.pdf')");
        $pdo->exec("INSERT INTO invoice_pdfs (id, invoice_id, filename) VALUES
            (31, 101, 'storage/invoices/own-pdf-31.pdf'),
            (32, 102, 'storage/invoices/own-pdf-32.pdf'),
            (33, 201, 'storage/invoices/foreign-pdf.pdf'),
            (34, 999, 'storage/invoices/orphan-pdf.pdf')");
        $registry = $this->indirectRegistry($this->invoiceOwnership());
        $area = $registry->definition('file-area:invoice-test-files');
        self::assertNotNull($area);

        $references = iterator_to_array(
            (new CompanyBackupSqlFileReferenceSource(batchSize: 1))->references(
                $pdo,
                7,
                $area,
                $registry,
            ),
        );

        self::assertSame(
            ['table:invoice_attachments', 'table:invoice_attachments',
                'table:invoice_pdfs', 'table:invoice_pdfs'],
            array_column($references, 'registryKey'),
        );
        self::assertSame(
            [['id' => 11], ['id' => 12], ['id' => 31], ['id' => 32]],
            array_column($references, 'primaryKey'),
        );
        self::assertSame(
            ['own-attachment-11.pdf', 'own-attachment-12.pdf',
                'own-pdf-31.pdf', 'own-pdf-32.pdf'],
            array_column($references, 'sourcePath'),
        );

        $otherSupplier = iterator_to_array(
            (new CompanyBackupSqlFileReferenceSource(batchSize: 1))->references(
                $pdo,
                8,
                $area,
                $registry,
            ),
        );
        self::assertSame(
            [['id' => 13], ['id' => 33]],
            array_column($otherSupplier, 'primaryKey'),
        );
        self::assertSame(
            ['foreign-attachment.pdf', 'foreign-pdf.pdf'],
            array_column($otherSupplier, 'sourcePath'),
        );
    }

    public function testRejectsInvalidIndirectOwnershipMetadataBeforeQuery(): void
    {
        $cases = [
            [$this->invoiceOwnership(path: [[
                'from_column' => 'invoice_id',
                'to_table' => 'invoices',
                'to_column' => 'id',
            ]]), ['id', 'invoice_id', 'filename'], 'file_reference_ownership_invalid'],
            [$this->invoiceOwnership(), ['id', 'filename'], 'file_reference_ownership_invalid'],
            [['strategy' => 'unknown_relationship'],
                ['id', 'invoice_id', 'filename'], 'file_reference_ownership_unsupported'],
        ];
        foreach ($cases as [$ownership, $columns, $expectedCode]) {
            $registry = $this->indirectRegistry($ownership, $columns);
            $area = $registry->definition('file-area:invoice-test-files');
            self::assertNotNull($area);
            $pdo = $this->createMock(PDO::class);
            $pdo->expects(self::never())->method('prepare');

            try {
                iterator_to_array(
                    (new CompanyBackupSqlFileReferenceSource())->references(
                        $pdo,
                        7,
                        $area,
                        $registry,
                    ),
                );
                self::fail('Vadná nepřímá ownership metadata nesmějí otevřít SQL dotaz.');
            } catch (CompanyBackupFileSourceException $e) {
                self::assertSame($expectedCode, $e->errorCode);
                self::assertSame('file-area:invoice-test-files', $e->registryKey);
                self::assertNull($e->sourcePath);
                self::assertNull($e->getPrevious());
                self::assertStringNotContainsString('SELECT', $e->getMessage());
            }
        }
    }

    public function testInvoiceAttachmentsKeepSameBasenameDistinctByInvoiceId(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER)');
        $pdo->exec('CREATE TABLE invoice_attachments (id INTEGER PRIMARY KEY, invoice_id INTEGER, filename TEXT)');
        $pdo->exec('INSERT INTO supplier (id) VALUES (7), (8)');
        $pdo->exec('INSERT INTO invoices (id, supplier_id) VALUES (101, 7), (102, 7), (201, 8)');
        $pdo->exec("INSERT INTO invoice_attachments (id, invoice_id, filename) VALUES
            (11, 101, 'same.pdf'), (12, 102, 'same.pdf'),
            (13, 201, 'same.pdf'), (14, 999, 'orphan.pdf')");
        $registry = $this->invoiceAttachmentRegistry();
        $area = $registry->definition('file-area:invoice-attachments');
        self::assertNotNull($area);
        $source = new CompanyBackupSqlFileReferenceSource(batchSize: 1);

        $own = iterator_to_array($source->references($pdo, 7, $area, $registry));
        self::assertSame(
            ['sup-7/attachments/101/same.pdf',
                'sup-7/attachments/102/same.pdf'],
            array_column($own, 'sourcePath'),
        );
        self::assertSame(
            [['id' => 11], ['id' => 12]],
            array_column($own, 'primaryKey'),
        );
        self::assertSame(
            ['table:invoice_attachments', 'table:invoice_attachments'],
            array_column($own, 'registryKey'),
        );
        self::assertSame(['filename', 'filename'], array_column($own, 'column'));

        $foreign = iterator_to_array($source->references($pdo, 8, $area, $registry));
        self::assertSame([['id' => 13]], array_column($foreign, 'primaryKey'));
        self::assertSame(
            ['sup-8/attachments/201/same.pdf'],
            array_column($foreign, 'sourcePath'),
        );
    }

    public function testInvoiceAttachmentRejectsNullEmptyAndInvalidBasenamesInsteadOfSkipping(): void
    {
        foreach ([null, '', '../escape.pdf'] as $basename) {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY)');
            $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER)');
            $pdo->exec('CREATE TABLE invoice_attachments (id INTEGER PRIMARY KEY, invoice_id INTEGER, filename TEXT)');
            $pdo->exec('INSERT INTO supplier (id) VALUES (7)');
            $pdo->exec('INSERT INTO invoices (id, supplier_id) VALUES (101, 7)');
            $insert = $pdo->prepare(
                'INSERT INTO invoice_attachments (id, invoice_id, filename)'
                    . ' VALUES (11, 101, ?)',
            );
            self::assertInstanceOf(PDOStatement::class, $insert);
            self::assertTrue($insert->execute([$basename]));
            self::assertTrue($insert->closeCursor());
            $registry = $this->invoiceAttachmentRegistry();
            $area = $registry->definition('file-area:invoice-attachments');
            self::assertNotNull($area);

            try {
                iterator_to_array(
                    (new CompanyBackupSqlFileReferenceSource(batchSize: 1))
                        ->references($pdo, 7, $area, $registry),
                );
                self::fail('Nesprávný basename nesmí být přes SQL filtr vynechán.');
            } catch (CompanyBackupFileSourceException $e) {
                self::assertSame('file_reference_path_invalid', $e->errorCode);
                self::assertSame('file-area:invoice-attachments', $e->registryKey);
            }
        }
    }

    public function testInvoicePdfsPreferMonthlyArchiveAndFallbackToLegacyFlatPath(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-pdf-source-' . bin2hex(random_bytes(8));
        $monthly = $root . DIRECTORY_SEPARATOR . 'sup-7'
            . DIRECTORY_SEPARATOR . '_archive' . DIRECTORY_SEPARATOR . '2025-01';
        $flat = $root . DIRECTORY_SEPARATOR . 'sup-8'
            . DIRECTORY_SEPARATOR . '_archive';
        self::assertTrue(mkdir($monthly, 0700, true));
        self::assertTrue(mkdir($flat, 0700, true));
        $monthlyName = '20250131-120000-aaaaaaaa-monthly.pdf';
        $flatName = '20250201-120000-bbbbbbbb-flat.pdf';
        self::assertSame(7, file_put_contents(
            $monthly . DIRECTORY_SEPARATOR . $monthlyName,
            'monthly',
        ));
        self::assertSame(11, file_put_contents(
            dirname($monthly) . DIRECTORY_SEPARATOR . $monthlyName,
            'legacy-flat',
        ));
        self::assertSame(4, file_put_contents(
            $flat . DIRECTORY_SEPARATOR . $flatName,
            'flat',
        ));

        try {
            $pdo = $this->invoicePdfDatabase($monthlyName, $flatName);
            $registry = $this->invoicePdfRegistry();
            $area = $registry->definition('file-area:invoice-pdfs');
            self::assertNotNull($area);
            $roots = new CountingPdfRootResolver($root);
            $source = new CompanyBackupSqlFileReferenceSource(
                batchSize: 1,
                roots: $roots,
            );

            $own = iterator_to_array($source->references(
                $pdo, 7, $area, $registry,
            ));
            $foreign = iterator_to_array($source->references(
                $pdo, 8, $area, $registry,
            ));

            self::assertSame([
                'sup-7/_archive/2025-01/' . $monthlyName,
            ], array_column($own, 'sourcePath'));
            self::assertSame([
                'sup-8/_archive/' . $flatName,
            ], array_column($foreign, 'sourcePath'));
            self::assertSame([['id' => 31]], array_column($own, 'primaryKey'));
            self::assertSame([['id' => 32]], array_column($foreign, 'primaryKey'));
            self::assertSame(2, $roots->calls);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testMonthlyPdfSymlinkOutsideRootStopsCollectionDespiteFlatFallback(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-pdf-source-' . bin2hex(random_bytes(8));
        $monthly = $root . '/sup-7/_archive/2025-01';
        self::assertTrue(mkdir($monthly, 0700, true));
        $outside = tempnam(sys_get_temp_dir(), 'myucto-pdf-outside-');
        self::assertIsString($outside);
        $name = '20250131-120000-aaaaaaaa-monthly.pdf';
        try {
            self::assertSame(7, file_put_contents($outside, 'outside'));
            self::assertSame(4, file_put_contents(dirname($monthly) . '/' . $name, 'flat'));
            if (!@symlink($outside, $monthly . '/' . $name)) {
                self::markTestSkipped('Platforma testu nedovoluje vytvořit symlink.');
            }
            $pdo = $this->invoicePdfDatabase($name, 'other.pdf');
            $registry = new TenantDataRegistry(1, $this->invoicePdfRegistry()->definitions(), [
                TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            ]);
            $area = $registry->definition('file-area:invoice-pdfs');
            self::assertNotNull($area);
            $roots = new CountingPdfRootResolver($root);
            $source = new CompanyBackupSqlFileReferenceSource(roots: $roots);
            $references = iterator_to_array($source->references($pdo, 7, $area, $registry));
            self::assertSame('sup-7/_archive/2025-01/' . $name, $references[0]->sourcePath);

            try {
                (new \MyInvoice\Service\Backup\Company\CompanyBackupFileCollector($roots))->collect(
                    $pdo,
                    \MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot::fromRegistry(
                        $registry,
                        TenantDataRegistry::COMPANY_BACKUP_PROFILE,
                    ),
                    7,
                    $source,
                );
                self::fail('Měsíční symlink nesmí uniknout kontrole ani použít plochou kopii.');
            } catch (CompanyBackupFileSourceException $e) {
                self::assertSame('file_source_path_unsafe', $e->errorCode);
                self::assertSame('sup-7/_archive/2025-01/' . $name, $e->sourcePath);
            }
        } finally {
            @unlink($outside);
            $this->removeTree($root);
        }
    }

    public function testInvoicePdfMissingCandidatesKeepLegacyFlatReference(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-pdf-source-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700, true));
        try {
            $name = '20251340-120000-cccccccc-missing.pdf';
            $pdo = $this->invoicePdfDatabase($name, 'other.pdf');
            $registry = $this->invoicePdfRegistry();
            $area = $registry->definition('file-area:invoice-pdfs');
            self::assertNotNull($area);

            $references = iterator_to_array(
                (new CompanyBackupSqlFileReferenceSource(
                    roots: new CountingPdfRootResolver($root),
                ))->references($pdo, 7, $area, $registry),
            );

            self::assertSame(
                ['sup-7/_archive/' . $name],
                array_column($references, 'sourcePath'),
            );
        } finally {
            $this->removeTree($root);
        }
    }

    public function testInvoicePdfFilenameWithoutDateHasOnlyLegacyFlatCandidate(): void
    {
        $pdo = $this->invoicePdfDatabase('legacy.pdf', 'other.pdf');
        $registry = $this->invoicePdfRegistry();
        $area = $registry->definition('file-area:invoice-pdfs');
        self::assertNotNull($area);

        $references = iterator_to_array(
            (new CompanyBackupSqlFileReferenceSource(
                roots: new CountingPdfRootResolver(sys_get_temp_dir()),
            ))->references($pdo, 7, $area, $registry),
        );

        self::assertSame(
            ['sup-7/_archive/legacy.pdf'],
            array_column($references, 'sourcePath'),
        );
    }

    public function testInvoicePdfRejectsInvalidRootBeforeQuery(): void
    {
        $registry = $this->invoicePdfRegistry();
        $area = $registry->definition('file-area:invoice-pdfs');
        self::assertNotNull($area);
        foreach (['', "bad\0root"] as $root) {
            $pdo = $this->createMock(PDO::class);
            $pdo->expects(self::never())->method('prepare');
            try {
                iterator_to_array(
                    (new CompanyBackupSqlFileReferenceSource(
                        roots: new CountingPdfRootResolver($root),
                    ))->references($pdo, 7, $area, $registry),
                );
                self::fail('Neplatný runtime kořen nesmí otevřít SQL dotaz.');
            } catch (CompanyBackupFileSourceException $e) {
                self::assertSame('file_area_root_invalid', $e->errorCode);
                self::assertSame('file-area:invoice-pdfs', $e->registryKey);
            }
        }
    }

    public function testInvoicePdfRejectsNullAndEmptyFilenameInsteadOfSkipping(): void
    {
        foreach ([null, ''] as $filename) {
            $pdo = $this->invoicePdfDatabase($filename, 'other.pdf');
            $registry = $this->invoicePdfRegistry();
            $area = $registry->definition('file-area:invoice-pdfs');
            self::assertNotNull($area);
            try {
                iterator_to_array(
                    (new CompanyBackupSqlFileReferenceSource(
                        roots: new CountingPdfRootResolver(sys_get_temp_dir()),
                    ))->references($pdo, 7, $area, $registry),
                );
                self::fail('Prázdný PDF filename nesmí být přes SQL filtr vynechán.');
            } catch (CompanyBackupFileSourceException $e) {
                self::assertSame('file_reference_path_invalid', $e->errorCode);
                self::assertSame('file-area:invoice-pdfs', $e->registryKey);
            }
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function statement(array $rows): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with([7])
            ->willReturn(true);
        $statement->expects(self::once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($rows);
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        return $statement;
    }

    private function registry(): TenantDataRegistry
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return new TenantDataRegistry(1, [
            $this->table(
                'branding_profiles',
                TenantDataPolicy::TenantOwned,
                ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
            ),
            $this->table(
                'invoices',
                TenantDataPolicy::TenantOwned,
                ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
            ),
            $this->table(
                'supplier',
                TenantDataPolicy::TenantRoot,
                ['strategy' => 'selected_supplier', 'column' => 'id'],
            ),
            new TenantDataDefinition(
                'file-area:supplier-logos',
                TenantDataObjectKind::FileArea,
                TenantDataPolicy::TenantOwned,
                [$profile],
                [
                    'file_policy' => 'historical_optional',
                    'ownership' => ['strategy' => 'database_references'],
                    'path_policy' => 'supplier_logo',
                    'storage_subdirectory' => 'supplier-logos',
                    'file_owners' => [
                        $this->owner('table:branding_profiles', 'logo_path'),
                        $this->owner(
                            'table:invoices',
                            'supplier_snapshot',
                            ['logo_path'],
                        ),
                        $this->owner('table:supplier', 'logo_path'),
                    ],
                ],
            ),
        ]);
    }

    private function contentRegistry(): TenantDataRegistry
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return new TenantDataRegistry(1, [
            $this->table(
                'stock_media',
                TenantDataPolicy::TenantOwned,
                ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
            ),
            new TenantDataDefinition(
                'file-area:document-content',
                TenantDataObjectKind::FileArea,
                TenantDataPolicy::TenantOwned,
                [$profile],
                [
                    'file_policy' => 'required',
                    'ownership' => ['strategy' => 'database_references'],
                    'path_policy' => 'supplier_content_hash',
                    'storage_subdirectory' => 'documents',
                    'file_owners' => [[
                        'registry_key' => 'table:stock_media',
                        'column' => 'storage_key',
                        'path' => [],
                        'stored_prefix' => '',
                    ]],
                ],
            ),
        ]);
    }

    private function invoiceAttachmentRegistry(): TenantDataRegistry
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return new TenantDataRegistry(1, [
            new TenantDataDefinition(
                'table:invoice_attachments',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantOwnedIndirect,
                [$profile],
                [
                    'primary_key' => ['id'],
                    'ownership' => $this->invoiceOwnership(),
                    'secrets' => [],
                    'company_backup' => [
                        'data_columns' => ['id', 'invoice_id', 'filename'],
                        'embedded_references' => [],
                        'generated_columns' => [],
                        'omit_columns' => [],
                        'references' => [[
                            'columns' => ['invoice_id'],
                            'target' => 'table:invoices',
                            'target_columns' => ['id'],
                            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                            'constraint' => CompanyBackupReferenceConstraint::Required->value,
                            'nullable_columns' => [],
                            'fallbacks' => [],
                        ]],
                        'restore_overrides' => [],
                    ],
                ],
            ),
            new TenantDataDefinition(
                'file-area:invoice-attachments',
                TenantDataObjectKind::FileArea,
                TenantDataPolicy::TenantOwned,
                [$profile],
                [
                    'file_policy' => 'historical_optional',
                    'ownership' => ['strategy' => 'database_references'],
                    'path_policy' => 'supplier_invoice_attachment',
                    'storage_subdirectory' => 'invoices',
                    'file_owners' => [[
                        'registry_key' => 'table:invoice_attachments',
                        'column' => 'filename',
                        'path' => [],
                        'stored_prefix' => '',
                    ]],
                ],
            ),
        ]);
    }

    private function invoicePdfDatabase(mixed $ownFilename, mixed $foreignFilename): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER)');
        $pdo->exec('CREATE TABLE invoice_pdfs (id INTEGER PRIMARY KEY, invoice_id INTEGER, filename TEXT)');
        $pdo->exec('INSERT INTO supplier (id) VALUES (7), (8)');
        $pdo->exec('INSERT INTO invoices (id, supplier_id) VALUES (101, 7), (201, 8)');
        $statement = $pdo->prepare(
            'INSERT INTO invoice_pdfs (id, invoice_id, filename) VALUES (?, ?, ?)',
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        self::assertTrue($statement->execute([31, 101, $ownFilename]));
        self::assertTrue($statement->execute([32, 201, $foreignFilename]));
        self::assertTrue($statement->closeCursor());
        return $pdo;
    }

    private function invoicePdfRegistry(): TenantDataRegistry
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return new TenantDataRegistry(1, [
            new TenantDataDefinition(
                'table:invoice_pdfs',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantOwnedIndirect,
                [$profile],
                [
                    'primary_key' => ['id'],
                    'ownership' => $this->invoiceOwnership(),
                    'secrets' => [],
                    'company_backup' => [
                        'data_columns' => ['id', 'invoice_id', 'filename'],
                        'embedded_references' => [],
                        'generated_columns' => [],
                        'omit_columns' => [],
                        'references' => [[
                            'columns' => ['invoice_id'],
                            'target' => 'table:invoices',
                            'target_columns' => ['id'],
                            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                            'constraint' => CompanyBackupReferenceConstraint::Required->value,
                            'nullable_columns' => [],
                            'fallbacks' => [],
                        ]],
                        'restore_overrides' => [],
                    ],
                ],
            ),
            new TenantDataDefinition(
                'file-area:invoice-pdfs',
                TenantDataObjectKind::FileArea,
                TenantDataPolicy::TenantOwned,
                [$profile],
                [
                    'file_policy' => 'historical_optional',
                    'ownership' => ['strategy' => 'database_references'],
                    'path_policy' => 'supplier_invoice_pdf',
                    'storage_subdirectory' => 'invoices',
                    'file_owners' => [[
                        'registry_key' => 'table:invoice_pdfs',
                        'column' => 'filename',
                        'path' => [],
                        'stored_prefix' => '',
                    ]],
                ],
            ),
        ]);
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink()
                ? @rmdir($item->getPathname())
                : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }

    /**
     * @param list<array{from_column:string,to_table:string,to_column:string}>|null $path
     * @return array<string,mixed>
     */
    private function invoiceOwnership(?array $path = null): array
    {
        return [
            'strategy' => 'foreign_key_path',
            'path' => $path ?? [
                ['from_column' => 'invoice_id', 'to_table' => 'invoices', 'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier', 'to_column' => 'id'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $ownership
     * @param list<string> $columns
     */
    private function indirectRegistry(
        array $ownership,
        array $columns = ['id', 'invoice_id', 'filename'],
    ): TenantDataRegistry {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $tables = [];
        foreach (['invoice_attachments', 'invoice_pdfs'] as $name) {
            $tables[] = new TenantDataDefinition(
                'table:' . $name,
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantOwnedIndirect,
                [$profile],
                [
                    'primary_key' => ['id'],
                    'ownership' => $ownership,
                    'secrets' => [],
                    'company_backup' => [
                        'data_columns' => $columns,
                        'embedded_references' => [],
                        'generated_columns' => [],
                        'omit_columns' => [],
                        'references' => [[
                            'columns' => ['invoice_id'],
                            'target' => 'table:invoices',
                            'target_columns' => ['id'],
                            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                            'constraint' => CompanyBackupReferenceConstraint::Required->value,
                            'nullable_columns' => [],
                            'fallbacks' => [],
                        ]],
                        'restore_overrides' => [],
                    ],
                ],
            );
        }
        return new TenantDataRegistry(1, [
            ...$tables,
            new TenantDataDefinition(
                'file-area:invoice-test-files',
                TenantDataObjectKind::FileArea,
                TenantDataPolicy::TenantOwned,
                [$profile],
                [
                    'file_policy' => 'historical_optional',
                    'ownership' => ['strategy' => 'database_references'],
                    'path_policy' => 'relative',
                    'storage_subdirectory' => 'invoices',
                    'file_owners' => [
                        ['registry_key' => 'table:invoice_attachments',
                            'column' => 'filename', 'path' => [],
                            'stored_prefix' => 'storage/invoices/'],
                        ['registry_key' => 'table:invoice_pdfs',
                            'column' => 'filename', 'path' => [],
                            'stored_prefix' => 'storage/invoices/'],
                    ],
                ],
            ),
        ]);
    }

    /** @param array<string,mixed> $ownership */
    private function table(
        string $name,
        TenantDataPolicy $policy,
        array $ownership,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $name,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => $ownership,
            ],
        );
    }

    /**
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private function owner(
        string $registryKey,
        string $column,
        array $path = [],
    ): array {
        return [
            'registry_key' => $registryKey,
            'column' => $column,
            'path' => $path,
            'stored_prefix' => 'storage/supplier-logos/',
        ];
    }
}

final class CountingPdfRootResolver implements CompanyBackupFileAreaRootResolver
{
    public int $calls = 0;

    public function __construct(private readonly string $root) {}

    public function resolve(string $storageSubdirectory): string
    {
        ++$this->calls;
        return $this->root;
    }
}
