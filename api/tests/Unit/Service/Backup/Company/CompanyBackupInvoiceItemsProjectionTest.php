<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceItemsProjection as Projection;
use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use PHPUnit\Framework\TestCase;

final class CompanyBackupInvoiceItemsProjectionTest extends TestCase
{
    public function testPreservesAllPersistedColumnsInPhysicalOrder(): void
    {
        self::assertSame([
            'id', 'invoice_id', 'description', 'quantity', 'unit',
            'unit_price_without_vat', 'vat_rate_id', 'vat_rate_snapshot',
            'total_without_vat', 'total_vat', 'total_with_vat', 'order_index',
            'item_kind', 'linked_work_report_id', 'stock_item_id', 'warehouse_id',
            'small_asset_id', 'asset_id', 'vat_classification_code',
            'oss_applicable', 'oss_consumer_country', 'oss_rate_type',
            'oss_supply_type', 'oss_exchange_rate', 'oss_exchange_rate_date',
            'oss_taxable_amount_return', 'oss_vat_amount_return',
            'oss_original_period', 'oss_needs_manual_review',
        ], Projection::dataColumns());
    }

    public function testClassifiesEveryPhysicalForeignKeyWithoutFallback(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            Projection::references(), 'table:invoice_items',
        )->references;
        self::assertSame([
            'asset_id->assets:id',
            'invoice_id->invoices:id',
            'linked_work_report_id->work_reports:id',
            'small_asset_id->small_assets:id',
            'stock_item_id->stock_items:id',
            'vat_rate_id->vat_rates:id',
            'warehouse_id->warehouses:id',
        ], array_map(static fn (CompanyBackupReference $reference): string =>
            $reference->signature(), $references));
        foreach ($references as $reference) {
            self::assertSame(CompanyBackupReferenceConstraint::Required,
                $reference->constraint);
            self::assertSame([], $reference->fallbacks);
            self::assertSame(
                in_array($reference->firstColumn(), ['invoice_id', 'vat_rate_id'], true)
                    ? [] : [$reference->firstColumn()],
                $reference->nullableColumns,
            );
            self::assertSame(
                $reference->firstColumn() === 'vat_rate_id'
                    ? CompanyBackupReferenceMapping::GlobalNaturalKey
                    : CompanyBackupReferenceMapping::TenantId,
                $reference->mapping,
            );
        }
    }

    public function testRemapsOnlyIdentifiersAndPreservesAmountsVatAndOssEvidence(): void
    {
        $row = array_fill_keys(Projection::dataColumns(), null);
        $row['id'] = 17;
        $row['invoice_id'] = 31;
        $row['vat_rate_id'] = 2;
        $row['asset_id'] = 43;
        $row['quantity'] = '2.7500';
        $row['unit_price_without_vat'] = '12.345678';
        $row['vat_rate_snapshot'] = '21.0000';
        $row['total_without_vat'] = '33.950617';
        $row['total_vat'] = '7.129629';
        $row['total_with_vat'] = '41.080246';
        $row['oss_exchange_rate'] = '24.123456';
        $row['oss_taxable_amount_return'] = '1.234567';
        $row['oss_vat_amount_return'] = '0.259259';
        $row['oss_needs_manual_review'] = 1;
        $original = $row;

        $references = CompanyBackupReferenceSet::fromArray(
            Projection::references(), 'table:invoice_items',
        );
        $mapped = $references->remap($row,
            static function (CompanyBackupReference $reference, array $source): array {
                self::assertIsInt($source[0]);
                return [$source[0] + 100];
            },
        );

        self::assertSame(17, $mapped['id']);
        self::assertSame(131, $mapped['invoice_id']);
        self::assertSame(102, $mapped['vat_rate_id']);
        self::assertSame(143, $mapped['asset_id']);
        foreach ($original as $column => $value) {
            if (!in_array($column, ['invoice_id', 'vat_rate_id', 'asset_id'], true)) {
                self::assertSame($value, $mapped[$column], $column);
            }
        }
        self::assertSame($original, $row);
    }
}
