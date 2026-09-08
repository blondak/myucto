<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce tabulky stock_take_lines. */
final class CompanyBackupStockTakeLinesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'stock_take_id',
            'supplier_id',
            'stock_item_id',
            'expected_qty',
            'expected_value',
            'counted_qty',
            'surplus_unit_cost',
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
                'columns' => ['stock_item_id'],
                'target' => 'table:stock_items',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['stock_take_id'],
                'target' => 'table:stock_takes',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id'],
                'target' => 'table:supplier',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }
}
