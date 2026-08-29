<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce pracovněprávního vztahu zaměstnance.
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
final class CompanyBackupPayrollEmploymentsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employee_id',
            'office_id',
            'code',
            'relation_type',
            'status',
            'is_primary',
            'start_date',
            'actual_start_date',
            'end_date',
            'archived_at',
            'monthly_gross_minor',
            'is_legacy_projection',
            'row_version',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<string> */
    public static function generatedColumns(): array
    {
        return ['legacy_projection_key', 'primary_employee_key'];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            [
                'columns' => ['supplier_id', 'employee_id'],
                'target' => 'table:payroll_employees',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id', 'office_id'],
                'target' => 'table:payroll_offices',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['office_id'],
                'fallbacks' => [],
            ],
        ];
    }
}
