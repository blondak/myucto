<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce číselníku výrobců. */
final class CompanyBackupManufacturersProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'code',
            'name',
            'website',
            'logo_media_id',
            'display_order',
            'export_eshop',
            'archived',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * Nullable logo uzavírá cyklus přes médium a skladovou kartu. Odložené
     * dosazení reference jej obnoví až po předalokaci všech identit.
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
            self::tenant('logo_media_id', 'stock_media', nullable: true),
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
    private static function tenant(
        string $column,
        string $target,
        bool $nullable = false,
    ): array {
        return [
            'columns' => [$column],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }
}
