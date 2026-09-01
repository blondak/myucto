<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce výplatního profilu zaměstnance.
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
final class CompanyBackupPayrollEmployeeProfilesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'supplier_id',
            'employee_id',
            'profile_status',
            'payout_method',
            'partner_settlement_account_code',
            'cash_allocation_basis_points',
            'payout_effective_on',
            'secure_delivery_channel',
            'row_version',
            'created_at',
            'updated_at',
        ];
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
                'columns' => [
                    'supplier_id',
                    'partner_settlement_account_code',
                ],
                'target' => 'table:chart_of_accounts',
                'target_columns' => ['supplier_id', 'account_code'],
                'mapping' =>
                    CompanyBackupReferenceMapping::TenantNaturalKey->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['partner_settlement_account_code'],
                'fallbacks' => [],
            ],
        ];
    }
}
