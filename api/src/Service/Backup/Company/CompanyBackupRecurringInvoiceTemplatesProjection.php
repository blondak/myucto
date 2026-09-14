<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Kontrakt šablon; aktivace vyžaduje položky a jejich ceníkové závislosti. */
final class CompanyBackupRecurringInvoiceTemplatesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id', 'supplier_id', 'branding_profile_id', 'client_id', 'project_id',
            'name', 'frequency', 'day_of_month', 'end_of_month', 'anchor_date',
            'end_date', 'next_run_date', 'last_run_date', 'last_error',
            'last_error_at', 'invoice_type', 'currency_id', 'language',
            'payment_method', 'reverse_charge', 'discount_percent',
            'revenue_category_id', 'payment_due_days', 'payment_due_unit',
            'tax_date_mode', 'draft_open_mode', 'reminder_days_before',
            'last_reminder_date', 'note_above_items', 'note_below_items',
            'increment_month_in_descriptions', 'auto_issue', 'auto_send_email',
            'status', 'created_by', 'created_at', 'updated_at', 'prices_include_vat',
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function references(): array
    {
        return [
            self::tenant('branding_profile_id', 'branding_profiles', true),
            self::tenant('client_id', 'clients'),
            [
                'columns' => ['created_by'], 'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [], 'fallbacks' => ['restore_actor'],
            ],
            self::tenant('currency_id', 'currencies'),
            self::tenant('project_id', 'projects', true),
            self::tenant('revenue_category_id', 'revenue_categories', true,
                CompanyBackupReferenceConstraint::Optional),
            self::tenant('supplier_id', 'supplier'),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function restoreOverrides(): array
    {
        return [
            'auto_issue' => ['value' => 0, 'reason' => 'disable_recurring_automation_after_restore'],
            'auto_send_email' => ['value' => 0, 'reason' => 'disable_recurring_automation_after_restore'],
            'status' => [
                'value' => 'paused',
                'reason' => 'pause_active_recurring_template_after_restore',
                'when' => ['column' => 'status', 'values' => ['active']],
            ],
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
            'columns' => [$column], 'target' => 'table:' . $table,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => $constraint->value,
            'nullable_columns' => $nullable ? [$column] : [], 'fallbacks' => [],
        ];
    }
}
