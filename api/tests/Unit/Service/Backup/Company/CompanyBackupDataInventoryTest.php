<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveException;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\TestCase;

final class CompanyBackupDataInventoryTest extends TestCase
{
    public function testBindsEveryMachineDataObjectToRegistryPathOrderAndDigest(): void
    {
        $invoice = "{\"id\":7,\"supplier_id\":3}\n";
        $supplier = "{\"id\":3,\"name\":\"Synthetic s.r.o.\"}\n";
        $inventory = CompanyBackupDataInventory::fromArray([
            'format' => CompanyBackupDataInventory::FORMAT,
            'version' => CompanyBackupDataInventory::VERSION,
            'objects' => [
                $this->object('table:supplier', 1, $supplier),
                $this->object('table:invoices', 2, $invoice),
            ],
        ], $this->snapshot());

        self::assertSame(
            ['table:supplier', 'table:invoices'],
            array_map(static fn ($object): string => $object->registryKey, $inventory->objects),
        );
        self::assertSame(
            'data/table-invoices.jsonl',
            $inventory->object('table:invoices')?->path,
        );
        self::assertNull($inventory->object('table:derived_cache'));
        self::assertSame($inventory->toArray(), CompanyBackupDataInventory::fromArray(
            $inventory->toArray(),
            $this->snapshot(),
        )->toArray());
        self::assertSame($inventory->toArray(), CompanyBackupDataInventory::fromObjects(
            $inventory->objects,
            $this->snapshot(),
        )->toArray());

        $inventory->assertArchiveEntries(
            [
                'README.txt' => hash('sha256', "README\n"),
                'data/table-invoices.jsonl' => hash('sha256', $invoice),
                'data/table-supplier.jsonl' => hash('sha256', $supplier),
                'manifest.json' => hash('sha256', '{}'),
            ],
            [
                'README.txt' => 7,
                'data/table-invoices.jsonl' => strlen($invoice),
                'data/table-supplier.jsonl' => strlen($supplier),
                'manifest.json' => 2,
            ],
        );
    }

    public function testMissingRequiredRegistryObjectIsRejected(): void
    {
        $supplier = "{\"id\":3}\n";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('table:invoices');
        CompanyBackupDataInventory::fromArray([
            'format' => CompanyBackupDataInventory::FORMAT,
            'version' => CompanyBackupDataInventory::VERSION,
            'objects' => [$this->object('table:supplier', 1, $supplier)],
        ], $this->snapshot());
    }

    public function testRuntimeDerivedObjectMustNotMasqueradeAsPayload(): void
    {
        $supplier = "{\"id\":3}\n";
        $derived = "{\"cache\":true}\n";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('table:derived_cache');
        CompanyBackupDataInventory::fromArray([
            'format' => CompanyBackupDataInventory::FORMAT,
            'version' => CompanyBackupDataInventory::VERSION,
            'objects' => [
                $this->object('table:supplier', 1, $supplier),
                $this->object('table:invoices', 2, ""),
                $this->object('table:derived_cache', 3, $derived),
            ],
        ], $this->snapshot());
    }

    public function testManifestDigestIsBoundToActualDataEntry(): void
    {
        $supplier = "{\"id\":3}\n";
        $invoice = "{\"id\":7}\n";
        $inventory = CompanyBackupDataInventory::fromArray([
            'format' => CompanyBackupDataInventory::FORMAT,
            'version' => CompanyBackupDataInventory::VERSION,
            'objects' => [
                $this->object('table:supplier', 1, $supplier),
                $this->object('table:invoices', 2, $invoice),
            ],
        ], $this->snapshot());

        try {
            $inventory->assertArchiveEntries(
                [
                    'data/table-supplier.jsonl' => hash('sha256', $supplier),
                    'data/table-invoices.jsonl' => str_repeat('f', 64),
                ],
                [
                    'data/table-supplier.jsonl' => strlen($supplier),
                    'data/table-invoices.jsonl' => strlen($invoice),
                ],
            );
            self::fail('Manifestový digest musí být svázaný se skutečnou položkou archivu.');
        } catch (CompanyBackupArchiveException $e) {
            self::assertSame('data_entry_checksum_mismatch', $e->errorCode);
            self::assertSame('data/table-invoices.jsonl', $e->entry);
        }
    }

    public function testUnregisteredDataEntryIsRejectedEvenWhenChecksumsCoverIt(): void
    {
        $supplier = "{\"id\":3}\n";
        $invoice = "{\"id\":7}\n";
        $inventory = CompanyBackupDataInventory::fromArray([
            'format' => CompanyBackupDataInventory::FORMAT,
            'version' => CompanyBackupDataInventory::VERSION,
            'objects' => [
                $this->object('table:supplier', 1, $supplier),
                $this->object('table:invoices', 2, $invoice),
            ],
        ], $this->snapshot());

        $this->expectException(CompanyBackupArchiveException::class);
        $this->expectExceptionMessage('data_inventory_scope_mismatch');
        $inventory->assertArchiveEntries(
            [
                'data/table-supplier.jsonl' => hash('sha256', $supplier),
                'data/table-invoices.jsonl' => hash('sha256', $invoice),
                'data/table-unknown.jsonl' => hash('sha256', ''),
            ],
            [
                'data/table-supplier.jsonl' => strlen($supplier),
                'data/table-invoices.jsonl' => strlen($invoice),
                'data/table-unknown.jsonl' => 0,
            ],
        );
    }

    /** @return array{registry_key:string,path:string,order:int,rows:int,bytes:int,sha256:string} */
    private function object(string $registryKey, int $order, string $contents): array
    {
        return [
            'registry_key' => $registryKey,
            'path' => 'data/' . str_replace(':', '-', $registryKey) . '.jsonl',
            'order' => $order,
            'rows' => $contents === '' ? 0 : substr_count($contents, "\n"),
            'bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    private function snapshot(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                new TenantDataDefinition(
                    'table:supplier',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::TenantRoot,
                    [$profile],
                    ['ownership' => ['strategy' => 'selected_supplier', 'column' => 'id']],
                ),
                new TenantDataDefinition(
                    'table:invoices',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    ['ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id']],
                ),
                new TenantDataDefinition(
                    'table:derived_cache',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::RuntimeDerived,
                    [$profile],
                    ['ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id']],
                ),
                new TenantDataDefinition(
                    'file-area:invoice-pdf',
                    TenantDataObjectKind::FileArea,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    ['ownership' => ['strategy' => 'invoice_reference']],
                ),
            ],
            [$profile],
        ), $profile);
    }
}
