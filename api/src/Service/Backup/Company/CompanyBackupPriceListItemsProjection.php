<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce karet ceníku bez přepočtu jejich stavu. */
final class CompanyBackupPriceListItemsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'supplier_id', 'code', 'name', 'description', 'unit',
            'vat_rate_id', 'prices_include_vat', 'base_currency_code',
            'allow_exchange_rate_conversion', 'archived', 'created_at',
            'updated_at',
        ];
    }

    /**
     * `unit` a `base_currency_code` jsou uložené kódy bez fyzického FK.
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
            self::reference('supplier_id', 'supplier', CompanyBackupReferenceMapping::TenantId),
            self::reference('vat_rate_id', 'vat_rates', CompanyBackupReferenceMapping::GlobalNaturalKey),
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
    ): array {
        return [
            'columns' => [$column],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => $mapping->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }
}
