<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce řádků nákupních objednávek. */
final class CompanyBackupPurchaseOrderLinesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'order_id',
            'supplier_id',
            'line_no',
            'stock_item_id',
            'warehouse_id',
            'vendor_sku',
            'description',
            'unit',
            'qty_ordered',
            'qty_confirmed',
            'qty_cancelled',
            'unit_price',
            'vat_rate_id',
            'expected_date',
            'has_over_delivery',
            'note',
        ];
    }

    /**
     * @return list<array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }>
     */
    public static function references(): array
    {
        return [
            [
                'columns' => ['order_id'],
                'target' => 'table:purchase_orders',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['stock_item_id'],
                'target' => 'table:stock_items',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['stock_item_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id'],
                'target' => 'table:supplier',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['vat_rate_id'],
                'target' => 'table:vat_rates',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::GlobalNaturalKey->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['vat_rate_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['warehouse_id'],
                'target' => 'table:warehouses',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['warehouse_id'],
                'fallbacks' => [],
            ],
        ];
    }
}
