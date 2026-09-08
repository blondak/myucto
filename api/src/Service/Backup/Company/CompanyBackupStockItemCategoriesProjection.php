<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce vazeb skladových karet na kategorie. */
final class CompanyBackupStockItemCategoriesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'supplier_id',
            'stock_item_id',
            'category_id',
            'is_primary',
            'display_order',
        ];
    }

    /**
     * Obě části složeného primárního klíče jsou cílové tenantové identity;
     * zdrojová dvojice proto nesmí fungovat jako natural key instalace.
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
            self::tenant('category_id', 'stock_categories'),
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
