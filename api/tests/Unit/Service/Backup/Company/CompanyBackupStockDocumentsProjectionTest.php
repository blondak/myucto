<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupStockDocumentsProjectionTest extends TestCase
{
    /** @return array<string,array{string,list<string>,list<string>,?list<string>}> */
    public static function tables(): array
    {
        return [
            'stock_documents' => [
                'stock_documents',
                [
                    'id', 'supplier_id', 'doc_type',
                    'origin', 'warehouse_id', 'warehouse_to_id',
                    'doc_number', 'doc_date', 'description',
                    'partner_name', 'invoice_id', 'purchase_invoice_id',
                    'purchase_order_id', 'stock_take_id', 'journal_entry_id',
                    'reversal_document_id', 'status', 'booked_at',
                    'booked_by', 'created_by', 'created_at',
                    'updated_at',
                ],
                [
                    'booked_by', 'created_by', 'invoice_id',
                    'journal_entry_id', 'purchase_invoice_id', 'purchase_order_id',
                    'reversal_document_id', 'stock_take_id', 'supplier_id',
                    'warehouse_id', 'warehouse_to_id',
                ],
                null,
            ],
            'stock_document_lines' => [
                'stock_document_lines',
                [
                    'id', 'document_id', 'supplier_id',
                    'stock_item_id', 'doc_date', 'qty',
                    'unit_cost', 'value_total', 'extra_cost',
                    'invoice_item_id', 'purchase_invoice_item_id', 'purchase_order_line_id',
                    'source_description', 'source_qty', 'line_no',
                    'note',
                ],
                [
                    'document_id', 'invoice_item_id', 'purchase_invoice_item_id',
                    'purchase_order_line_id', 'stock_item_id', 'supplier_id',
                ],
                null,
            ],
            'stock_landed_costs' => [
                'stock_landed_costs',
                [
                    'id', 'supplier_id', 'document_id',
                    'purchase_invoice_id', 'purchase_invoice_item_id', 'description',
                    'amount', 'allocation', 'created_at',
                ],
                [
                    'document_id', 'purchase_invoice_id', 'purchase_invoice_item_id',
                    'supplier_id',
                ],
                null,
            ],
            'stock_takes' => [
                'stock_takes',
                [
                    'id', 'supplier_id', 'warehouse_id',
                    'take_date', 'status', 'note',
                    'counting_method', 'responsible_count_name', 'responsible_inventory_name',
                    'started_at', 'receipt_document_id', 'issue_document_id',
                    'created_by', 'closed_by', 'closed_at',
                    'created_at', 'updated_at',
                ],
                [
                    'closed_by', 'created_by', 'issue_document_id',
                    'receipt_document_id', 'supplier_id', 'warehouse_id',
                ],
                ['supplier_id', 'warehouse_id', 'take_date'],
            ],
            'stock_take_lines' => [
                'stock_take_lines',
                [
                    'id', 'stock_take_id', 'supplier_id',
                    'stock_item_id', 'expected_qty', 'expected_value',
                    'counted_qty', 'surplus_unit_cost',
                ],
                [
                    'stock_item_id', 'stock_take_id', 'supplier_id',
                ],
                ['stock_take_id', 'stock_item_id'],
            ],
        ];
    }

    /**
     * @param list<string> $columns
     * @param list<string> $references
     * @param ?list<string> $naturalKey
     */
    #[DataProvider('tables')]
    public function testCompleteProjectionRemapsRelationsWithoutRecalculatingHistory(
        string $table, array $columns, array $references, ?array $naturalKey,
    ): void {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:' . $table);
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        self::assertSame($columns, $projection->dataColumns);
        self::assertSame($naturalKey, $definition->details['natural_key'] ?? null);
        $projection->assertRegistryTargets($registry);
        $row = array_fill_keys($columns, 'historical-value');
        $row['id'] = 1;
        foreach ($references as $index => $column) {
            $row[$column] = $index + 10;
        }
        foreach (['qty', 'unit_cost', 'value_total', 'extra_cost', 'expected_qty',
            'expected_value', 'counted_qty', 'surplus_unit_cost', 'amount'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = '10.123456';
            }
        }
        $visited = [];
        $mapped = $projection->references->remap($row,
            static function (CompanyBackupReference $reference, array $key) use (&$visited): ?array {
                $visited[] = $reference->columns[0];
                if ($reference->mapping === CompanyBackupReferenceMapping::Actor) {
                    self::assertSame(['null', 'restore_actor'], $reference->fallbacks);
                    return null;
                }
                self::assertSame(CompanyBackupReferenceMapping::TenantId, $reference->mapping);
                return [$key[0] + 100];
            },
        );
        self::assertSame($references, $visited);
        foreach ($row as $column => $value) {
            $expected = in_array($column, $references, true)
                ? (in_array($column, ['booked_by', 'created_by', 'closed_by'], true) ? null : $value + 100)
                : $value;
            self::assertSame($expected, $mapped[$column], $table . '.' . $column);
        }
    }
}
