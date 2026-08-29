<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce účinných definic mzdových složek.
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
final class CompanyBackupPayrollComponentDefinitionsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'code',
            'name',
            'component_kind',
            'value_kind',
            'frequency_kind',
            'tax_treatment',
            'social_participation_treatment',
            'social_treatment',
            'health_participation_treatment',
            'health_treatment',
            'average_earning_treatment',
            'enforcement_treatment',
            'jmhz_treatment',
            'statistics_treatment',
            'accounting_debit_code',
            'accounting_credit_code',
            'annual_limit_minor',
            'exemption_basket',
            'exemption_basis',
            'valid_from',
            'valid_to',
            'is_active',
            'row_version',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::account('accounting_credit_code'),
            self::account('accounting_debit_code'),
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

    /** @return TableReference */
    private static function account(string $column): array
    {
        return [
            'columns' => ['supplier_id', $column],
            'target' => 'table:chart_of_accounts',
            'target_columns' => ['supplier_id', 'account_code'],
            'mapping' => CompanyBackupReferenceMapping::TenantNaturalKey->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => [$column],
            'fallbacks' => [],
        ];
    }
}
