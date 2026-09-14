<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce historických položek vydaných faktur. */
final class CompanyBackupInvoiceItemsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'invoice_id',
            'description',
            'quantity',
            'unit',
            'unit_price_without_vat',
            'vat_rate_id',
            'vat_rate_snapshot',
            'total_without_vat',
            'total_vat',
            'total_with_vat',
            'order_index',
            'item_kind',
            'linked_work_report_id',
            'stock_item_id',
            'warehouse_id',
            'small_asset_id',
            'asset_id',
            'vat_classification_code',
            'oss_applicable',
            'oss_consumer_country',
            'oss_rate_type',
            'oss_supply_type',
            'oss_exchange_rate',
            'oss_exchange_rate_date',
            'oss_taxable_amount_return',
            'oss_vat_amount_return',
            'oss_original_period',
            'oss_needs_manual_review',
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
            self::reference('asset_id', 'assets', nullable: true),
            self::reference('invoice_id', 'invoices'),
            self::reference('linked_work_report_id', 'work_reports', nullable: true),
            self::reference('small_asset_id', 'small_assets', nullable: true),
            self::reference('stock_item_id', 'stock_items', nullable: true),
            self::reference(
                'vat_rate_id',
                'vat_rates',
                CompanyBackupReferenceMapping::GlobalNaturalKey,
            ),
            self::reference('warehouse_id', 'warehouses', nullable: true),
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
        CompanyBackupReferenceMapping $mapping = CompanyBackupReferenceMapping::TenantId,
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
