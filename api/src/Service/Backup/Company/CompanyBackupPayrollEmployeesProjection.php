<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce základního záznamu zaměstnance.
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
final class CompanyBackupPayrollEmployeesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'full_name',
            'birth_date',
            'birth_number',
            'address',
            'taxpayer_type',
            'employment_type',
            'tax_declaration_signed',
            'tax_credit_taxpayer',
            'child_count',
            'net_settlement_account_code',
            'monthly_gross',
            'auto_post',
            'is_active',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            [
                'columns' => [
                    'supplier_id',
                    'net_settlement_account_code',
                ],
                'target' => 'table:chart_of_accounts',
                'target_columns' => ['supplier_id', 'account_code'],
                'mapping' =>
                    CompanyBackupReferenceMapping::TenantNaturalKey->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['net_settlement_account_code'],
                'fallbacks' => [],
            ],
            [
                'columns' => ['supplier_id'],
                'target' => 'table:supplier',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
        ];
    }

    /** @return array<string,RestoreOverride> */
    public static function restoreOverrides(): array
    {
        return [
            'auto_post' => [
                'value' => 0,
                'reason' => 'disable_payroll_automation_after_restore',
            ],
        ];
    }
}
