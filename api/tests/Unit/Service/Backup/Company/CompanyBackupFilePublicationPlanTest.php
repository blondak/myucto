<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFilePublicationPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupFileRestoreException;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\TestCase;

final class CompanyBackupFilePublicationPlanTest extends TestCase
{
    public function testRejectsFlatAndMonthlyPdfSourcesWithSameCanonicalTarget(): void
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $ownership = [
            'strategy' => 'foreign_key_path',
            'path' => [
                ['from_column' => 'invoice_id', 'to_table' => 'invoices',
                    'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier',
                    'to_column' => 'id'],
            ],
        ];
        $registry = TenantDataRegistrySnapshot::fromRegistry(
            new TenantDataRegistry(1, [
                new TenantDataDefinition(
                    'table:invoice_pdfs',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::TenantOwnedIndirect,
                    [$profile],
                    [
                        'primary_key' => ['id'],
                        'ownership' => $ownership,
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
                                'mapping' => 'tenant_id',
                                'constraint' => 'required',
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
            ], [$profile]),
            $profile,
        );
        $filename = '20260921-142530-a1b2c3d4-invoice.pdf';
        $owner = static fn (int $id): array => [[
            'registry_key' => 'table:invoice_pdfs',
            'primary_key' => ['id' => $id],
            'column' => 'filename',
            'path' => [],
        ]];
        $entry = static fn (string $sourcePath, int $id): array => [
            'source_path' => $sourcePath,
            'archive_path' => null,
            'state' => 'missing',
            'bytes' => null,
            'sha256' => null,
            'owners' => $owner($id),
        ];
        $inventory = CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [[
                'registry_key' => 'file-area:invoice-pdfs',
                'order' => 1,
                'entries' => [
                    $entry('sup-7/_archive/2026-09/' . $filename, 12),
                    $entry('sup-7/_archive/' . $filename, 11),
                ],
            ]],
        ], $registry);

        try {
            CompanyBackupFilePublicationPlan::fromInventory(
                $inventory,
                $registry,
                7,
                41,
            );
            self::fail('Alias ploché a měsíční PDF cesty nesmí vytvořit publikační plán.');
        } catch (CompanyBackupFileRestoreException $e) {
            self::assertSame('file_restore_target_path_duplicate', $e->errorCode);
            self::assertSame('file-area:invoice-pdfs', $e->registryKey);
            self::assertNull($e->getPrevious());
        }
    }
}
