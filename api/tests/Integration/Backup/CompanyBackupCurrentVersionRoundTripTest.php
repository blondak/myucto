<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveInspector;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupAutoIncrementColumn;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflight;
use MyInvoice\Service\Backup\Company\CompanyBackupDataRowSource;
use MyInvoice\Service\Backup\Company\CompanyBackupDatabaseImporter;
use MyInvoice\Service\Backup\Company\CompanyBackupFileAreaRootResolver;
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFilePublisher;
use MyInvoice\Service\Backup\Company\CompanyBackupFileRestoreStager;
use MyInvoice\Service\Backup\Company\CompanyBackupFileStagingRootResolver;
use MyInvoice\Service\Backup\Company\CompanyBackupFormat;
use MyInvoice\Service\Backup\Company\CompanyBackupImportSchemaSource;
use MyInvoice\Service\Backup\Company\CompanyBackupImportTableMetadata;
use MyInvoice\Service\Backup\Company\CompanyBackupJsonlWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupMachineArchiveWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupMachineSnapshot;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupRegistryPostImportValidator;
use MyInvoice\Service\Backup\Company\CompanyBackupRestoreCoordinator;
use MyInvoice\Service\Backup\Company\CompanyBackupRestoreService;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeDescriptor;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableSchema;
use MyInvoice\Service\Backup\Company\CompanyBackupTenantSqlSelector;
use MyInvoice\Service\Backup\Company\CompanyBackupTechnicalValidation;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** Aktuální formát musí projít celou obnovou nad skutečným AES ZIPem. */
#[Group('integration')]
final class CompanyBackupCurrentVersionRoundTripTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';
    private const INSTANCE_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const PASSWORD = 'synthetic-round-trip-password-42';
    private const APP_VERSION = '5.28.1';

    private Connection $db;

    private string $recordsTable = '';

    private string $root = '';

    private bool $connected = false;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)
            || !defined('ZipArchive::EM_AES_256')
        ) {
            $this->markTestSkipped('Round-trip vyžaduje ZIP s AES-256.');
        }
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                throw new \RuntimeException('Aplikace nemá DI kontejner.');
            }
            $connection = $container->get(Connection::class);
            if (!$connection instanceof Connection) {
                throw new \RuntimeException('DI nevrátilo databázové spojení.');
            }
            $pdo = $connection->pdo();
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                throw new \RuntimeException('Test vyžaduje MariaDB.');
            }
            $this->db = $connection;
            $this->connected = true;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Testovací DB není dostupná: ' . $e->getMessage());
        }

        $this->recordsTable = 'company_backup_roundtrip_' . bin2hex(random_bytes(4));
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-company-roundtrip-' . bin2hex(random_bytes(8));
        if (!mkdir($this->root, 0700)) {
            throw new \RuntimeException('Nelze vytvořit pracovní adresář testu.');
        }

        $pdo = $this->db->pdo();
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS supplier');
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS users');
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS purchase_invoices');
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS invoice_pdfs');
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS invoice_attachments');
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS invoices');
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS email_profiles');
        $pdo->exec(
            'CREATE TEMPORARY TABLE supplier ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'company_name VARCHAR(190) NOT NULL,'
            . 'logo_path VARCHAR(255) NULL'
            . ') ENGINE=InnoDB',
        );
        $pdo->exec(
            'CREATE TEMPORARY TABLE users ('
            . 'id BIGINT UNSIGNED NOT NULL PRIMARY KEY'
            . ') ENGINE=InnoDB',
        );
        $pdo->exec('CREATE TEMPORARY TABLE email_profiles ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'supplier_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO email_profiles (id,supplier_id) VALUES (11,7)');
        $pdo->exec(
            'CREATE TEMPORARY TABLE invoices ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'supplier_id BIGINT UNSIGNED NOT NULL,'
            . 'invoice_number VARCHAR(64) NOT NULL,'
            . 'imported_pdf_path VARCHAR(255) NULL,'
            . 'pdf_path VARCHAR(255) NULL,'
            . 'pdf_generated_at DATETIME NULL,'
            . 'supplier_snapshot JSON NULL,'
            . 'client_snapshot JSON NULL,'
            . 'bank_snapshot JSON NULL'
            . ') ENGINE=InnoDB',
        );
        $pdo->exec(
            'CREATE TEMPORARY TABLE purchase_invoices ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'supplier_id BIGINT UNSIGNED NOT NULL,'
            . 'pdf_path VARCHAR(255) NULL,'
            . 'pdf_hash CHAR(64) NULL,'
            . 'source_path VARCHAR(255) NULL,'
            . 'source_hash CHAR(64) NULL,'
            . 'source_format VARCHAR(32) NULL,'
            . 'source_size_bytes BIGINT UNSIGNED NULL,'
            . 'source_original_name VARCHAR(255) NULL,'
            . 'vendor_snapshot JSON NOT NULL,'
            . 'own_snapshot JSON NULL'
            . ') ENGINE=InnoDB',
        );
        $pdo->exec(
            'CREATE TEMPORARY TABLE invoice_attachments ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'invoice_id BIGINT UNSIGNED NOT NULL,'
            . 'filename VARCHAR(255) NOT NULL'
            . ') ENGINE=InnoDB',
        );
        $pdo->exec(
            'CREATE TEMPORARY TABLE invoice_pdfs ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'invoice_id BIGINT UNSIGNED NOT NULL,'
            . 'filename VARCHAR(255) NOT NULL,'
            . 'size_bytes BIGINT UNSIGNED NOT NULL,'
            . 'sha256 CHAR(64) NOT NULL,'
            . 'was_sent TINYINT(1) NOT NULL,'
            . 'sent_to TEXT NULL,'
            . 'reason VARCHAR(64) NOT NULL,'
            . 'archived_at DATETIME NOT NULL'
            . ') ENGINE=InnoDB',
        );
        $pdo->exec(
            'CREATE TABLE `' . $this->recordsTable . '` ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'supplier_id BIGINT UNSIGNED NOT NULL,'
            . 'parent_id BIGINT UNSIGNED NULL,'
            . 'code VARCHAR(64) NOT NULL,'
            . "updated_at TIMESTAMP NOT NULL DEFAULT '2020-01-01 12:00:00' ON UPDATE CURRENT_TIMESTAMP,"
            . 'UNIQUE KEY uq_roundtrip_supplier_code (supplier_id, code)'
            . ') ENGINE=InnoDB',
        );
        $pdo->exec(
            "INSERT INTO supplier (id, company_name, logo_path)"
            . " VALUES (7, 'Existing tenant', NULL)",
        );
        $pdo->exec('INSERT INTO users (id) VALUES (91)');
        $pdo->exec(
            "INSERT INTO invoices (id, supplier_id, invoice_number)"
            . " VALUES (101, 7, 'existing-invoice')",
        );
        $pdo->exec(
            "INSERT INTO invoice_attachments (id, invoice_id, filename)"
            . " VALUES (11, 101, 'same.pdf')",
        );
        $pdo->exec("UPDATE invoices SET imported_pdf_path='supplier-7/ab/abcdef0123456789.pdf' WHERE id=101");
        $existingImported = $this->root . '/live/custom-issued-archive/supplier-7/ab';
        self::assertTrue(mkdir($existingImported, 0700, true));
        self::assertSame(strlen("existing-imported-tenant7\n"), file_put_contents(
            $existingImported . '/abcdef0123456789.pdf', "existing-imported-tenant7\n",
        ));
        $pdo->exec("INSERT INTO purchase_invoices (id,supplier_id,pdf_path,pdf_hash,vendor_snapshot)"
            . " VALUES (201,7,'supplier-7/1234567890abcdef.pdf',NULL,'{}')");
        $purchaseDirectory = $this->root . '/live/custom-purchase-archive/supplier-7';
        self::assertTrue(mkdir($purchaseDirectory, 0700, true));
        $existingPurchase = "existing-purchase-tenant7\n";
        self::assertSame(strlen($existingPurchase), file_put_contents(
            $purchaseDirectory . '/1234567890abcdef.pdf', $existingPurchase,
        ));
        $existingSourcePath = $this->root . '/live/custom-purchase-archive/' . self::purchaseSourcePath(7);
        self::assertTrue(mkdir(dirname($existingSourcePath), 0700, true));
        self::assertSame(8, file_put_contents($existingSourcePath, 'original'));
        $sourceUpdate = $pdo->prepare('UPDATE purchase_invoices SET source_path=?, source_hash=?,'
            . ' source_format=?, source_size_bytes=?, source_original_name=? WHERE id=201');
        self::assertInstanceOf(PDOStatement::class, $sourceUpdate);
        self::assertTrue($sourceUpdate->execute([
            self::purchaseSourcePath(7), hash('sha256', 'original'), 'isdocx', 8, 'existing.isdocx',
        ]));
        self::assertTrue($sourceUpdate->closeCursor());
        $pdo->exec("UPDATE invoices SET pdf_path='sup-7/rendered.pdf',"
            . " pdf_generated_at='2025-01-01 10:00:00' WHERE id=101");
        $cacheDirectory = $this->root . '/live/invoices/sup-7';
        self::assertTrue(mkdir($cacheDirectory, 0700, true));
        self::assertSame(8, file_put_contents($cacheDirectory . '/rendered.pdf', 'original'));
        $pdfFilename = '20250131-120000-aaaaaaaa-invoice.pdf';
        $pdfContents = "existing-tenant-archived-pdf\n";
        $pdfInsert = $pdo->prepare(
            'INSERT INTO invoice_pdfs'
            . ' (id, invoice_id, filename, size_bytes, sha256, was_sent,'
            . ' sent_to, reason, archived_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        if (!$pdfInsert instanceof PDOStatement || !$pdfInsert->execute([
            31, 101, $pdfFilename, strlen($pdfContents),
            hash('sha256', $pdfContents), 1,
            '["existing@example.test"]', 'sent', '2025-01-31 12:00:00',
        ])) {
            throw new \RuntimeException('Nelze vložit syntetický PDF archiv.');
        }
        if (!$pdfInsert->closeCursor()) {
            throw new \RuntimeException('Nelze uzavřít vložení PDF archivu.');
        }
        $pdo->exec(
            'INSERT INTO `' . $this->recordsTable . '`'
            . ' (id, supplier_id, parent_id, code) VALUES'
            . " (31, 7, NULL, 'existing-parent'),"
            . " (32, 7, 31, 'existing-child')",
        );
        $existingFile = $this->root . DIRECTORY_SEPARATOR . 'live'
            . DIRECTORY_SEPARATOR . 'invoices'
            . DIRECTORY_SEPARATOR . 'sup-7'
            . DIRECTORY_SEPARATOR . 'attachments'
            . DIRECTORY_SEPARATOR . '101';
        if (!mkdir($existingFile, 0700, true)
            || file_put_contents(
                $existingFile . DIRECTORY_SEPARATOR . 'same.pdf',
                "existing-tenant-attachment\n",
            ) === false
        ) {
            throw new \RuntimeException('Nelze vytvořit izolovanou existující přílohu.');
        }
        $existingPdfDirectory = $this->root . DIRECTORY_SEPARATOR . 'live'
            . DIRECTORY_SEPARATOR . 'invoices' . DIRECTORY_SEPARATOR . 'sup-7'
            . DIRECTORY_SEPARATOR . '_archive';
        if (!mkdir($existingPdfDirectory, 0700, true)
            || file_put_contents(
                $existingPdfDirectory . DIRECTORY_SEPARATOR . $pdfFilename,
                $pdfContents,
            ) !== strlen($pdfContents)
        ) {
            throw new \RuntimeException('Nelze vytvořit izolovaný existující PDF archiv.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->connected) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS supplier');
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS users');
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS purchase_invoices');
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS invoice_pdfs');
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS invoice_attachments');
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS invoices');
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS email_profiles');
            if ($this->recordsTable !== '') {
                $pdo->exec(
                    'DROP TABLE IF EXISTS `' . $this->recordsTable . '`',
                );
            }
            $this->db->close();
        }
        if ($this->root !== '') {
            $this->removeTree($this->root);
        }
    }

    public function testRestoresEncryptedCurrentArchiveWithCollidingIds(): void
    {
        $pdo = $this->db->pdo();
        $registry = $this->registry();
        $archive = $this->archive($registry);
        $limits = $this->limits();
        $inspection = (new CompanyBackupArchiveInspector(
            new CompanyBackupFormat([
                CompanyBackupSecretEnvelopeDescriptor::CAPABILITY,
            ]),
            BackupUpcasterRegistry::empty(),
            $limits,
        ))->inspect(
            $archive,
            self::PASSWORD,
            self::APP_VERSION,
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
        self::assertArrayHasKey('CTI-MNE.txt', $inspection->entryHashes);
        self::assertArrayNotHasKey('README.txt', $inspection->entryHashes);
        $validation = new CompanyBackupTechnicalValidation(
            $inspection,
            $registry,
            self::APP_VERSION,
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
        $preflight = (new CompanyBackupDataPreflight($limits))->inspect(
            $archive,
            self::PASSWORD,
            $validation,
            $pdo,
        );
        self::assertSame(8, $preflight->rowCount);
        self::assertSame(8, $preflight->identityCount);
        self::assertSame([], $preflight->externalReferences->requirements);
        $decisions = CompanyBackupReferenceDecisionPlan::fromArray([
            'format' => CompanyBackupReferenceDecisionPlan::FORMAT,
            'version' => CompanyBackupReferenceDecisionPlan::VERSION,
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'decisions' => [],
        ], $preflight, $registry, self::INSTANCE_ID, 91);
        $coordinator = new CompanyBackupRestoreCoordinator(
            $pdo,
            importer: new CompanyBackupDatabaseImporter(
                $pdo,
                new CurrentRoundTripSchemaSource(),
                $limits,
            ),
            postImport: new CompanyBackupRegistryPostImportValidator(
                new CurrentRoundTripRowSource(),
            ),
            stager: new CompanyBackupFileRestoreStager(
                new CurrentRoundTripStagingRootResolver(
                    $this->root . DIRECTORY_SEPARATOR . 'staging',
                ),
            ),
            publisher: new CompanyBackupFilePublisher(
                new CurrentRoundTripFileAreaRootResolver(
                    $this->root . DIRECTORY_SEPARATOR . 'live',
                ),
            ),
        );

        $result = (new CompanyBackupRestoreService(
            $coordinator,
            $limits,
        ))->restore(
            $archive,
            self::PASSWORD,
            $validation,
            $preflight,
            $decisions,
            $this->sensitiveData(),
        );

        self::assertFalse($pdo->inTransaction());
        self::assertSame(8, $result->database->supplierId);
        self::assertSame(8, $result->database->insertedRows);
        self::assertSame(3, $result->database->deferredRows);
        self::assertSame(2, $result->database->updatedRows);
        self::assertSame(7, $result->postImport->checkedTableCount);
        self::assertSame(8, $result->postImport->checkedTenantRows);
        self::assertSame(3,
            $result->postImport->invariantReport->invariantCount);
        self::assertSame(0, $result->postImport->invariantReport->checkCount);
        self::assertSame(6, $result->publishedFileCount);

        self::assertSame([
            [
                'id' => 7,
                'company_name' => 'Existing tenant',
                'logo_path' => null,
            ],
            [
                'id' => 8,
                'company_name' => 'Restored tenant',
                'logo_path' => 'storage/supplier-logos/sup-8.png',
            ],
        ], $this->supplierRows($pdo));
        self::assertSame([
            [
                'id' => 31,
                'supplier_id' => 7,
                'parent_id' => null,
                'code' => 'existing-parent',
                'updated_at' => '2020-01-01 12:00:00',
            ],
            [
                'id' => 32,
                'supplier_id' => 7,
                'parent_id' => 31,
                'code' => 'existing-child',
                'updated_at' => '2020-01-01 12:00:00',
            ],
            [
                'id' => 33,
                'supplier_id' => 8,
                'parent_id' => null,
                'code' => 'restored-parent',
                'updated_at' => '2021-01-01 12:00:00',
            ],
            [
                'id' => 34,
                'supplier_id' => 8,
                'parent_id' => 33,
                'code' => 'restored-child',
                'updated_at' => '2022-01-01 12:00:00',
            ],
        ], $this->recordRows($pdo));
        self::assertSame([
            ['id' => 101, 'supplier_id' => 7,
                'invoice_number' => 'existing-invoice',
                'imported_pdf_path' => 'supplier-7/ab/abcdef0123456789.pdf',
                'pdf_path' => 'sup-7/rendered.pdf', 'pdf_generated_at' => '2025-01-01 10:00:00'],
            ['id' => 102, 'supplier_id' => 8,
                'invoice_number' => 'restored-invoice',
                'imported_pdf_path' => 'supplier-8/ab/abcdef0123456789.pdf',
                'pdf_path' => null, 'pdf_generated_at' => null],
        ], $this->invoiceRows($pdo));
        self::assertSame([
            ['id' => 11, 'invoice_id' => 101, 'filename' => 'same.pdf'],
            ['id' => 12, 'invoice_id' => 102, 'filename' => 'same.pdf'],
        ], $this->attachmentRows($pdo));
        $restoredPdfFilename = '20250131-120000-aaaaaaaa-invoice.pdf';
        self::assertSame([
            [
                'id' => 31,
                'invoice_id' => 101,
                'filename' => $restoredPdfFilename,
                'size_bytes' => strlen("existing-tenant-archived-pdf\n"),
                'sha256' => hash('sha256', "existing-tenant-archived-pdf\n"),
                'was_sent' => 1,
                'sent_to' => '["existing@example.test"]',
                'reason' => 'sent',
                'archived_at' => '2025-01-31 12:00:00',
            ],
            [
                'id' => 32,
                'invoice_id' => 102,
                'filename' => $restoredPdfFilename,
                'size_bytes' => strlen("synthetic-round-trip-archived-pdf\n"),
                'sha256' => hash('sha256', "synthetic-round-trip-archived-pdf\n"),
                'was_sent' => 1,
                'sent_to' => '["recipient@example.test"]',
                'reason' => 'sent',
                'archived_at' => '2025-01-31 12:00:00',
            ],
        ], $this->pdfRows($pdo));
        self::assertSame([
            ['id' => 101, 'supplier_snapshot' => null],
            ['id' => 102, 'supplier_snapshot' => self::invoiceSupplierSnapshot(8, 12)],
        ], $this->fetchRows($pdo, 'SELECT id,supplier_snapshot FROM invoices ORDER BY id'));
        self::assertSame([
            ['id' => 101, 'client_snapshot' => null, 'bank_snapshot' => null],
            ['id' => 102, 'client_snapshot' => self::invoiceClientSnapshot(),
                'bank_snapshot' => self::invoiceBankSnapshot()],
        ], $this->fetchRows($pdo, 'SELECT id,client_snapshot,bank_snapshot FROM invoices ORDER BY id'));
        self::assertSame([
            ['id' => 11, 'supplier_id' => 7], ['id' => 12, 'supplier_id' => 8],
        ], $this->fetchRows($pdo, 'SELECT id,supplier_id FROM email_profiles ORDER BY id'));
        $restoredLogo = $this->root . DIRECTORY_SEPARATOR . 'live'
            . DIRECTORY_SEPARATOR . 'supplier-logos'
            . DIRECTORY_SEPARATOR . 'sup-8.png';
        self::assertFileExists($restoredLogo);
        self::assertSame(
            "synthetic-round-trip-logo\n",
            file_get_contents($restoredLogo),
        );
        $restoredAttachment = $this->root . DIRECTORY_SEPARATOR . 'live'
            . DIRECTORY_SEPARATOR . 'invoices'
            . DIRECTORY_SEPARATOR . 'sup-8'
            . DIRECTORY_SEPARATOR . 'attachments'
            . DIRECTORY_SEPARATOR . '102'
            . DIRECTORY_SEPARATOR . 'same.pdf';
        self::assertFileExists($restoredAttachment);
        self::assertSame(
            "synthetic-round-trip-attachment\n",
            file_get_contents($restoredAttachment),
        );
        $existingAttachment = $this->root . DIRECTORY_SEPARATOR . 'live'
            . DIRECTORY_SEPARATOR . 'invoices'
            . DIRECTORY_SEPARATOR . 'sup-7'
            . DIRECTORY_SEPARATOR . 'attachments'
            . DIRECTORY_SEPARATOR . '101'
            . DIRECTORY_SEPARATOR . 'same.pdf';
        self::assertSame(
            "existing-tenant-attachment\n",
            file_get_contents($existingAttachment),
        );
        $restoredPdf = $this->root . DIRECTORY_SEPARATOR . 'live'
            . DIRECTORY_SEPARATOR . 'invoices' . DIRECTORY_SEPARATOR . 'sup-8'
            . DIRECTORY_SEPARATOR . '_archive' . DIRECTORY_SEPARATOR . '2025-01'
            . DIRECTORY_SEPARATOR . $restoredPdfFilename;
        self::assertFileExists($restoredPdf);
        self::assertSame(
            "synthetic-round-trip-archived-pdf\n",
            file_get_contents($restoredPdf),
        );
        $existingPdf = $this->root . DIRECTORY_SEPARATOR . 'live'
            . DIRECTORY_SEPARATOR . 'invoices' . DIRECTORY_SEPARATOR . 'sup-7'
            . DIRECTORY_SEPARATOR . '_archive'
            . DIRECTORY_SEPARATOR . $restoredPdfFilename;
        self::assertSame(
            "existing-tenant-archived-pdf\n",
            file_get_contents($existingPdf),
        );
        $importedRoot = $this->root . '/live/custom-issued-archive';
        self::assertSame("synthetic-imported-pdf\n", file_get_contents(
            $importedRoot . '/supplier-8/ab/abcdef0123456789.pdf',
        ));
        self::assertSame("existing-imported-tenant7\n", file_get_contents(
            $importedRoot . '/supplier-7/ab/abcdef0123456789.pdf',
        ));
        self::assertDirectoryDoesNotExist($this->root . '/live/invoices-imported');
        self::assertSame('original', file_get_contents($this->root . '/live/invoices/sup-7/rendered.pdf'));
        self::assertFileDoesNotExist($this->root . '/live/invoices/sup-8/rendered.pdf');

        self::assertSame([
            ['id' => 201, 'supplier_id' => 7,
                'pdf_path' => 'supplier-7/1234567890abcdef.pdf', 'pdf_hash' => null],
            ['id' => 202, 'supplier_id' => 8,
                'pdf_path' => 'supplier-8/1234567890abcdef.pdf',
                'pdf_hash' => str_repeat('b', 64)],
        ], $this->fetchRows($pdo, 'SELECT id,supplier_id,pdf_path,pdf_hash FROM purchase_invoices ORDER BY id'));
        self::assertSame([
            ['id' => 201, 'vendor_snapshot' => '{}', 'own_snapshot' => null],
            ['id' => 202, 'vendor_snapshot' => self::purchaseVendorSnapshot(),
                'own_snapshot' => self::purchaseOwnSnapshot()],
        ], $this->fetchRows($pdo, 'SELECT id,vendor_snapshot,own_snapshot'
            . ' FROM purchase_invoices ORDER BY id'));
        self::assertSame("synthetic-purchase-pdf\n", file_get_contents(
            $this->root . '/live/custom-purchase-archive/supplier-8/1234567890abcdef.pdf',
        ));
        self::assertSame("existing-purchase-tenant7\n", file_get_contents(
            $this->root . '/live/custom-purchase-archive/supplier-7/1234567890abcdef.pdf',
        ));
        self::assertDirectoryDoesNotExist($this->root . '/live/purchase-invoices');
        self::assertSame([
            ['id' => 201, 'source_path' => self::purchaseSourcePath(7),
                'source_hash' => hash('sha256', 'original'), 'source_format' => 'isdocx',
                'source_size_bytes' => 8, 'source_original_name' => 'existing.isdocx'],
            ['id' => 202, 'source_path' => self::purchaseSourcePath(8),
                'source_hash' => hash('sha256', self::purchaseSourceBytes()), 'source_format' => 'isdocx',
                'source_size_bytes' => strlen(self::purchaseSourceBytes()),
                'source_original_name' => 'synthetic-origin.isdocx'],
        ], $this->fetchRows($pdo, 'SELECT id,source_path,source_hash,source_format,source_size_bytes,'
            . 'source_original_name FROM purchase_invoices ORDER BY id'));
        self::assertSame(self::purchaseSourceBytes(), file_get_contents(
            $this->root . '/live/custom-purchase-archive/' . self::purchaseSourcePath(8),
        ));
        self::assertSame('original', file_get_contents(
            $this->root . '/live/custom-purchase-archive/' . self::purchaseSourcePath(7),
        ));


        self::assertSame([], $this->entries(
            $this->root . DIRECTORY_SEPARATOR . 'staging',
        ));
    }

    private static function invoiceClientSnapshot(): string
    {
        return ' { "company_name":"Historical customer", "zip":"00123",'
            . ' "main_email":"historical@example.test", "id":999,'
            . ' "legacy":{"code":"0007","amount":1.2300} } ';
    }

    private static function invoiceBankSnapshot(): string
    {
        return '{ "currency":"CZK", "account_number":"1000000005",'
            . ' "bank_code":"0100", "iban":"CZ1801000000001000000005",'
            . ' "bank_name":"Synthetic historical bank", "id":888 }';
    }

    private static function invoiceSupplierSnapshot(int $supplierId, int $emailProfileId): string
    {
        return ' { "id" : ' . $supplierId . ', "company_name":"Synthetic archive",'
            . ' "email_profile_id":' . $emailProfileId . ', "branding_profile_id":999,'
            . ' "branding_profile_name":"Historical brand", "zip":"00123",'
            . ' "logo_path":"storage/supplier-logos/sup-' . $supplierId . '.png" } ';
    }

    private static function purchaseVendorSnapshot(): string
    {
        // Historical contact ID need not exist in the current tenant graph.
        return ' { "id" : 999, "company_name":"Historical vendor",'
            . ' "zip":"00123", "legacy":{"amount":123.4500,"id":888} } ';
    }

    private static function purchaseOwnSnapshot(): string
    {
        // Opaque historical payload: even the original tenant marker stays intact.
        return '{ "id":7, "company_name":"Historical owner", "legacy":[1.2300,null] }';
    }

    private static function purchaseSourceBytes(): string
    {
        // Opaque binary fixture: backup must not parse or rewrite the source container.
        return "PK\x03\x04synthetic-origin\x00\xff\r\n";
    }

    private static function purchaseSourcePath(int $supplierId): string
    {
        $hash = hash('sha256', self::purchaseSourceBytes());
        return 'sources/supplier-' . $supplierId . '/' . substr($hash, 0, 2)
            . '/' . substr($hash, 0, 16) . '.isdocx';
    }

    private function archive(TenantDataRegistrySnapshot $registry): string
    {
        $sourceRows = [
            'table:supplier' => [[
                'id' => 7,
                'company_name' => 'Restored tenant',
                'logo_path' => 'storage/supplier-logos/sup-7.png',
            ]],
            'table:email_profiles' => [['id' => 11, 'supplier_id' => 7]],
            'table:invoices' => [[
                'supplier_snapshot' => self::invoiceSupplierSnapshot(7, 11),
                'client_snapshot' => self::invoiceClientSnapshot(),
                'bank_snapshot' => self::invoiceBankSnapshot(),
                'id' => 101,
                'supplier_id' => 7,
                'invoice_number' => 'restored-invoice',
                'imported_pdf_path' => 'supplier-7/ab/abcdef0123456789.pdf',
                'pdf_path' => 'sup-7/rendered.pdf',
                'pdf_generated_at' => '2025-01-01 10:00:00',
            ]],
            'table:purchase_invoices' => [[
                'id' => 201, 'supplier_id' => 7,
                'vendor_snapshot' => self::purchaseVendorSnapshot(),
                'own_snapshot' => self::purchaseOwnSnapshot(),
                'pdf_path' => 'supplier-7/1234567890abcdef.pdf',
                'source_path' => self::purchaseSourcePath(7),
                'source_hash' => hash('sha256', self::purchaseSourceBytes()),
                'source_format' => 'isdocx',
                'source_size_bytes' => strlen(self::purchaseSourceBytes()),
                'source_original_name' => 'synthetic-origin.isdocx',
                // pdf_hash may identify an ISDOCX container, not these PDF bytes.
                'pdf_hash' => str_repeat('b', 64),
            ]],
            'table:invoice_attachments' => [[
                'id' => 11,
                'invoice_id' => 101,
                'filename' => 'same.pdf',
            ]],
            'table:invoice_pdfs' => [[
                'id' => 31,
                'invoice_id' => 101,
                'filename' => '20250131-120000-aaaaaaaa-invoice.pdf',
                'size_bytes' => strlen("synthetic-round-trip-archived-pdf\n"),
                'sha256' => hash('sha256', "synthetic-round-trip-archived-pdf\n"),
                'was_sent' => 1,
                'sent_to' => '["recipient@example.test"]',
                'reason' => 'sent',
                'archived_at' => '2025-01-31 12:00:00',
            ]],
            'table:' . $this->recordsTable => [[
                'id' => 31,
                'supplier_id' => 7,
                'parent_id' => null,
                'code' => 'restored-parent',
                'updated_at' => '2021-01-01 12:00:00',
            ], [
                'id' => 32,
                'supplier_id' => 7,
                'parent_id' => 31,
                'code' => 'restored-child',
                'updated_at' => '2022-01-01 12:00:00',
            ]],
        ];
        $objects = [];
        $sourceFiles = [];
        $temporaryFiles = [];
        $writer = new CompanyBackupJsonlWriter($this->limits());
        foreach (CompanyBackupDataInventory::payloadDefinitions($registry) as $index => $definition) {
            $path = $this->root . DIRECTORY_SEPARATOR
                . 'data-' . ($index + 1) . '.jsonl';
            $object = $writer->write(
                $definition,
                $index + 1,
                $sourceRows[$definition->key],
                $path,
            );
            $objects[] = $object;
            $sourceFiles[$object->path] = $path;
            $temporaryFiles[$object->path] = $path;
        }
        $inventory = CompanyBackupDataInventory::fromObjects(
            $objects,
            $registry,
        );
        $logoContents = "synthetic-round-trip-logo\n";
        $logoPath = $this->root . DIRECTORY_SEPARATOR . 'source-logo.png';
        if (file_put_contents($logoPath, $logoContents) !== strlen($logoContents)) {
            throw new \RuntimeException('Nelze zapsat syntetické logo.');
        }
        $logoHash = hash('sha256', $logoContents);
        $logoArchivePath = 'files/supplier-logos/' . $logoHash . '.png';
        $attachmentContents = "synthetic-round-trip-attachment\n";
        $attachmentPath = $this->root . DIRECTORY_SEPARATOR
            . 'source-attachment.pdf';
        if (file_put_contents($attachmentPath, $attachmentContents)
            !== strlen($attachmentContents)
        ) {
            throw new \RuntimeException('Nelze zapsat syntetickou přílohu.');
        }
        $attachmentHash = hash('sha256', $attachmentContents);
        $attachmentArchivePath = 'files/invoice-attachments/'
            . $attachmentHash . '.pdf';
        $pdfContents = "synthetic-round-trip-archived-pdf\n";
        $pdfPath = $this->root . DIRECTORY_SEPARATOR . 'source-archived-pdf.pdf';
        if (file_put_contents($pdfPath, $pdfContents) !== strlen($pdfContents)) {
            throw new \RuntimeException('Nelze zapsat syntetický PDF archiv.');
        }
        $pdfHash = hash('sha256', $pdfContents);
        $pdfArchivePath = 'files/invoice-pdfs/' . $pdfHash . '.pdf';
        $importedContents = "synthetic-imported-pdf\n";
        $importedPath = $this->root . DIRECTORY_SEPARATOR . 'source-imported.pdf';
        self::assertSame(strlen($importedContents), file_put_contents($importedPath, $importedContents));
        $importedHash = hash('sha256', $importedContents);
        $importedArchivePath = 'files/invoice-imported-pdfs/' . $importedHash . '.pdf';
        $purchaseContents = "synthetic-purchase-pdf\n";
        $purchasePath = $this->root . DIRECTORY_SEPARATOR . 'source-purchase.pdf';
        self::assertSame(strlen($purchaseContents), file_put_contents($purchasePath, $purchaseContents));
        $purchaseHash = hash('sha256', $purchaseContents);
        $purchaseArchivePath = 'files/purchase-invoice-pdfs/' . $purchaseHash . '.pdf';
        $purchaseSourceFile = $this->root . DIRECTORY_SEPARATOR . 'source-original.isdocx';
        self::assertSame(strlen(self::purchaseSourceBytes()), file_put_contents($purchaseSourceFile, self::purchaseSourceBytes()));
        $purchaseSourceHash = hash('sha256', self::purchaseSourceBytes());
        $purchaseSourceArchivePath = 'files/purchase-invoice-sources/' . $purchaseSourceHash . '.isdocx';
        $fileInventory = CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [[
                'registry_key' => 'file-area:invoice-attachments',
                'order' => 1,
                'entries' => [[
                    'source_path' => 'sup-7/attachments/101/same.pdf',
                    'archive_path' => $attachmentArchivePath,
                    'state' => 'present',
                    'bytes' => strlen($attachmentContents),
                    'sha256' => $attachmentHash,
                    'owners' => [[
                        'registry_key' => 'table:invoice_attachments',
                        'primary_key' => ['id' => 11],
                        'column' => 'filename',
                        'path' => [],
                    ]],
                ]],
            ], [
                'registry_key' => 'file-area:invoice-imported-pdfs',
                'order' => 2,
                'entries' => [[
                    'source_path' => 'supplier-7/ab/abcdef0123456789.pdf',
                    'archive_path' => $importedArchivePath,
                    'state' => 'present',
                    'bytes' => strlen($importedContents),
                    'sha256' => $importedHash,
                    'owners' => [[
                        'registry_key' => 'table:invoices',
                        'primary_key' => ['id' => 101],
                        'column' => 'imported_pdf_path',
                        'path' => [],
                    ]],
                ]],
            ], [
                'registry_key' => 'file-area:invoice-pdfs',
                'order' => 3,
                'entries' => [[
                    'source_path' => 'sup-7/_archive/'
                        . '20250131-120000-aaaaaaaa-invoice.pdf',
                    'archive_path' => $pdfArchivePath,
                    'state' => 'present',
                    'bytes' => strlen($pdfContents),
                    'sha256' => $pdfHash,
                    'owners' => [[
                        'registry_key' => 'table:invoice_pdfs',
                        'primary_key' => ['id' => 31],
                        'column' => 'filename',
                        'path' => [],
                    ]],
                ]],
            ], [
                'registry_key' => 'file-area:purchase-invoice-pdfs',
                'order' => 4,
                'entries' => [[
                    'source_path' => 'supplier-7/1234567890abcdef.pdf',
                    'archive_path' => $purchaseArchivePath,
                    'state' => 'present',
                    'bytes' => strlen($purchaseContents),
                    'sha256' => $purchaseHash,
                    'owners' => [[
                        'registry_key' => 'table:purchase_invoices',
                        'primary_key' => ['id' => 201],
                        'column' => 'pdf_path',
                        'path' => [],
                    ]],
                ]],
            ], [
                'registry_key' => 'file-area:purchase-invoice-sources',
                'order' => 5,
                'entries' => [[
                    'source_path' => self::purchaseSourcePath(7),
                    'archive_path' => $purchaseSourceArchivePath,
                    'state' => 'present',
                    'bytes' => strlen(self::purchaseSourceBytes()),
                    'sha256' => $purchaseSourceHash,
                    'owners' => [[
                        'registry_key' => 'table:purchase_invoices',
                        'primary_key' => ['id' => 201],
                        'column' => 'source_path', 'path' => [],
                    ]],
                ]],
            ], [
                'registry_key' => 'file-area:supplier-logos',
                'order' => 6,
                'entries' => [[
                    'source_path' => 'sup-7.png',
                    'archive_path' => $logoArchivePath,
                    'state' => 'present',
                    'bytes' => strlen($logoContents),
                    'sha256' => $logoHash,
                    'owners' => [[
                        'registry_key' => 'table:invoices',
                        'primary_key' => ['id' => 101],
                        'column' => 'supplier_snapshot',
                        'path' => ['logo_path'],
                    ], [
                        'registry_key' => 'table:supplier',
                        'primary_key' => ['id' => 7],
                        'column' => 'logo_path',
                        'path' => [],
                    ]],
                ]],
            ]],
        ], $registry);
        $sourceFiles[$attachmentArchivePath] = $attachmentPath;
        $sourceFiles[$importedArchivePath] = $importedPath;
        $sourceFiles[$pdfArchivePath] = $pdfPath;
        $sourceFiles[$purchaseArchivePath] = $purchasePath;
        $sourceFiles[$purchaseSourceArchivePath] = $purchaseSourceFile;
        $sourceFiles[$logoArchivePath] = $logoPath;
        $snapshot = new CompanyBackupMachineSnapshot(
            7,
            self::BACKUP_ID,
            $registry,
            $inventory,
            $fileInventory,
            CompanyBackupSecretInventory::fromCounts([], $registry),
            null,
            $sourceFiles,
            $temporaryFiles,
        );
        $archive = $this->root . DIRECTORY_SEPARATOR . 'company-backup.zip';
        (new CompanyBackupMachineArchiveWriter($this->limits()))->write(
            $snapshot,
            $archive,
            self::PASSWORD,
            self::APP_VERSION,
            "Syntetická přenositelná záloha.\n",
        );
        return $archive;
    }

    private function registry(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                $this->tableDefinition(
                    'table:invoice_pdfs',
                    TenantDataPolicy::TenantOwnedIndirect,
                    [
                        'id', 'invoice_id', 'filename', 'size_bytes', 'sha256',
                        'was_sent', 'sent_to', 'reason', 'archived_at',
                    ],
                    [
                        'strategy' => 'foreign_key_path',
                        'path' => [[
                            'from_column' => 'invoice_id',
                            'to_table' => 'invoices',
                            'to_column' => 'id',
                        ], [
                            'from_column' => 'supplier_id',
                            'to_table' => 'supplier',
                            'to_column' => 'id',
                        ]],
                    ],
                    [$this->reference('invoice_id', 'table:invoices')],
                ),
                new TenantDataDefinition(
                    'file-area:invoice-pdfs',
                    TenantDataObjectKind::FileArea,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    [
                        'file_policy' => 'historical_optional',
                        'path_policy' => 'supplier_invoice_pdf',
                        'file_owners' => [[
                            'registry_key' => 'table:invoice_pdfs',
                            'column' => 'filename',
                            'path' => [],
                            'stored_prefix' => '',
                        ]],
                        'ownership' => ['strategy' => 'database_references'],
                        'storage_subdirectory' => 'invoices',
                    ],
                ),
                $this->tableDefinition(
                    'table:invoice_attachments',
                    TenantDataPolicy::TenantOwnedIndirect,
                    ['id', 'invoice_id', 'filename'],
                    [
                        'strategy' => 'foreign_key_path',
                        'path' => [[
                            'from_column' => 'invoice_id',
                            'to_table' => 'invoices',
                            'to_column' => 'id',
                        ], [
                            'from_column' => 'supplier_id',
                            'to_table' => 'supplier',
                            'to_column' => 'id',
                        ]],
                    ],
                    [$this->reference('invoice_id', 'table:invoices')],
                ),
                new TenantDataDefinition(
                    'file-area:invoice-attachments',
                    TenantDataObjectKind::FileArea,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    [
                        'file_policy' => 'historical_optional',
                        'path_policy' => 'supplier_invoice_attachment',
                        'file_owners' => [[
                            'registry_key' => 'table:invoice_attachments',
                            'column' => 'filename',
                            'path' => [],
                            'stored_prefix' => '',
                        ]],
                        'ownership' => ['strategy' => 'database_references'],
                        'storage_subdirectory' => 'invoices',
                    ],
                ),
                new TenantDataDefinition(
                    'file-area:invoice-imported-pdfs',
                    TenantDataObjectKind::FileArea,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    [
                        'file_policy' => 'historical_optional',
                        'path_policy' => 'supplier_imported_invoice_pdf',
                        'storage_subdirectory' => 'invoices-imported',
                        'ownership' => ['strategy' => 'database_references'],
                        'file_owners' => [[
                            'registry_key' => 'table:invoices',
                            'column' => 'imported_pdf_path',
                            'path' => [],
                            'stored_prefix' => '',
                        ]],
                    ],
                ),
                $this->tableDefinition(
                    'table:purchase_invoices', TenantDataPolicy::TenantOwned,
                    ['id', 'supplier_id', 'pdf_path', 'pdf_hash', 'source_path',
                        'source_hash', 'source_format', 'source_size_bytes', 'source_original_name',
                        'vendor_snapshot', 'own_snapshot'],
                    ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                    [$this->reference('supplier_id', 'table:supplier')],
                    embeddedReferences: \MyInvoice\Service\Backup\Company\CompanyBackupPurchaseInvoicesProjection::embeddedReferences(),
                ),
                new TenantDataDefinition(
                    'file-area:purchase-invoice-pdfs', TenantDataObjectKind::FileArea,
                    TenantDataPolicy::TenantOwned, [$profile], [
                        'file_policy' => 'historical_optional',
                        'path_policy' => 'supplier_purchase_invoice_pdf',
                        'storage_subdirectory' => 'purchase-invoices',
                        'ownership' => ['strategy' => 'database_references'],
                        'file_owners' => [[
                            'registry_key' => 'table:purchase_invoices',
                            'column' => 'pdf_path', 'path' => [], 'stored_prefix' => '',
                        ]],
                    ],
                ),
                new TenantDataDefinition(
                    'file-area:purchase-invoice-sources', TenantDataObjectKind::FileArea,
                    TenantDataPolicy::TenantOwned, [$profile], [
                        'file_policy' => 'historical_optional',
                        'path_policy' => 'supplier_purchase_invoice_source',
                        'storage_subdirectory' => 'purchase-invoices',
                        'ownership' => ['strategy' => 'database_references'],
                        'file_owners' => [[
                            'registry_key' => 'table:purchase_invoices',
                            'column' => 'source_path', 'path' => [], 'stored_prefix' => '',
                        ]],
                    ],
                ),
                $this->tableDefinition(
                    'table:invoices',
                    TenantDataPolicy::TenantOwned,
                    ['id', 'supplier_id', 'invoice_number', 'imported_pdf_path', 'pdf_path', 'pdf_generated_at', 'supplier_snapshot',
                        'client_snapshot', 'bank_snapshot'],
                    ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                    [$this->reference('supplier_id', 'table:supplier')],
                    \MyInvoice\Service\Backup\Company\CompanyBackupInvoicesProjection::restoreOverrides(),
                    \MyInvoice\Service\Backup\Company\CompanyBackupInvoicesProjection::embeddedReferences(),
                ),
                $this->tableDefinition(
                    'table:email_profiles', TenantDataPolicy::TenantOwned,
                    ['id', 'supplier_id'],
                    ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                    [$this->reference('supplier_id', 'table:supplier')],
                ),
                $this->tableDefinition(
                    'table:' . $this->recordsTable,
                    TenantDataPolicy::TenantOwned,
                    ['id', 'supplier_id', 'parent_id', 'code', 'updated_at'],
                    [
                        'strategy' => 'supplier_id',
                        'column' => 'supplier_id',
                    ],
                    [
                        $this->reference(
                            'parent_id',
                            'table:' . $this->recordsTable,
                            true,
                        ),
                        $this->reference('supplier_id', 'table:supplier'),
                    ],
                ),
                new TenantDataDefinition(
                    'file-area:supplier-logos',
                    TenantDataObjectKind::FileArea,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    [
                        'file_policy' => 'historical_optional',
                        'path_policy' => 'supplier_logo',
                        'file_owners' => [[
                            'registry_key' => 'table:invoices',
                            'column' => 'supplier_snapshot',
                            'path' => ['logo_path'],
                            'stored_prefix' => 'storage/supplier-logos/',
                        ], [
                            'registry_key' => 'table:supplier',
                            'column' => 'logo_path',
                            'path' => [],
                            'stored_prefix' => 'storage/supplier-logos/',
                        ]],
                        'ownership' => ['strategy' => 'database_references'],
                        'storage_subdirectory' => 'supplier-logos',
                    ],
                ),
                $this->tableDefinition(
                    'table:supplier',
                    TenantDataPolicy::TenantRoot,
                    ['id', 'company_name', 'logo_path'],
                    ['strategy' => 'selected_supplier', 'column' => 'id'],
                    [],
                ),
                new TenantDataDefinition(
                    'table:users',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::InstanceOwned,
                    [$profile],
                    [
                        'primary_key' => ['id'],
                        'ownership' => ['strategy' => 'instance'],
                    ],
                ),
            ],
            [$profile],
        ), $profile);
    }

    /**
     * @param list<string> $columns
     * @param array<string,mixed> $ownership
     * @param list<array<string,mixed>> $references
     * @param array<string,mixed> $restoreOverrides
     * @param list<array<string,mixed>> $embeddedReferences
     */
    private function tableDefinition(
        string $key,
        TenantDataPolicy $policy,
        array $columns,
        array $ownership,
        array $references,
        array $restoreOverrides = [],
        array $embeddedReferences = [],
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            $key,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => $ownership,
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => $columns,
                    'embedded_references' => $embeddedReferences,
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' => $references,
                    'restore_overrides' => $restoreOverrides,
                ],
            ],
        );
    }

    /** @return array<string,mixed> */
    private function reference(
        string $column,
        string $target,
        bool $nullable = false,
    ): array {
        return [
            'columns' => [$column],
            'target' => $target,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }

    private function limits(): CompanyBackupArchiveLimits
    {
        return new CompanyBackupArchiveLimits(
            maxArchiveBytes: 2_000_000,
            maxEntries: 30,
            maxEntryBytes: 200_000,
            maxExpandedBytes: 1_000_000,
            maxCompressionRatio: 1_000,
            maxManifestBytes: 200_000,
            maxChecksumsBytes: 20_000,
        );
    }

    private function sensitiveData(): PayrollSensitiveData
    {
        $config = new Config([
            'app' => [
                'secret_encryption_key' => base64_encode(str_repeat('s', 32)),
                'payroll_hash_key' => base64_encode(str_repeat('h', 32)),
            ],
        ]);
        return new PayrollSensitiveData(new SecretEncryption($config), $config);
    }

    /** @return list<array{id:int,company_name:string,logo_path:?string}> */
    private function supplierRows(PDO $pdo): array
    {
        $rows = $this->fetchRows(
            $pdo,
            'SELECT id, company_name, logo_path FROM supplier ORDER BY id',
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'company_name' => (string) $row['company_name'],
            'logo_path' => is_string($row['logo_path'])
                ? $row['logo_path']
                : null,
        ], $rows);
    }

    /** @return list<array{id:int,supplier_id:int,parent_id:?int,code:string,updated_at:string}> */
    private function recordRows(PDO $pdo): array
    {
        $rows = $this->fetchRows(
            $pdo,
            'SELECT id, supplier_id, parent_id, code, updated_at FROM `'
                . $this->recordsTable . '` ORDER BY id',
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'supplier_id' => (int) $row['supplier_id'],
            'parent_id' => $row['parent_id'] === null
                ? null
                : (int) $row['parent_id'],
            'code' => (string) $row['code'],
            'updated_at' => (string) $row['updated_at'],
        ], $rows);
    }

    /** @return list<array{id:int,supplier_id:int,invoice_number:string,imported_pdf_path:?string,pdf_path:?string,pdf_generated_at:?string}> */
    private function invoiceRows(PDO $pdo): array
    {
        $rows = $this->fetchRows(
            $pdo,
            'SELECT id, supplier_id, invoice_number, imported_pdf_path, pdf_path, pdf_generated_at FROM invoices ORDER BY id',
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'supplier_id' => (int) $row['supplier_id'],
            'invoice_number' => (string) $row['invoice_number'],
            'imported_pdf_path' => is_string($row['imported_pdf_path']) ? $row['imported_pdf_path'] : null,
            'pdf_path' => is_string($row['pdf_path']) ? $row['pdf_path'] : null,
            'pdf_generated_at' => is_string($row['pdf_generated_at']) ? $row['pdf_generated_at'] : null,
        ], $rows);
    }

    /** @return list<array{id:int,invoice_id:int,filename:string}> */
    private function attachmentRows(PDO $pdo): array
    {
        $rows = $this->fetchRows(
            $pdo,
            'SELECT id, invoice_id, filename FROM invoice_attachments ORDER BY id',
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'invoice_id' => (int) $row['invoice_id'],
            'filename' => (string) $row['filename'],
        ], $rows);
    }

    /** @return list<array<string,int|string|null>> */
    private function pdfRows(PDO $pdo): array
    {
        $rows = $this->fetchRows(
            $pdo,
            'SELECT id, invoice_id, filename, size_bytes, sha256, was_sent,'
                . ' sent_to, reason, archived_at FROM invoice_pdfs ORDER BY id',
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'invoice_id' => (int) $row['invoice_id'],
            'filename' => (string) $row['filename'],
            'size_bytes' => (int) $row['size_bytes'],
            'sha256' => (string) $row['sha256'],
            'was_sent' => (int) $row['was_sent'],
            'sent_to' => is_string($row['sent_to']) ? $row['sent_to'] : null,
            'reason' => (string) $row['reason'],
            'archived_at' => (string) $row['archived_at'],
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function fetchRows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        if (!$statement instanceof PDOStatement) {
            throw new \RuntimeException('Syntetický SELECT selhal.');
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!array_is_list($rows)) {
            throw new \RuntimeException('Syntetický SELECT nevrátil seznam.');
        }
        return $rows;
    }

    /** @return list<string> */
    private function entries(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $entries = scandir($directory);
        if (!is_array($entries)) {
            throw new \RuntimeException('Nelze přečíst testovací adresář.');
        }
        return array_values(array_diff($entries, ['.', '..']));
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
            $item->isDir()
                ? @rmdir($item->getPathname())
                : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}

/** @internal Přesné runtime metadata syntetických InnoDB tabulek round-tripu. */
final readonly class CurrentRoundTripSchemaSource implements
    CompanyBackupImportSchemaSource
{
    public function read(
        PDO $database,
        CompanyBackupTableProjection $projection,
    ): CompanyBackupTableSchema {
        return new CompanyBackupTableSchema(
            $projection->dataColumns,
            [],
            $projection->primaryKey,
            [],
        );
    }

    public function readImportMetadata(
        PDO $database,
        CompanyBackupTableProjection $projection,
    ): CompanyBackupImportTableMetadata {
        return new CompanyBackupImportTableMetadata(
            new CompanyBackupAutoIncrementColumn('id', PHP_INT_MAX),
        );
    }
}

/** @internal Registry replay omezený na tenantové řádky round-trip tabulek. */
final readonly class CurrentRoundTripRowSource implements CompanyBackupDataRowSource
{
    /** @return iterable<int,array<string,mixed>> */
    public function rows(
        PDO $snapshot,
        int $supplierId,
        TenantDataDefinition $definition,
    ): iterable {
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $selection = (new CompanyBackupTenantSqlSelector())->select(
            $projection, $supplierId,
        );
        $alias = CompanyBackupTenantSqlSelector::SOURCE_ALIAS;
        $columns = implode(', ', array_map(
            static fn (string $name): string => '`' . $alias . '`.`' . $name . '`',
            $projection->dataColumns,
        ));
        $order = implode(', ', array_map(
            static fn (string $name): string => '`' . $alias . '`.`' . $name . '`',
            $projection->primaryKey,
        ));
        $statement = $snapshot->prepare(
            'SELECT ' . $columns . ' FROM `' . $projection->name . '`'
                . ' AS `' . $alias . '` WHERE ' . $selection->where
                . ' ORDER BY ' . $order,
        );
        if (!$statement instanceof PDOStatement
            || !$statement->execute($selection->params)
        ) {
            throw new \RuntimeException('Round-trip registry replay selhal.');
        }
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \RuntimeException(
                    'Round-trip registry replay vrátil neplatný řádek.',
                );
            }
            yield $row;
        }
        if (!$statement->closeCursor()) {
            throw new \RuntimeException('Round-trip registry replay nelze uzavřít.');
        }
    }
}

/** @internal */
final readonly class CurrentRoundTripStagingRootResolver implements
    CompanyBackupFileStagingRootResolver
{
    public function __construct(private string $root) {}

    public function root(): string
    {
        return $this->root;
    }
}

/** @internal */
final readonly class CurrentRoundTripFileAreaRootResolver implements
    CompanyBackupFileAreaRootResolver
{
    public function __construct(private string $root) {}

    public function resolve(string $storageSubdirectory): string
    {
        if (in_array($storageSubdirectory, ['invoices-imported', 'purchase-invoices'], true)) {
            return (new \MyInvoice\Service\Backup\Company\CompanyBackupConfiguredFileAreaRootResolver(
                new \MyInvoice\Infrastructure\Config\Config([
                    'invoice' => ['import_archive_storage' => $this->root . '/custom-issued-archive'],
                    'purchase_invoice' => ['archive_storage' => $this->root . '/custom-purchase-archive'],
                ]),
            ))->resolve($storageSubdirectory);
        }
        return $this->root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $storageSubdirectory);
    }
}
