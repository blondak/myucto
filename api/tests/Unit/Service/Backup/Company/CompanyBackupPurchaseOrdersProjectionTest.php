<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPurchaseOrdersProjectionTest extends TestCase
{
    public function testOrderRemapsOwnersAndActorsWithoutChangingDraftState(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:purchase_orders');
        self::assertNotNull($definition);
        self::assertArrayNotHasKey('natural_key', $definition->details);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $projection->assertRegistryTargets($registry);
        $row = array_fill_keys($projection->dataColumns, null);
        $row = array_replace($row, [
            'id' => 1, 'supplier_id' => 7, 'vendor_id' => 8,
            'warehouse_id' => 9, 'currency_id' => 10,
            'created_by' => 11, 'confirmed_by' => 12,
            'closed_by' => 13, 'cancelled_by' => 14, 'state' => 'draft',
            'total_with_vat' => '123.45',
        ]);
        $actors = [];
        $mapped = $projection->references->remap($row,
            static function (CompanyBackupReference $reference, array $key) use (&$actors): ?array {
                if ($reference->mapping === CompanyBackupReferenceMapping::Actor) {
                    $actors[] = $reference->columns[0];
                    self::assertSame(['null', 'restore_actor'], $reference->fallbacks);
                    return null;
                }
                return [$key[0] + 100];
            },
        );
        self::assertSame(['cancelled_by', 'closed_by', 'confirmed_by', 'created_by'], $actors);
        self::assertSame(107, $mapped['supplier_id']);
        self::assertSame(108, $mapped['vendor_id']);
        self::assertSame(109, $mapped['warehouse_id']);
        self::assertSame(110, $mapped['currency_id']);
        self::assertNull($mapped['created_by']);
        self::assertNull($mapped['order_number']);
        self::assertSame('draft', $mapped['state']);
        self::assertSame('123.45', $mapped['total_with_vat']);
        self::assertCount(27, $projection->dataColumns);
    }

    public function testServiceLineKeepsNullableStockFieldsAndRemapsVat(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:purchase_order_lines');
        self::assertNotNull($definition);
        self::assertSame(['order_id', 'line_no'], $definition->details['natural_key']);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $projection->assertRegistryTargets($registry);
        $row = array_fill_keys($projection->dataColumns, null);
        $row = array_replace($row, [
            'id' => 2, 'supplier_id' => 7, 'order_id' => 1, 'line_no' => 1,
            'vat_rate_id' => 4, 'qty_ordered' => '2.000',
            'qty_cancelled' => '0.000', 'unit_price' => '10.123456',
        ]);
        $mapped = $projection->references->remap($row,
            static function (CompanyBackupReference $reference, array $key): array {
                if ($reference->columns === ['vat_rate_id']) {
                    self::assertSame(CompanyBackupReferenceMapping::GlobalNaturalKey, $reference->mapping);
                }
                return [$key[0] + 100];
            },
        );
        self::assertSame(101, $mapped['order_id']);
        self::assertSame(104, $mapped['vat_rate_id']);
        self::assertNull($mapped['stock_item_id']);
        self::assertNull($mapped['warehouse_id']);
        self::assertNull($mapped['qty_confirmed']);
        self::assertSame('10.123456', $mapped['unit_price']);
        self::assertCount(17, $projection->dataColumns);
    }

    public function testInvoiceLinkRemapsBothDocumentsAndPreservesMatchOrigin(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:purchase_order_invoice_links');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $projection->assertRegistryTargets($registry);
        $row = [
            'id' => 3, 'supplier_id' => 7, 'order_id' => 1,
            'purchase_invoice_id' => 2, 'linked_at' => '2026-01-01 12:00:00',
            'linked_by' => null, 'match_source' => 'manual',
        ];
        $mapped = $projection->references->remap($row,
            static fn (CompanyBackupReference $reference, array $key): array => [$key[0] + 100],
        );
        self::assertSame(101, $mapped['order_id']);
        self::assertSame(102, $mapped['purchase_invoice_id']);
        self::assertSame(107, $mapped['supplier_id']);
        self::assertSame('manual', $mapped['match_source']);
        self::assertNull($mapped['linked_by']);
        self::assertSame(['order_id', 'purchase_invoice_id'], $definition->details['natural_key']);
        $projection->assertRuntimeSchema(array_keys($row), [], ['id']);
    }
}
