<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce skladových karet. */
final class CompanyBackupStockItemsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'sku',
            'name',
            'item_type',
            'manufacturer_id',
            'unit',
            'ean',
            'vat_rate_id',
            'sale_price_without_vat',
            'min_qty',
            'is_active',
            'note',
            'created_at',
            'updated_at',
            'warranty_months',
            'delivery_days',
            'export_eshop',
            'is_stocked',
            'weight_g',
            'pricing_base',
        ];
    }

    /**
     * Výrobce je firemní číselník, zatímco sazby DPH jsou globální a jejich
     * identita se obnovuje přes stabilní kód.
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
            self::reference(
                'manufacturer_id',
                'manufacturers',
                CompanyBackupReferenceMapping::TenantId,
                nullable: true,
            ),
            self::reference(
                'supplier_id',
                'supplier',
                CompanyBackupReferenceMapping::TenantId,
            ),
            self::reference(
                'vat_rate_id',
                'vat_rates',
                CompanyBackupReferenceMapping::GlobalNaturalKey,
                nullable: true,
            ),
        ];
    }

    /**
     * @return array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function reference(
        string $column,
        string $target,
        CompanyBackupReferenceMapping $mapping,
        bool $nullable = false,
    ): array {
        return [
            'columns' => [$column],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => $mapping->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }
}
