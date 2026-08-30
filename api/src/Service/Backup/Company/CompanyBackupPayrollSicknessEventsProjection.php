<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce reprodukovatelných výpočtů náhrady DPN.
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
 */
final class CompanyBackupPayrollSicknessEventsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'absence_id',
            'first_day_fully_worked',
            'insurance_eligibility_confirmed',
            'conflicting_benefit_excluded',
            'average_snapshot_id',
            'compensation_window_from',
            'compensation_window_to',
            'reduced_hourly_minor',
            'compensation_minor',
            'support_status',
            'ruleset_id',
            'ruleset_hash',
            'calculation_trace',
            'row_version',
            'calculated_by',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<string> */
    public static function preservedIdentifiers(): array
    {
        return ['ruleset_id'];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            [
                'columns' => ['calculated_by'],
                'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['calculated_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            self::tenant('absence_id', 'payroll_absences'),
            self::tenant(
                'average_snapshot_id',
                'payroll_average_earning_snapshots',
            ),
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
