<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce odběratelů a dodavatelů firmy. */
final class CompanyBackupClientsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'company_name',
            'first_name',
            'last_name',
            'ic',
            'dic',
            'tax_number',
            'street',
            'city',
            'zip',
            'country_id',
            'main_email',
            'phone',
            'language',
            'currency_default_id',
            'vat_rate_default_id',
            'reverse_charge',
            'oss_mode',
            'oss_default_supply_type',
            'is_customer',
            'is_vendor',
            'is_fuel_station',
            'idoklad_id',
            'auto_send_reminders',
            'payment_due_default',
            'payment_due_unit',
            'hourly_rate',
            'note',
            'default_expense_category_id',
            'default_revenue_category_id',
            'invoice_number_format',
            'proforma_number_format',
            'credit_note_number_format',
            'invoice_number_period',
            'default_branding_profile_id',
            'archived_at',
            'created_at',
            'updated_at',
            'fakturoid_id',
            'is_vat_payer',
            'default_payment_method',
            'related_party',
            'related_party_type',
            'related_party_note',
        ];
    }

    /** @return list<string> */
    public static function preservedIdentifiers(): array
    {
        return ['fakturoid_id', 'idoklad_id'];
    }

    /**
     * `idoklad_id` a `fakturoid_id` jsou identity v externích systémech, ne
     * lokální reference. Země a sazba DPH se naopak přes zdrojový globální
     * řádek obnovují podle jeho natural key.
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
            self::global('country_id', 'countries'),
            self::tenant('currency_default_id', 'currencies'),
            self::tenant(
                'default_branding_profile_id',
                'branding_profiles',
                nullable: true,
            ),
            self::tenant(
                'default_expense_category_id',
                'expense_categories',
                nullable: true,
                constraint: CompanyBackupReferenceConstraint::Optional,
            ),
            self::tenant(
                'default_revenue_category_id',
                'revenue_categories',
                nullable: true,
                constraint: CompanyBackupReferenceConstraint::Optional,
            ),
            self::tenant('supplier_id', 'supplier'),
            self::global('vat_rate_default_id', 'vat_rates', nullable: true),
        ];
    }

    /**
     * @return array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function tenant(
        string $column,
        string $target,
        bool $nullable = false,
        CompanyBackupReferenceConstraint $constraint = CompanyBackupReferenceConstraint::Required,
    ): array {
        return self::reference(
            $column,
            $target,
            CompanyBackupReferenceMapping::TenantId,
            $nullable,
            $constraint,
        );
    }

    /**
     * @return array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function global(
        string $column,
        string $target,
        bool $nullable = false,
    ): array {
        return self::reference(
            $column,
            $target,
            CompanyBackupReferenceMapping::GlobalNaturalKey,
            $nullable,
            CompanyBackupReferenceConstraint::Required,
        );
    }

    /**
     * @return array{
     *   columns:list<string>,
     *   target:string,
     *   target_columns:list<string>,
     *   mapping:string,
     *   constraint:string,
     *   nullable_columns:list<string>,
     *   fallbacks:list<string>
     * }
     */
    private static function reference(
        string $column,
        string $target,
        CompanyBackupReferenceMapping $mapping,
        bool $nullable,
        CompanyBackupReferenceConstraint $constraint,
    ): array {
        return [
            'columns' => [$column],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => $mapping->value,
            'constraint' => $constraint->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }
}
