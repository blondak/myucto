<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupFileAreaProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupFilePathPolicy;
use MyInvoice\Service\Backup\Company\CompanyBackupFileSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlFileReferenceSource;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupFileAreaProjectionTest extends TestCase
{
    public function testInvoiceAttachmentAreaRequiresOneExactOwnerAndDirectInvoiceBinding(): void
    {
        $registry = $this->registry();
        $definition = $registry->definition('file-area:invoice-attachments');
        self::assertNotNull($definition);
        $area = CompanyBackupFileAreaProjection::fromDefinition(
            $definition, $registry,
        );

        self::assertSame('invoices', $area->storageSubdirectory);
        self::assertSame(
            CompanyBackupFilePathPolicy::SupplierInvoiceAttachment,
            $area->pathPolicy,
        );
        self::assertCount(1, $area->owners->owners);
        self::assertSame(
            'table:invoice_attachments',
            $area->owners->owners[0]->registryKey,
        );
        self::assertSame('filename', $area->owners->owners[0]->column);
        self::assertSame([], $area->owners->owners[0]->path);
        self::assertSame('', $area->owners->owners[0]->storedPrefix);
    }

    public function testInvoiceAttachmentAreaRejectsContractSwapsBeforeSqlSource(): void
    {
        $reference = $this->invoiceReference();
        $ownership = $this->ownership();
        $owner = $this->owner();
        $cases = [
            [['storage_subdirectory' => 'documents'], []],
            [['file_owners' => [$owner,
                ['registry_key' => 'table:invoice_attachments',
                    'column' => 'invoice_id', 'path' => [],
                    'stored_prefix' => '']]], []],
            [['file_owners' => [[...$owner, 'column' => 'invoice_id']]], []],
            [['file_owners' => [[...$owner, 'path' => ['inner']]]], []],
            [['file_owners' => [[...$owner,
                'stored_prefix' => 'storage/invoices/']]], []],
            [[], ['primary_key' => ['invoice_id']]],
            [[], ['ownership' => ['strategy' => 'supplier_id',
                'column' => 'supplier_id']]],
            [[], ['ownership' => ['strategy' => 'foreign_key_path',
                'path' => [$ownership['path'][0]]]]],
            [[], ['company_backup' => $this->projection(
                ['id', 'filename'], [$reference],
            )]],
            [[], ['company_backup' => $this->projection(
                ['id', 'invoice_id', 'filename'],
                [[...$reference, 'constraint' =>
                    CompanyBackupReferenceConstraint::Optional->value]],
            )]],
            [[], ['company_backup' => $this->projection(
                ['id', 'invoice_id', 'filename'],
                [[...$reference, 'nullable_columns' => ['invoice_id']]],
            )]],
            [[], ['company_backup' => $this->projection(
                ['id', 'invoice_id', 'filename'],
                [[...$reference, 'target' => 'table:purchase_invoices']],
            )]],
        ];
        foreach ($cases as [$areaChanges, $tableChanges]) {
            $registry = $this->registry($areaChanges, $tableChanges);
            $definition = $registry->definition('file-area:invoice-attachments');
            self::assertNotNull($definition);
            $pdo = $this->createMock(PDO::class);
            $pdo->expects(self::never())->method('prepare');
            try {
                iterator_to_array(
                    (new CompanyBackupSqlFileReferenceSource())->references(
                        $pdo, 7, $definition, $registry,
                    ),
                );
                self::fail('Záměna vlastnictví přílohy nesmí vytvořit file area.');
            } catch (CompanyBackupFileSourceException $e) {
                self::assertSame('file_area_metadata_invalid', $e->errorCode);
                self::assertSame('file-area:invoice-attachments', $e->registryKey);
                self::assertNull($e->sourcePath);
            }
        }
    }

    public function testInvoicePdfAreaRequiresExactOwnerAndDirectInvoiceBinding(): void
    {
        $registry = $this->registry(
            tableName: 'invoice_pdfs',
            areaName: 'invoice-pdfs',
            pathPolicy: 'supplier_invoice_pdf',
        );
        $definition = $registry->definition('file-area:invoice-pdfs');
        self::assertNotNull($definition);

        $area = CompanyBackupFileAreaProjection::fromDefinition(
            $definition,
            $registry,
        );

        self::assertSame('invoices', $area->storageSubdirectory);
        self::assertSame(
            CompanyBackupFilePathPolicy::SupplierInvoicePdf,
            $area->pathPolicy,
        );
        self::assertCount(1, $area->owners->owners);
        self::assertSame('table:invoice_pdfs', $area->owners->owners[0]->registryKey);
        self::assertSame('filename', $area->owners->owners[0]->column);
        self::assertSame([], $area->owners->owners[0]->path);
        self::assertSame('', $area->owners->owners[0]->storedPrefix);
    }

    public function testInvoicePdfAreaRejectsOwnerSwapBeforeSqlSource(): void
    {
        $registry = $this->registry(
            areaChanges: ['file_owners' => [[
                'registry_key' => 'table:invoice_pdfs',
                'column' => 'invoice_id',
                'path' => [],
                'stored_prefix' => '',
            ]]],
            tableName: 'invoice_pdfs',
            areaName: 'invoice-pdfs',
            pathPolicy: 'supplier_invoice_pdf',
        );
        $definition = $registry->definition('file-area:invoice-pdfs');
        self::assertNotNull($definition);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('prepare');

        try {
            iterator_to_array(
                (new CompanyBackupSqlFileReferenceSource())->references(
                    $pdo, 7, $definition, $registry,
                ),
            );
            self::fail('Záměna PDF vlastníka nesmí vytvořit file area.');
        } catch (CompanyBackupFileSourceException $e) {
            self::assertSame('file_area_metadata_invalid', $e->errorCode);
            self::assertSame('file-area:invoice-pdfs', $e->registryKey);
        }
    }

    /**
     * @param array<string,mixed> $areaChanges
     * @param array<string,mixed> $tableChanges
     */
    private function registry(
        array $areaChanges = [],
        array $tableChanges = [],
        string $tableName = 'invoice_attachments',
        string $areaName = 'invoice-attachments',
        string $pathPolicy = 'supplier_invoice_attachment',
    ): TenantDataRegistry {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $tableDetails = [
            'primary_key' => ['id'],
            'ownership' => $this->ownership(),
            'secrets' => [],
            'company_backup' => $this->projection(
                ['id', 'invoice_id', 'filename'],
                [$this->invoiceReference()],
            ),
        ];
        $areaDetails = [
            'file_policy' => 'historical_optional',
            'ownership' => ['strategy' => 'database_references'],
            'path_policy' => $pathPolicy,
            'storage_subdirectory' => 'invoices',
            'file_owners' => [$this->owner('table:' . $tableName)],
        ];
        return new TenantDataRegistry(1, [
            new TenantDataDefinition(
                'table:' . $tableName,
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantOwnedIndirect,
                [$profile],
                [...$tableDetails, ...$tableChanges],
            ),
            new TenantDataDefinition(
                'file-area:' . $areaName,
                TenantDataObjectKind::FileArea,
                TenantDataPolicy::TenantOwned,
                [$profile],
                [...$areaDetails, ...$areaChanges],
            ),
        ]);
    }

    /** @return array<string,mixed> */
    private function ownership(): array
    {
        return [
            'strategy' => 'foreign_key_path',
            'path' => [
                ['from_column' => 'invoice_id', 'to_table' => 'invoices',
                    'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier',
                    'to_column' => 'id'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function owner(string $registryKey = 'table:invoice_attachments'): array
    {
        return [
            'registry_key' => $registryKey,
            'column' => 'filename',
            'path' => [],
            'stored_prefix' => '',
        ];
    }

    /** @return array<string,mixed> */
    private function invoiceReference(): array
    {
        return [
            'columns' => ['invoice_id'],
            'target' => 'table:invoices',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }

    /**
     * @param list<string> $columns
     * @param list<array<string,mixed>> $references
     * @return array<string,mixed>
     */
    private function projection(array $columns, array $references): array
    {
        return [
            'data_columns' => $columns,
            'embedded_references' => [],
            'generated_columns' => [],
            'omit_columns' => [],
            'references' => $references,
            'restore_overrides' => [],
        ];
    }
}
