<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce verzovaných pracovních směn.
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
final class CompanyBackupPayrollShiftsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employment_id',
            'calendar_id',
            'series_key',
            'revision_no',
            'supersedes_id',
            'starts_at_utc',
            'ends_at_utc',
            'timezone_name',
            'break_minutes',
            'remote_work',
            'standby_minutes',
            'status',
            'row_version',
            'created_by',
            'published_by',
            'published_at',
            'created_at',
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::actor('created_by'),
            self::actor('published_by'),
            self::tenant(
                'calendar_id',
                'payroll_work_calendars',
                nullable: true,
            ),
            self::tenant('employment_id', 'payroll_employments'),
            self::tenant('supersedes_id', 'payroll_shifts', nullable: true),
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
