<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce časově účinných opakovaných mzdových složek.
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
final class CompanyBackupPayrollRecurringComponentsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employment_id',
            'component_id',
            'calculation_kind',
            'amount_minor',
            'rate_basis_points',
            'valid_from',
            'valid_to',
            'allocation_rule',
            'maximum_amount_minor',
            'note',
            'is_active',
            'row_version',
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::actor('created_by'),
            self::tenant('component_id', 'payroll_component_definitions'),
            self::tenant('employment_id', 'payroll_employments'),
            self::actor('updated_by'),
        ];
    }

    /** @return array<string,RestoreOverride> */
    public static function restoreOverrides(): array
    {
        return [
            'is_active' => [
                'value' => 0,
                'reason' => 'disable_payroll_automation_after_restore',
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
    private static function tenant(string $column, string $target): array
    {
        return [
            'columns' => ['supplier_id', $column],
            'target' => 'table:' . $target,
            'target_columns' => ['supplier_id', 'id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }
}
