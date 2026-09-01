<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/**
 * Úplná company projekce účinné historie účtů mzdových institucí.
 *
 * Číslo účtu jde povinně přes secret envelope. Zdrojový ciphertext, keyed
 * hash ani maska se nevkládají raw; cílový materializer je po remapu ID vytvoří
 * z plaintextu pod instalačními klíči cíle.
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
 * @phpstan-type SecretMaterialization array{
 *   entity_id_column:string,
 *   field:string,
 *   materializer:string,
 *   secret_column:string,
 *   target_columns:array{ciphertext:string,lookup_hash:string,masked:string},
 *   tenant_id_column:string
 * }
 */
final class CompanyBackupPayrollInstitutionAccountsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'institution_id',
            'institution_name',
            'currency_code',
            'variable_symbol',
            'specific_symbol',
            'constant_symbol',
            'valid_from',
            'valid_to',
            'source_kind',
            'source_reference',
            'verified_on',
            'verified_by',
            'created_by',
            'updated_by',
            'row_version',
            'created_at',
            'updated_at',
        ];
    }

    /** @return array<string,string> */
    public static function omitColumns(): array
    {
        return [
            'bank_account_hash' => 'rederived_from_protected_secret',
            'bank_account_masked' => 'rederived_from_protected_secret',
        ];
    }

    /** @return array<string,array<string,string>> */
    public static function secrets(): array
    {
        return [
            'bank_account_ciphertext' => [
                'policy' => TenantSecretPolicy::ProtectedDomainSecret->value,
                'storage' =>
                    CompanyBackupSecretStorage::ApplicationEncryptedContext->value,
                'context' => 'payroll:{supplier_id}:{id}:bank_account',
            ],
        ];
    }

    /** @return list<SecretMaterialization> */
    public static function protectedSecretMaterializations(): array
    {
        return [[
            'entity_id_column' => 'id',
            'field' => 'bank_account',
            'materializer' =>
                CompanyBackupProtectedSecretMaterializer::PayrollSensitiveV1->value,
            'secret_column' => 'bank_account_ciphertext',
            'target_columns' => [
                'ciphertext' => 'bank_account_ciphertext',
                'lookup_hash' => 'bank_account_hash',
                'masked' => 'bank_account_masked',
            ],
            'tenant_id_column' => 'supplier_id',
        ]];
    }

    /** @return list<TableReference> */
    public static function references(): array
    {
        return [
            self::actor('created_by'),
            [
                'columns' => ['supplier_id', 'institution_id'],
                'target' => 'table:payroll_institutions',
                'target_columns' => ['supplier_id', 'id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            self::actor('updated_by'),
            self::actor('verified_by'),
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
