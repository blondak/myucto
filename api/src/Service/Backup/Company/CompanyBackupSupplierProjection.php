<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Nastavení firmy; credentialy a pseudonymizační klíč mají samostatné obálky. */
final class CompanyBackupSupplierProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'company_name', 'display_name', 'street', 'city', 'zip',
            'country_id', 'ic', 'dic', 'is_vat_payer', 'is_identified',
            'oss_enabled', 'oss_valid_from', 'oss_valid_to', 'oss_identification_country',
            'oss_return_currency', 'email', 'phone', 'web', 'tagline',
            'email_branding_enabled', 'email_accent_color', 'pdf_logo_show_name',
            'branding_profiles_enabled', 'default_branding_profile_id', 'commercial_register',
            'default_currency_id', 'default_vat_rate_id', 'default_payment_due_days',
            'default_payment_due_unit', 'default_hourly_rate', 'auto_send_reminders',
            'reminder_days_after_due', 'payment_thanks_enabled', 'payment_thanks_auto_send',
            'payment_thanks_default_checked', 'payment_thanks_attach_paid_pdf', 'self_copy',
            'embed_isdoc', 'auto_generate_recurring', 'logo_path', 'signature_path',
            'pohoda_account_code', 'pohoda_centre_code', 'pohoda_activity_code',
            'pohoda_contract_code', 'pohoda_accounting_code', 'created_at', 'updated_at',
            'idoklad_last_imported_at', 'invoice_number_format', 'proforma_number_format',
            'credit_note_number_format', 'invoice_number_period', 'fakturoid_slug',
            'fakturoid_email', 'fakturoid_last_imported_at', 'anthropic_default_model',
            'anthropic_extractions_count', 'taxpayer_type', 'vat_period',
            'financial_office_code', 'workplace_code', 'cz_nace_code', 'data_box_type',
            'data_box_id', 'sest_jmeno', 'sest_prijmeni', 'sest_telefon', 'sest_email',
            'sest_funkce', 'street_number_pop', 'street_number_orient', 'opr_jmeno',
            'opr_prijmeni', 'opr_postaveni', 'flat_tax_band', 'default_prices_include_vat',
            'purchase_invoice_number_format', 'abo_client_number', 'accounting_mode',
            'accounting_enabled', 'payroll_enabled', 'proforma_payment_document',
            'accounting_starts_on', 'accounting_activation_status', 'stock_enabled',
            'stock_method', 'stock_auto_issue', 'ai_provider', 'ai_data_region',
            'ai_eu_residency_required', 'azure_openai_endpoint', 'azure_openai_deployment',
            'azure_openai_api_version', 'azure_extractions_count', 'openai_base_url',
            'openai_default_model', 'openai_extractions_count', 'gemini_default_model',
            'gemini_extractions_count', 'cssz_vsdp', 'cssz_ossz_code',
            'health_insurance_number', 'health_insurance_code', 'auto_post_invoices',
            'auto_post_purchases', 'ai_assist_enabled', 'ai_assist_scope',
            'ai_dpa_confirmations', 'stock_in_transit_from', 'stock_order_price_tolerance_pct',
            'ai_extraction_notes', 'ai_effort',
        ];
    }

    /**
     * @return list<array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,fallbacks:list<string>
     * }>
     */
    public static function references(): array
    {
        return [
            self::reference('country_id', 'countries', 'global_natural_key'),
            self::reference('default_branding_profile_id', 'branding_profiles', 'tenant_id', true),
            self::reference('default_currency_id', 'currencies', 'tenant_id'),
            self::reference('default_vat_rate_id', 'vat_rates', 'global_natural_key'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        return [[
            'column' => 'ai_dpa_confirmations', 'path' => ['*', 'user_id'],
            'target' => 'table:users', 'target_columns' => ['id'],
            'mapping' => 'actor', 'nullable' => true, 'condition' => null,
            // Historické potvrzení se nesmí automaticky připsat obnovujícímu uživateli.
            'fallbacks' => [],
        ]];
    }

    /** @return array<string,array{value:int,reason:string}> */
    public static function restoreOverrides(): array
    {
        $overrides = [];
        foreach ([
            'auto_send_reminders', 'payment_thanks_auto_send', 'auto_generate_recurring',
            'stock_auto_issue', 'auto_post_invoices', 'auto_post_purchases', 'ai_assist_enabled',
        ] as $column) {
            $overrides[$column] = ['value' => 0, 'reason' => 'disable_automation_after_restore'];
        }
        return $overrides;
    }

    /** @param array<string,mixed> $row */
    public static function assertSourceRow(array $row): void
    {
        // Starý sloupec nemá v aplikaci čtecí ani upload cestu ani známou souborovou oblast.
        // Nezahazovat případný historický soubor a nekopírovat neověřenou cestu do nové firmy.
        if (($row['signature_path'] ?? null) !== null && $row['signature_path'] !== '') {
            throw new CompanyBackupDataSourceException('data_supplier_legacy_signature_unsupported',
                'table:supplier', 'signature_path');
        }
        if (($row['accounting_activation_status'] ?? null) === 'running') {
            throw new CompanyBackupDataSourceException('data_supplier_activation_running',
                'table:supplier', 'accounting_activation_status');
        }
    }

    /**
     * @return array{
     *   columns:list<string>,target:string,target_columns:list<string>,
     *   mapping:string,constraint:string,nullable_columns:list<string>,fallbacks:list<string>
     * }
     */
    private static function reference(string $column, string $table, string $mapping, bool $nullable = false): array
    {
        return [
            'columns' => [$column], 'target' => 'table:' . $table, 'target_columns' => ['id'],
            'mapping' => $mapping, 'constraint' => $column === 'default_branding_profile_id' ? 'optional' : 'required',
            'nullable_columns' => $nullable ? [$column] : [], 'fallbacks' => [],
        ];
    }
}
