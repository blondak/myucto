<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce schvalovacího životního cyklu absencí.
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
final class CompanyBackupPayrollAbsencesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employment_id',
            'absence_type',
            'date_from',
            'date_to',
            'timezone_name',
            'partial_first_minutes',
            'partial_last_minutes',
            'note',
            'compensation_policy',
            'compensation_rate_basis_points',
            'average_snapshot_id',
            'support_status',
            'status',
            'correction_pending',
            'row_version',
            'requested_by',
            'decided_by',
            'decided_at',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::actor('decided_by'),
            self::actor('requested_by'),
            self::tenant(
                'average_snapshot_id',
                'payroll_average_earning_snapshots',
                nullable: true,
            ),
            self::tenant('employment_id', 'payroll_employments'),
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
    private static function tenant(
        string $column,
        string $target,
        bool $nullable = false,
    ): array {
        return [
            'columns' => ['supplier_id', $column],
            'target' => 'table:' . $target,
            'target_columns' => ['supplier_id', 'id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => [],
        ];
    }
}
