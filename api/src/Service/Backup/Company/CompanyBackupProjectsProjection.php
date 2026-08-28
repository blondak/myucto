<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce zakázek včetně nepřímého vlastnictví přes klienta. */
final class CompanyBackupProjectsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'client_id',
            'name',
            'payment_due_days',
            'billing_emails_mode',
            'payment_due_unit',
            'project_number',
            'contract_number',
            'budget_total',
            'budget_yearly',
            'budget_monthly',
            'hourly_rate',
            'currency_id',
            'status',
            'requires_work_report_approval',
            'note',
            'default_revenue_category_id',
            'archived_at',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * Kategorie tržby je aplikační soft reference bez fyzického FK. Při obnově
     * se přesto musí remapovat stejně jako fyzické tenantové reference.
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
            self::tenant('client_id', 'clients'),
            self::tenant('currency_id', 'currencies'),
            self::tenant(
                'default_revenue_category_id',
                'revenue_categories',
                nullable: true,
                constraint: CompanyBackupReferenceConstraint::Optional,
            ),
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
}
