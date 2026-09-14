<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupSmallAssetsProjection as Projection;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSmallAssetsProjectionTest extends TestCase
{
    public function testPreservesEveryPersistedCardFieldInPhysicalOrder(): void
    {
        self::assertSame([
            'id', 'supplier_id', 'asset_kind', 'purchase_invoice_id',
            'purchase_invoice_item_id', 'cash_document_id', 'document_ref',
            'name', 'inventory_number', 'vendor_client_id', 'vendor_name',
            'acquisition_date', 'put_into_use_date', 'useful_months',
            'quantity', 'unit_price', 'price', 'location', 'responsible_person',
            'status', 'disposed_at', 'disposal_reason', 'sale_invoice_id',
            'sold_at', 'sale_price', 'notes', 'created_by', 'created_at',
            'updated_at',
        ], Projection::dataColumns());
    }

    public function testClassifiesAllPhysicalForeignKeysAndActorPolicy(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            Projection::references(), 'table:small_assets',
        )->references;
        self::assertSame([
            'cash_document_id->cash_documents:id',
            'created_by->users:id',
            'purchase_invoice_id->purchase_invoices:id',
            'purchase_invoice_item_id->purchase_invoice_items:id',
            'sale_invoice_id->invoices:id',
            'supplier_id->supplier:id',
            'vendor_client_id->clients:id',
        ], array_map(static fn (CompanyBackupReference $reference): string =>
            $reference->signature(), $references));
        foreach ($references as $reference) {
            $column = $reference->firstColumn();
            self::assertSame(
                $column === 'created_by'
                    ? CompanyBackupReferenceMapping::Actor
                    : CompanyBackupReferenceMapping::TenantId,
                $reference->mapping,
                $column,
            );
            self::assertSame(CompanyBackupReferenceConstraint::Required,
                $reference->constraint, $column);
            self::assertSame($column === 'supplier_id' ? [] : [$column],
                $reference->nullableColumns, $column);
            self::assertSame($column === 'created_by' ? ['null', 'restore_actor'] : [],
                $reference->fallbacks, $column);
        }
    }

    public function testRemapPreservesOriginalAcquisitionAndSaleValues(): void
    {
        $row = array_fill_keys(Projection::dataColumns(), null);
        $row['id'] = 12;
        $row['supplier_id'] = 4;
        $row['purchase_invoice_id'] = 11;
        $row['purchase_invoice_item_id'] = 15;
        $row['sale_invoice_id'] = 29;
        $row['created_by'] = 7;
        $row['quantity'] = '2.500';
        $row['unit_price'] = '100.01';
        $row['price'] = '250.03';
        $row['sale_price'] = '125.07';
        $row['asset_kind'] = 'intangible';
        $row['document_ref'] = 'HISTORICAL-REF';
        $row['vendor_name'] = 'Synthetic Vendor';
        $original = $row;

        $references = CompanyBackupReferenceSet::fromArray(
            Projection::references(), 'table:small_assets',
        );
        $mapped = $references->remap($row,
            static function (CompanyBackupReference $reference, array $source): array {
                self::assertIsInt($source[0]);
                return [$source[0] + 100];
            },
        );
        self::assertSame(104, $mapped['supplier_id']);
        self::assertSame(111, $mapped['purchase_invoice_id']);
        self::assertSame(115, $mapped['purchase_invoice_item_id']);
        self::assertSame(129, $mapped['sale_invoice_id']);
        self::assertSame(107, $mapped['created_by']);
        foreach ($original as $column => $value) {
            if (!in_array($column, [
                'supplier_id', 'purchase_invoice_id', 'purchase_invoice_item_id',
                'sale_invoice_id', 'created_by',
            ], true)) {
                self::assertSame($value, $mapped[$column], $column);
            }
        }
        self::assertSame($original, $row);
    }
}
