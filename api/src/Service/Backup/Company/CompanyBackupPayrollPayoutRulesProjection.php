<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce pravidel rozdělení čisté mzdy.
 *
 * Bankovní cíl obsahuje instanční ID ve tvaru `account:{id}` a přemapuje se
 * jen ve větvi `destination_kind=bank`. Hotovostní NULL i tenantový kód účtu
 * partnerského zápočtu se zachovají bez pokusu o číselný remap.
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
final class CompanyBackupPayrollPayoutRulesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employee_id',
            'allocation_reference',
            'destination_kind',
            'destination_reference',
            'allocation_kind',
            'amount_minor',
            'basis_points',
            'priority_no',
            'is_active',
            'row_version',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<string> */
    public static function generatedColumns(): array
    {
        return ['remainder_guard'];
    }

    /** @return list<array<string,mixed>> */
    public static function encodedReferences(): array
    {
        return [[
            'column' => 'destination_reference',
            'condition' => [
                'column' => 'destination_kind',
                'equals' => 'bank',
            ],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'nullable' => false,
            'target' => 'table:payroll_person_accounts',
            'target_columns' => ['id'],
            'value_prefix' => 'account:',
            'value_suffix_separator' => null,
        ]];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [[
            'columns' => ['supplier_id', 'employee_id'],
            'target' => 'table:payroll_employees',
            'target_columns' => ['supplier_id', 'id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ]];
    }
}
