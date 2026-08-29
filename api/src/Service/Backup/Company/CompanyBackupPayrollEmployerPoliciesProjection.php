<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce časově účinných politik zaměstnavatele.
 *
 * @phpstan-type TableReference array{
 *   columns:list<string>,
 *   target:string,
 *   target_columns:list<string>,
 *   mapping:string,
 *   constraint:string,
 *   nullable_columns:list<string>,
 *   fallbacks:list<string>
 * }
 * @phpstan-type RestoreOverride array{
 *   value:string|int|bool|null,
 *   reason:string
 * }
 */
final class CompanyBackupPayrollEmployerPoliciesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'valid_from',
            'valid_to',
            'payday_day',
            'payday_month_offset',
            'payday_business_day_rule',
            'balance_rounding_mode',
            'home_office_policy',
            'travel_expense_policy',
            'leave_entitlement_weeks',
            'four_eyes_required',
            'automatic_calculation_enabled',
            'automatic_posting_enabled',
            'automatic_payments_enabled',
            'delivery_channel',
            'delivery_verified_on',
            'source_kind',
            'source_reference',
            'created_by',
            'updated_by',
            'row_version',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::actor('created_by'),
            self::supplier(),
            self::actor('updated_by'),
        ];
    }

    /** @return array<string,RestoreOverride> */
    public static function restoreOverrides(): array
    {
        return [
            'automatic_calculation_enabled' => [
                'value' => 0,
                'reason' => 'disable_payroll_automation_after_restore',
            ],
            'automatic_payments_enabled' => [
                'value' => 0,
                'reason' => 'disable_payroll_automation_after_restore',
            ],
            'automatic_posting_enabled' => [
                'value' => 0,
                'reason' => 'disable_payroll_automation_after_restore',
            ],
            'delivery_channel' => [
                'value' => 'disabled',
                'reason' => 'disable_payroll_delivery_after_restore',
            ],
            'delivery_verified_on' => [
                'value' => null,
                'reason' => 'require_payroll_delivery_reverification_after_restore',
            ],
        ];
    }

    /** @return TableReference */
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

    /** @return TableReference */
    private static function supplier(): array
    {
        return [
            'columns' => ['supplier_id'],
            'target' => 'table:supplier',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }
}
