<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Položky recurring šablony včetně ceníkové vazby a původních cenových snapshotů. */
final class CompanyBackupRecurringInvoiceTemplateItemsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'template_id', 'price_list_item_id', 'catalog_policy',
            'description_source', 'catalog_price_source',
            'catalog_source_currency_code', 'catalog_source_unit_price',
            'catalog_exchange_rate', 'catalog_exchange_rate_date',
            'description', 'quantity', 'unit', 'unit_price_without_vat',
            'vat_rate_id', 'oss_applicable', 'oss_consumer_country',
            'oss_rate_type', 'oss_supply_type', 'order_index',
            'stock_item_id', 'warehouse_id',
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function references(): array
    {
        return [
            self::reference('price_list_item_id', 'price_list_items', true),
            self::reference('stock_item_id', 'stock_items', true),
            self::reference('template_id', 'recurring_invoice_templates'),
            self::reference('vat_rate_id', 'vat_rates', false,
                CompanyBackupReferenceMapping::GlobalNaturalKey),
            self::reference('warehouse_id', 'warehouses', true),
        ];
    }

    /** @return array<string,mixed> */
    private static function reference(
        string $column,
        string $target,
        bool $nullable = false,
        CompanyBackupReferenceMapping $mapping = CompanyBackupReferenceMapping::TenantId,
    ): array {
        return [
            'columns' => [$column], 'target' => 'table:' . $target,
            'target_columns' => ['id'], 'mapping' => $mapping->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $nullable ? [$column] : [], 'fallbacks' => [],
        ];
    }
}
