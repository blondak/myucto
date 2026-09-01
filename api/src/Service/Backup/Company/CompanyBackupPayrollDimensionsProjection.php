<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Úplná company projekce účinného číselníku mzdových dimenzí.
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
final class CompanyBackupPayrollDimensionsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'dimension_type',
            'code',
            'name',
            'valid_from',
            'valid_to',
            'is_active',
            'default_account_code',
            'created_by',
            'updated_by',
            'row_version',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::actor('created_by'),
            [
                'columns' => ['supplier_id', 'default_account_code'],
                'target' => 'table:chart_of_accounts',
                'target_columns' => ['supplier_id', 'account_code'],
                'mapping' => CompanyBackupReferenceMapping::TenantNaturalKey->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => ['default_account_code'],
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
            self::actor('updated_by'),
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
}
