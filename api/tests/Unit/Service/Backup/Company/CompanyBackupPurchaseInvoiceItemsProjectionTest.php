<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPurchaseInvoiceItemsProjection as Projection;
use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPurchaseInvoiceItemsProjectionTest extends TestCase
{
    public function testPreservesAllPersistedColumnsInPhysicalOrder(): void
    {
        self::assertSame([
            'id', 'purchase_invoice_id', 'description', 'quantity', 'unit',
            'unit_price_without_vat', 'vat_rate_id', 'vat_rate_snapshot',
            'total_without_vat', 'total_vat', 'total_with_vat', 'order_index',
            'vat_classification_code', 'stock_item_id', 'purchase_order_line_id',
            'is_fixed_asset', 'expense_kind', 'expense_account_code',
            'accrual_from', 'accrual_to', 'expense_category_id',
        ], Projection::dataColumns());
    }

    public function testClassifiesEveryPhysicalForeignKeyWithoutFallback(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            Projection::references(), 'table:purchase_invoice_items',
        )->references;
        self::assertSame([
            'expense_category_id->expense_categories:id',
            'purchase_invoice_id->purchase_invoices:id',
            'purchase_order_line_id->purchase_order_lines:id',
            'stock_item_id->stock_items:id',
            'vat_rate_id->vat_rates:id',
        ], array_map(static fn (CompanyBackupReference $reference): string =>
            $reference->signature(), $references));
        foreach ($references as $reference) {
            self::assertSame(
                $reference->firstColumn() === 'expense_category_id'
                    ? CompanyBackupReferenceConstraint::Optional
                    : CompanyBackupReferenceConstraint::Required,
                $reference->constraint);
            self::assertSame([], $reference->fallbacks);
            self::assertSame(
                in_array($reference->firstColumn(), ['purchase_invoice_id', 'vat_rate_id'], true)
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

    public function testRemapsOnlyIdentifiersAndPreservesHistoricalAccountingValues(): void
    {
        $row = array_fill_keys(Projection::dataColumns(), null);
        $row['id'] = 17;
        $row['purchase_invoice_id'] = 31;
        $row['vat_rate_id'] = 2;
        $row['purchase_order_line_id'] = 43;
        $row['stock_item_id'] = 44;
        $row['expense_category_id'] = 45;
        $row['quantity'] = '2.7500';
        $row['unit_price_without_vat'] = '12.345678';
        $row['vat_rate_snapshot'] = '21.0000';
        $row['total_without_vat'] = '33.950617';
        $row['total_vat'] = '7.129629';
        $row['total_with_vat'] = '41.080246';
        $row['vat_classification_code'] = 'synthetic-code';
        $row['is_fixed_asset'] = 1;
        $row['expense_kind'] = 'fixed_asset';
        $row['expense_account_code'] = '501';
        $row['accrual_from'] = '2026-01-01';
        $row['accrual_to'] = '2026-03-31';
        $original = $row;

        $references = CompanyBackupReferenceSet::fromArray(
            Projection::references(), 'table:purchase_invoice_items',
        );
        $mapped = $references->remap($row,
            static function (CompanyBackupReference $reference, array $source): array {
                self::assertIsInt($source[0]);
                return [$source[0] + 100];
            },
        );

        foreach (['purchase_invoice_id' => 131, 'vat_rate_id' => 102,
            'purchase_order_line_id' => 143, 'stock_item_id' => 144,
            'expense_category_id' => 145] as $column => $expected) {
            self::assertSame($expected, $mapped[$column], $column);
        }
        foreach ($original as $column => $value) {
            if (!in_array($column, ['purchase_invoice_id', 'vat_rate_id',
                'purchase_order_line_id', 'stock_item_id', 'expense_category_id'], true)) {
                self::assertSame($value, $mapped[$column], $column);
            }
        }
        self::assertSame($original, $row);
    }
}
