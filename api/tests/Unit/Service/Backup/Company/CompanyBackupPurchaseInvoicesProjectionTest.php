<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPurchaseInvoicesProjection as Projection;
use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPurchaseInvoicesProjectionTest extends TestCase
{
    public function testPersistedColumnsAndIdentifierClassification(): void
    {
        $columns = Projection::dataColumns();
        self::assertCount(79, $columns);
        self::assertSame($columns, array_values(array_unique($columns)));
        self::assertSame([], array_intersect($columns, Projection::generatedColumns()));
        self::assertSame(['amount_to_pay', 'effective_cost_date'], Projection::generatedColumns());
        self::assertSame(
            ['idoklad_id', 'fakturoid_id', 'import_batch_id'],
            Projection::preservedIdentifiers(),
        );
        foreach ([
            'vendor_snapshot', 'own_snapshot', 'vat_overrides', 'pdf_path',
            'source_path', 'prices_include_vat', 'paid_amount_payment_ccy',
            'payment_exchange_rate', 'received_at_source',
        ] as $column) {
            self::assertContains($column, $columns);
        }
    }

    public function testReferencesParseAndRemapWithoutChangingFinancialOrSourceData(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            Projection::references(), 'table:purchase_invoices',
        );
        self::assertCount(12, $references->references);
        $byColumn = [];
        foreach (Projection::references() as $reference) {
            $byColumn[$reference['columns'][0]] = $reference;
        }
        self::assertSame(
            ['restore_actor'], $byColumn['created_by']['fallbacks'],
        );
        self::assertSame([], $byColumn['created_by']['nullable_columns']);
        self::assertSame(
            CompanyBackupReferenceMapping::Actor->value,
            $byColumn['created_by']['mapping'],
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional->value,
            $byColumn['booked_by']['constraint'],
        );
        self::assertSame(
            ['null', 'restore_actor'], $byColumn['booked_by']['fallbacks'],
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional->value,
            $byColumn['expense_category_id']['constraint'],
        );

        $row = array_fill_keys(Projection::dataColumns(), null);
        foreach (array_keys($byColumn) as $index => $column) {
            $row[$column] = $index + 1;
        }
        $row['total_without_vat'] = '100.0000';
        $row['total_vat'] = '21.0000';
        $row['total_with_vat'] = '121.0000';
        $row['advance_paid_amount'] = '50.0000';
        $row['paid_amount_payment_ccy'] = '50.0000';
        $row['paid_amount_invoice_ccy'] = '49.9900';
        $row['payment_exchange_rate'] = '1.000001';
        $row['prices_include_vat'] = 1;
        $row['payment_method'] = 'bank_transfer';
        $row['payment_method_source'] = 'manual';
        $row['payment_iban'] = 'CZ1801000000001000000005';
        $row['vendor_snapshot'] = '{"name":"Synthetic Vendor"}';
        $row['own_snapshot'] = '{"name":"Synthetic Company"}';
        $row['vat_overrides'] = '[{"rate":21,"base":100,"vat":21}]';
        $row['pdf_path'] = 'supplier-7/ab/abcdef0123456789.pdf';
        $row['source_path'] = 'sources/supplier-7/ab/abcdef0123456789.xml';
        $row['import_batch_id'] = 'synthetic-batch-1';
        $row['idoklad_id'] = 901;
        $row['fakturoid_id'] = 902;
        $original = $row;

        $mapped = $references->remap(
            $row,
            static function (CompanyBackupReference $reference, array $source): array {
                self::assertCount(1, $source);
                self::assertIsInt($source[0]);
                return [$source[0] + 100];
            },
        );
        foreach ($original as $column => $value) {
            if (isset($byColumn[$column])) {
                self::assertIsInt($value);
                self::assertSame($value + 100, $mapped[$column], $column);
            } else {
                self::assertSame($value, $mapped[$column], $column);
            }
        }
        self::assertSame($original, $row);
    }
}
