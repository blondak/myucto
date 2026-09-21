<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/**
 * Kontrakt uložených sloupců hlavičky faktury. Aktivace vyžaduje samostatné
 * ošetření snapshotů, souborových cest a kolizí schvalovacích doručenek.
 */
final class CompanyBackupInvoicesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'supplier_id', 'branding_profile_id', 'varsymbol',
            'invoice_type', 'parent_invoice_id', 'recurring_template_id',
            'client_id', 'project_id', 'issue_date', 'tax_date',
            'corrective_delivered_on', 'due_date', 'currency_id',
            'exchange_rate', 'exchange_rate_date', 'reverse_charge',
            'is_simplified', 'auto_send_reminders', 'language',
            'note_above_items', 'note_below_items', 'advance_paid_amount',
            'paid_total', 'discount_percent', 'client_snapshot',
            'supplier_snapshot', 'bank_snapshot', 'total_without_vat',
            'total_vat', 'total_with_vat', 'rounding', 'status', 'booked_at',
            'booked_by', 'payment_method', 'cash_register_id',
            'approval_status', 'approval_receipt_hash',
            'approval_token_expires_at', 'approval_requested_at',
            'approval_decided_at', 'approval_reminder_at',
            'approval_reminder_count', 'approval_decided_by_email',
            'approval_rejection_reason', 'sent_at', 'last_reminder_at',
            'reminder_count', 'paid_at', 'cancelled_at', 'pdf_path',
            'pdf_generated_at', 'public_viewed_at', 'created_by',
            'created_at', 'updated_at', 'idoklad_id', 'imported_pdf_path',
            'imported_pdf_hash', 'imported_pdf_size_bytes',
            'imported_pdf_original_name', 'fakturoid_id',
            'revenue_category', 'vat_classification_code',
            'revenue_category_id', 'revenue_rule_key',
            'payment_thanks_sent_at', 'payment_thanks_sent_to',
            'prices_include_vat', 'income_tax_exempt',
            'income_tax_exempt_reason', 'penalty_covered_through',
        ];
    }

    /** @return list<string> */
    public static function generatedColumns(): array
    {
        return ['amount_to_pay', 'effective_tax_date'];
    }

    /** @return list<string> */
    public static function preservedIdentifiers(): array
    {
        return ['idoklad_id', 'fakturoid_id'];
    }

    /** @return array<string,array{value:null,reason:string}> */
    public static function restoreOverrides(): array
    {
        // Obě hodnoty resetovat společně: původní cesta nesmí být dostupná a čas
        // generování nesmí povolit opětovné použití osiřelé cache na disku.
        return [
            'pdf_path' => ['value' => null, 'reason' => 'regenerate_invoice_pdf_cache_after_restore'],
            'pdf_generated_at' => ['value' => null, 'reason' => 'regenerate_invoice_pdf_cache_after_restore'],
        ];
    }

    /** @return array<string,array{policy:string,reason?:string}> */
    public static function secretPolicies(): array
    {
        return [
            'approval_token' => ['policy' => TenantSecretPolicy::OmitAndReconfigure->value],
            'approval_token_expires_at' => [
                'policy' => TenantSecretPolicy::NotSecret->value,
                'reason' => 'expiry_timestamp_without_token_is_inert',
            ],
            'public_token' => ['policy' => TenantSecretPolicy::OmitAndReconfigure->value],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function references(): array
    {
        return [
            self::actor('booked_by', CompanyBackupReferenceConstraint::Optional),
            self::tenant('branding_profile_id', 'branding_profiles', true),
            self::tenant('cash_register_id', 'cash_registers', true),
            self::tenant('client_id', 'clients'),
            self::actor('created_by'),
            self::tenant('currency_id', 'currencies'),
            self::tenant('parent_invoice_id', 'invoices', true),
            self::tenant('project_id', 'projects', true),
            self::tenant('recurring_template_id', 'recurring_invoice_templates', true),
            self::tenant(
                'revenue_category_id', 'revenue_categories', true,
                CompanyBackupReferenceConstraint::Optional,
            ),
            self::tenant('supplier_id', 'supplier'),
        ];
    }

    /** @return array<string,mixed> */
    private static function tenant(
        string $column,
        string $table,
        bool $nullable = false,
        CompanyBackupReferenceConstraint $constraint = CompanyBackupReferenceConstraint::Required,
    ): array {
        return [
            'columns' => [$column],
            'target' => 'table:' . $table,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => $constraint->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function actor(
        string $column,
        CompanyBackupReferenceConstraint $constraint = CompanyBackupReferenceConstraint::Required,
    ): array {
        return [
            'columns' => [$column],
            'target' => 'table:users',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::Actor->value,
            'constraint' => $constraint->value,
            'nullable_columns' => [$column],
            'fallbacks' => ['null', 'restore_actor'],
        ];
    }
}
