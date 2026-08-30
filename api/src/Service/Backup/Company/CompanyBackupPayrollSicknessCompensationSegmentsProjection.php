<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce směnových segmentů náhrady DPN.
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
final class CompanyBackupPayrollSicknessCompensationSegmentsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'sickness_event_id',
            'shift_id',
            'local_date',
            'planned_minutes',
            'eligible_minutes',
            'hourly_average_minor',
            'reduced_hourly_minor',
            'compensation_minor',
            'trace',
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            [
                'columns' => ['supplier_id', 'shift_id'],
                'target' => 'table:payroll_shifts',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['shift_id'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id', 'sickness_event_id'],
                'target' => 'table:payroll_sickness_events',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function embeddedReferences(): array
    {
        return [[
            'column' => 'trace',
            'condition' => null,
            'fallbacks' => [],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => true,
            'path' => ['shift_id'],
            'target' => 'table:payroll_shifts',
            'target_columns' => ['id'],
        ]];
    }
}
