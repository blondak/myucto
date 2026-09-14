<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupRecurringInvoiceTemplateItemsProjection as Items;
use MyInvoice\Service\Backup\Company\CompanyBackupRecurringInvoiceTemplatesProjection as Templates;
use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupRestoreOverrideSet;
use PHPUnit\Framework\TestCase;

final class CompanyBackupRecurringInvoiceProjectionsTest extends TestCase
{
    public function testTemplateReferencesAndRestoreOverridesAreValid(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            Templates::references(), 'table:recurring_invoice_templates',
        );
        self::assertCount(7, $references->references);
        $byColumn = [];
        foreach ($references->references as $reference) {
            $byColumn[$reference->firstColumn()] = $reference;
        }
        self::assertSame(CompanyBackupReferenceMapping::Actor, $byColumn['created_by']->mapping);
        self::assertSame(CompanyBackupReferenceConstraint::Required, $byColumn['created_by']->constraint);
        self::assertSame([], $byColumn['created_by']->nullableColumns);
        self::assertSame(['restore_actor'], $byColumn['created_by']->fallbacks);
        self::assertSame(CompanyBackupReferenceConstraint::Optional, $byColumn['revenue_category_id']->constraint);
        self::assertSame('table:revenue_categories', $byColumn['revenue_category_id']->target);

        $overrides = CompanyBackupRestoreOverrideSet::fromArray(
            Templates::restoreOverrides(), 'table:recurring_invoice_templates',
            Templates::dataColumns(), ['id'], $references,
        );
        foreach (['active' => 'paused', 'paused' => 'paused', 'expired' => 'expired'] as $source => $expected) {
            $row = array_fill_keys(Templates::dataColumns(), null);
            $row['status'] = $source;
            $row['auto_issue'] = 1;
            $row['auto_send_email'] = 1;
            $row['next_run_date'] = '2030-04-15';
            $row['last_error'] = 'synthetic retry error';
            $row['prices_include_vat'] = 1;
            $actual = $overrides->apply($row);
            self::assertSame($expected, $actual['status']);
            self::assertSame(0, $actual['auto_issue']);
            self::assertSame(0, $actual['auto_send_email']);
            foreach ($row as $column => $value) {
                if (!in_array($column, ['status', 'auto_issue', 'auto_send_email'], true)) {
                    self::assertSame($value, $actual[$column], $column);
                }
            }
        }
    }

    public function testItemCenicReferenceCannotDisappearAndSnapshotsRemainByteExact(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            Items::references(), 'table:recurring_invoice_template_items',
        );
        self::assertCount(5, $references->references);
        $byColumn = [];
        foreach ($references->references as $reference) {
            $byColumn[$reference->firstColumn()] = $reference;
        }
        self::assertSame('table:price_list_items', $byColumn['price_list_item_id']->target);
        self::assertSame(CompanyBackupReferenceConstraint::Required, $byColumn['price_list_item_id']->constraint);
        self::assertSame(['price_list_item_id'], $byColumn['price_list_item_id']->nullableColumns);
        self::assertSame(CompanyBackupReferenceMapping::GlobalNaturalKey, $byColumn['vat_rate_id']->mapping);

        $row = array_fill_keys(Items::dataColumns(), null);
        $row['id'] = 1;
        $row['template_id'] = 2;
        $row['price_list_item_id'] = 3;
        $row['vat_rate_id'] = 4;
        $row['stock_item_id'] = 5;
        $row['catalog_policy'] = 'review_required';
        $row['catalog_source_unit_price'] = '17.2300';
        $row['catalog_exchange_rate'] = '24.12345678';
        $row['unit_price_without_vat'] = '19.9900';
        $row['quantity'] = '2.750';
        $row['oss_applicable'] = 1;
        $original = $row;
        $mapped = $references->remap($row,
            static function (CompanyBackupReference $reference, array $source): array {
                self::assertIsInt($source[0]);
                return [$source[0] + 100];
            },
        );
        foreach (['template_id', 'price_list_item_id', 'vat_rate_id', 'stock_item_id'] as $column) {
            self::assertIsInt($original[$column]);
            self::assertSame($original[$column] + 100, $mapped[$column]);
        }
        foreach ($original as $column => $value) {
            if (!in_array($column, ['template_id', 'price_list_item_id', 'vat_rate_id', 'stock_item_id'], true)) {
                self::assertSame($value, $mapped[$column], $column);
            }
        }
        self::assertSame($original, $row);
    }
}
