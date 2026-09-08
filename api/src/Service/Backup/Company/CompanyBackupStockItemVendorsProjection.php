<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce dodavatelských nabídek skladových karet. */
final class CompanyBackupStockItemVendorsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'stock_item_id',
            'client_id',
            'vendor_sku',
            'purchase_price',
            'currency_code',
            'delivery_days',
            'stock_qty',
            'availability_state',
            'stock_qty_updated_at',
            'is_preferred',
            'note',
            'updated_at',
            'min_order_qty',
            'package_qty',
            'price_valid_to',
            'data_source',
            'is_active',
        ];
    }

    /**
     * Měna je logický kód dříve obnoveného stock_currencies bez databázového
     * FK. Identitní mapování potřebují adresářový dodavatel, karta a firma.
     *
     * @return list<array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }>
     */
    public static function references(): array
    {
        return [
            self::tenant('client_id', 'clients'),
            self::tenant('stock_item_id', 'stock_items'),
            self::tenant('supplier_id', 'supplier'),
        ];
    }

    /**
     * @return array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function tenant(string $column, string $target): array
    {
        return [
            'columns' => [$column],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }
}
