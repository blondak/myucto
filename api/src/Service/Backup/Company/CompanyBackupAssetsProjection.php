<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce evidence dlouhodobého majetku. */
final class CompanyBackupAssetsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'inventory_number',
            'name',
            'description',
            'kind',
            'asset_account_code',
            'accumulated_account_code',
            'acquisition_account_code',
            'purchase_invoice_id',
            'purchase_invoice_item_id',
            'input_price',
            'acquisition_date',
            'put_into_use_date',
            'disposal_date',
            'disposal_type',
            'disposal_price',
            'sale_invoice_id',
            'status',
            'tax_method',
            'tax_group',
            'tax_first_year_increase',
            'is_first_owner',
            'depreciator_ground',
            'is_m1_vehicle',
            'm1_limit_exception',
            'is_zero_emission',
            'opening_tax_years',
            'opening_tax_amount',
            'opening_acc_months',
            'opening_acc_amount',
            'acc_useful_life_months',
            'acc_method',
            'acc_residual_value',
            'created_by',
            'created_at',
            'updated_at',
            'co_ownership_share',
            'depreciator_note',
        ];
    }

    /**
     * Účtové kódy a purchase_invoice_item_id jsou aplikační soft
     * reference bez fyzického FK; obnova je přesto musí ověřit a přemapovat.
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
            self::actor('created_by'),
            self::tenant(
                'purchase_invoice_id',
                'purchase_invoices',
                nullable: true,
            ),
            self::tenant(
                'purchase_invoice_item_id',
                'purchase_invoice_items',
                nullable: true,
                constraint: CompanyBackupReferenceConstraint::Optional,
            ),
            self::tenant('sale_invoice_id', 'invoices', nullable: true),
            self::account('accumulated_account_code', nullable: true),
            self::account('acquisition_account_code'),
            self::account('asset_account_code'),
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
    private static function account(
        string $column,
        bool $nullable = false,
    ): array {
        return [
            'columns' => ['supplier_id', $column],
            'target' => 'table:chart_of_accounts',
            'target_columns' => ['supplier_id', 'account_code'],
            'mapping' => CompanyBackupReferenceMapping::TenantNaturalKey->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
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
        CompanyBackupReferenceConstraint $constraint = CompanyBackupReferenceConstraint::Required,
    ): array {
        return [
            'columns' => [$column],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => $constraint->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }

    /**
     * @return array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function actor(string $column): array
    {
        return [
            'columns' => [$column],
            'target' => 'table:users',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::Actor->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => [$column],
            'fallbacks' => ['null', 'restore_actor'],
        ];
    }
}
