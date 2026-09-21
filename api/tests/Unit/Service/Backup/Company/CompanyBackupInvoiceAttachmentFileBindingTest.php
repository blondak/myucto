<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFilePublicationPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupFileRestoreException;
use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceAttachmentsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceAttachmentFileBinding;
use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceAttachmentPostImportValidator;
use MyInvoice\Service\Backup\Company\CompanyBackupPostImportException;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupInvoiceAttachmentFileBindingTest extends TestCase
{
    public function testCanonicalBindingAndPlanPreserveBasenameAndRebaseInvoiceId(): void
    {
        [$registry, $inventory] = $this->context([
            ['11', 7, 'ab12cd34-proof.pdf'],
            [12, 7, 'bc23de45-copy.pdf'],
        ]);
        $bindings = [
            new CompanyBackupInvoiceAttachmentFileBinding(12, 902, 7, 81),
            new CompanyBackupInvoiceAttachmentFileBinding(11, 901, 7, 81),
        ];
        $plan = CompanyBackupFilePublicationPlan::fromInventory(
            $inventory, $registry, 2, 41, $bindings,
        );
        self::assertSame([11, 12], array_map(
            static fn (CompanyBackupInvoiceAttachmentFileBinding $binding): int =>
                $binding->sourceAttachmentId,
            $plan->invoiceAttachmentBindings,
        ));
        self::assertSame([
            'sup-41/attachments/81/ab12cd34-proof.pdf',
            'sup-41/attachments/81/bc23de45-copy.pdf',
        ], array_map(static fn ($entry): string => $entry->targetPath, $plan->entries));
        self::assertSame($plan->bindingSha256,
            CompanyBackupFilePublicationPlan::fromInventory($inventory, $registry, 2, 41,
                array_reverse($bindings))->bindingSha256);
        self::assertSame([
            'source_attachment_id' => 11,
            'target_attachment_id' => 901,
            'source_invoice_id' => 7,
            'target_invoice_id' => 81,
        ], $plan->invoiceAttachmentBindings[0]->bindingValue());
        self::assertEquals($plan->invoiceAttachmentBindings[0],
            CompanyBackupInvoiceAttachmentFileBinding::fromArray(
                $plan->invoiceAttachmentBindings[0]->bindingValue(),
            ));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $plan->bindingSha256);
    }

    public function testRejectsMissingExtraDuplicateAndContradictoryBindings(): void
    {
        [$registry, $inventory] = $this->context([
            [11, 7, 'ab12cd34-proof.pdf'],
            [12, 7, 'bc23de45-copy.pdf'],
            [13, 8, 'cd34ef56-other.pdf'],
        ]);
        $good = [
            new CompanyBackupInvoiceAttachmentFileBinding(11, 901, 7, 81),
            new CompanyBackupInvoiceAttachmentFileBinding(12, 902, 7, 81),
            new CompanyBackupInvoiceAttachmentFileBinding(13, 903, 8, 82),
        ];
        foreach ([
            array_slice($good, 0, 2),
            [...$good, new CompanyBackupInvoiceAttachmentFileBinding(99, 999, 8, 82)],
            [$good[0], $good[0], $good[2]],
            [$good[0], new CompanyBackupInvoiceAttachmentFileBinding(12, 901, 7, 81), $good[2]],
            [$good[0], new CompanyBackupInvoiceAttachmentFileBinding(12, 902, 7, 83), $good[2]],
            [$good[0], $good[1], new CompanyBackupInvoiceAttachmentFileBinding(13, 903, 8, 81)],
            [$good[0], $good[1], new CompanyBackupInvoiceAttachmentFileBinding(13, 903, 9, 82)],
        ] as $bad) {
            $this->assertPlanFails($inventory, $registry, $bad);
        }
        foreach ([0, -1] as $id) {
            try {
                new CompanyBackupInvoiceAttachmentFileBinding($id, 901, 7, 81);
                self::fail('Nekladné ID nesmí vytvořit vazbu.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Vazba přílohy faktury není platná.', $e->getMessage());
            }
        }
    }

    public function testDifferentAreaKeyWithSamePolicyGetsBindingAndOtherPolicyDoesNot(): void
    {
        [$registry, $inventory] = $this->context([[11, 7, 'ab12cd34-proof.pdf']],
            'file-area:synthetic-attachments');
        $binding = new CompanyBackupInvoiceAttachmentFileBinding(11, 901, 7, 81);
        $plan = CompanyBackupFilePublicationPlan::fromInventory(
            $inventory, $registry, 2, 41, [$binding],
        );
        self::assertSame('sup-41/attachments/81/ab12cd34-proof.pdf',
            $plan->entries[0]->targetPath);
        [$otherRegistry, $otherInventory] = $this->logoContext();
        $empty = CompanyBackupFilePublicationPlan::fromInventory(
            $otherInventory, $otherRegistry, 2, 41,
        );
        self::assertSame([], $empty->invoiceAttachmentBindings);
        self::assertSame($empty->bindingSha256,
            CompanyBackupFilePublicationPlan::fromInventory($otherInventory,
                $otherRegistry, 2, 41, [])->bindingSha256);
        $this->assertPlanFails($otherInventory, $otherRegistry, [$binding]);
    }

    public function testChecksActualTargetRowBytewiseAndRequiresTenantOwnership(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro izolovaný post-import test.');
        }
        [$registry, $inventory] = $this->context([[11, 7, 'ab12cd34-proof.pdf']],
            'file-area:synthetic-attachments');
        $plan = CompanyBackupFilePublicationPlan::fromInventory($inventory, $registry,
            2, 41, [new CompanyBackupInvoiceAttachmentFileBinding(11, 901, 7, 81)]);
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL)');
        $db->exec('CREATE TABLE invoice_attachments ('
            . 'id INTEGER PRIMARY KEY, invoice_id INTEGER NOT NULL, filename TEXT NOT NULL)');
        self::assertTrue($db->beginTransaction());
        $db->exec('INSERT INTO invoices (id, supplier_id) VALUES (81, 41), (82, 41), (83, 42)');
        $db->exec("INSERT INTO invoice_attachments (id, invoice_id, filename)"
            . " VALUES (901, 81, 'ab12cd34-proof.pdf')");
        CompanyBackupInvoiceAttachmentPostImportValidator::assertValid(
            $db, $inventory, $registry, $plan, 41,
        );
        foreach ([
            [82, 'ab12cd34-proof.pdf'],
            [81, 'AB12CD34-proof.pdf'],
            [83, 'ab12cd34-proof.pdf'],
        ] as [$invoiceId, $filename]) {
            $change = $db->prepare('UPDATE invoice_attachments SET invoice_id = ?, filename = ? WHERE id = 901');
            self::assertTrue($change->execute([$invoiceId, $filename]));
            $this->assertTargetFails($db, $inventory, $registry, $plan);
        }
        $db->exec('DELETE FROM invoice_attachments WHERE id = 901');
        $this->assertTargetFails($db, $inventory, $registry, $plan);
        self::assertTrue($db->rollBack());
    }

    public function testPostImportCheckerRejectsMismatchedRegistryBeforeQuery(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro izolovaný post-import test.');
        }
        [$registry, $inventory] = $this->context([[11, 7, 'ab12cd34-proof.pdf']]);
        $plan = CompanyBackupFilePublicationPlan::fromInventory($inventory, $registry,
            2, 41, [new CompanyBackupInvoiceAttachmentFileBinding(11, 901, 7, 81)]);
        [$otherRegistry] = $this->logoContext();
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::assertTrue($db->beginTransaction());
        try {
            CompanyBackupInvoiceAttachmentPostImportValidator::assertValid(
                $db, $inventory, $otherRegistry, $plan, 41,
            );
            self::fail('Jiný registr nesmí získat SQL dotaz na přílohy.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame('post_import_attachment_context_invalid', $e->errorCode);
            self::assertNull($e->getPrevious());
        }
        self::assertTrue($db->rollBack());
    }

    public function testPostImportSqlFailureHasSafeErrorWithoutPreviousOrPath(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro izolovaný post-import test.');
        }
        [$registry, $inventory] = $this->context([[11, 7, 'ab12cd34-proof.pdf']]);
        $plan = CompanyBackupFilePublicationPlan::fromInventory($inventory, $registry,
            2, 41, [new CompanyBackupInvoiceAttachmentFileBinding(11, 901, 7, 81)]);
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::assertTrue($db->beginTransaction());
        try {
            CompanyBackupInvoiceAttachmentPostImportValidator::assertValid(
                $db, $inventory, $registry, $plan, 41,
            );
            self::fail('Chybějící tabulka musí ukončit post-import validaci.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertSame('post_import_attachment_query_failed', $e->errorCode);
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('ab12cd34-proof.pdf', (string) $e);
            self::assertStringNotContainsString('SELECT', (string) $e);
        }
        self::assertTrue($db->rollBack());
    }

    /** @param array<array-key,mixed> $bindings */
    private function assertPlanFails(
        CompanyBackupFileInventory $inventory,
        TenantDataRegistrySnapshot $registry,
        array $bindings,
    ): void {
        try {
            CompanyBackupFilePublicationPlan::fromInventory($inventory, $registry, 2, 41, $bindings);
            self::fail('Neúplné nebo rozporné vazby příloh nesmí vytvořit plán.');
        } catch (CompanyBackupFileRestoreException $e) {
            self::assertStringStartsWith('file_restore_attachment_', $e->errorCode);
            self::assertNull($e->getPrevious());
        }
    }

    private function assertTargetFails(
        PDO $db,
        CompanyBackupFileInventory $inventory,
        TenantDataRegistrySnapshot $registry,
        CompanyBackupFilePublicationPlan $plan,
    ): void {
        try {
            CompanyBackupInvoiceAttachmentPostImportValidator::assertValid(
                $db, $inventory, $registry, $plan, 41,
            );
            self::fail('Cílový řádek přílohy se nesmí lišit od publikační vazby.');
        } catch (CompanyBackupPostImportException $e) {
            self::assertStringStartsWith('post_import_attachment_', $e->errorCode);
            self::assertNull($e->getPrevious());
        }
    }

    /**
     * @param list<array{int|numeric-string,int,string}> $rows
     * @return array{TenantDataRegistrySnapshot,CompanyBackupFileInventory}
     */
    private function context(array $rows, string $areaKey = 'file-area:invoice-attachments'): array
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $ownership = ['strategy' => 'foreign_key_path', 'path' => [
            ['from_column' => 'invoice_id', 'to_table' => 'invoices', 'to_column' => 'id'],
            ['from_column' => 'supplier_id', 'to_table' => 'supplier', 'to_column' => 'id'],
        ]];
        $registry = TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(1, [
            new TenantDataDefinition('table:invoices', TenantDataObjectKind::Table,
                TenantDataPolicy::TenantOwned, [$profile], [
                    'primary_key' => ['id'],
                    'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                    'secrets' => [],
                ]),
            new TenantDataDefinition('table:invoice_attachments', TenantDataObjectKind::Table,
                TenantDataPolicy::TenantOwnedIndirect, [$profile], [
                    'primary_key' => ['id'],
                    'ownership' => $ownership,
                    'secrets' => [],
                    'company_backup' => [
                        'data_columns' => CompanyBackupInvoiceAttachmentsProjection::dataColumns(),
                        'deferred_updates' => false,
                        'embedded_references' => [],
                        'generated_columns' => [],
                        'omit_columns' => [],
                        'references' => CompanyBackupInvoiceAttachmentsProjection::references(),
                        'restore_overrides' => [],
                    ],
                ]),
            new TenantDataDefinition($areaKey, TenantDataObjectKind::FileArea,
                TenantDataPolicy::TenantOwned, [$profile], [
                    'file_policy' => 'historical_optional',
                    'path_policy' => 'supplier_invoice_attachment',
                    'file_owners' => [[
                        'registry_key' => 'table:invoice_attachments',
                        'column' => 'filename', 'path' => [], 'stored_prefix' => '',
                    ]],
                    'ownership' => ['strategy' => 'database_references'],
                    'storage_subdirectory' => 'invoices',
                ]),
        ], [$profile]), $profile);
        $entries = [];
        foreach ($rows as [$attachmentId, $invoiceId, $filename]) {
            $entries[] = [
                'source_path' => 'sup-2/attachments/' . $invoiceId . '/' . $filename,
                'archive_path' => null,
                'state' => 'missing',
                'bytes' => null,
                'sha256' => null,
                'owners' => [[
                    'registry_key' => 'table:invoice_attachments',
                    'primary_key' => ['id' => $attachmentId],
                    'column' => 'filename',
                    'path' => [],
                ]],
            ];
        }
        usort($entries, static fn (array $a, array $b): int =>
            strcmp($a['source_path'], $b['source_path']));
        $inventory = CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [[
                'registry_key' => $areaKey,
                'order' => 1,
                'entries' => $entries,
            ]],
        ], $registry);
        return [$registry, $inventory];
    }

    /** @return array{TenantDataRegistrySnapshot,CompanyBackupFileInventory} */
    private function logoContext(): array
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $registry = TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(1, [
            new TenantDataDefinition('table:supplier', TenantDataObjectKind::Table,
                TenantDataPolicy::TenantRoot, [$profile], [
                    'primary_key' => ['id'],
                    'ownership' => ['strategy' => 'selected_supplier', 'column' => 'id'],
                    'secrets' => [],
                ]),
            new TenantDataDefinition('file-area:invoice-attachments', TenantDataObjectKind::FileArea,
                TenantDataPolicy::TenantOwned, [$profile], [
                    'file_policy' => 'historical_optional',
                    'path_policy' => 'relative',
                    'file_owners' => [[
                        'registry_key' => 'table:supplier', 'column' => 'logo_path',
                        'path' => [], 'stored_prefix' => 'storage/supplier-logos/',
                    ]],
                    'ownership' => ['strategy' => 'database_references'],
                    'storage_subdirectory' => 'supplier-logos',
                ]),
        ], [$profile]), $profile);
        $inventory = CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [[
                'registry_key' => 'file-area:invoice-attachments', 'order' => 1,
                'entries' => [],
            ]],
        ], $registry);
        return [$registry, $inventory];
    }
}
