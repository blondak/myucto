<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce akčních cen skladových karet. */
final class CompanyBackupStockItemPromoPricesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'stock_item_id',
            'currency_code',
            'promo_price',
            'label',
            'valid_from',
            'valid_to',
            'qty_mode',
            'qty_limit',
            'is_active',
            'note',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * Stejně jako u běžných cen je currency_code volný ISO kód, nikoli ID
     * měnového účtu ani FK na číselník prodejních měn (migrace 1371).
     *
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
                'columns' => ['supplier_id'],
                'target' => 'table:supplier',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }
}
