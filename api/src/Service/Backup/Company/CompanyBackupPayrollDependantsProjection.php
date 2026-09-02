<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/**
 * Úplná company projekce vyživovaných osob zaměstnance.
 *
 * Rodné číslo je volitelná atomická trojice. Je-li přítomné, jde jen přes
 * secret envelope; cílový materializer po remapu ID znovu vytvoří instalační
 * ciphertext, tenantový keyed hash i masku. Chybějící hodnota obnoví tři NULL.
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
 *   nullable:bool,
 *   secret_column:string,
 *   target_columns:array{ciphertext:string,lookup_hash:string,masked:string},
 *   tenant_id_column:string
 * }
 */
final class CompanyBackupPayrollDependantsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employee_id',
            'relation',
            'full_name',
            'birth_date',
            'ztp_p',
            'student',
            'existence_from',
            'existence_to',
            'note',
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
            'birth_number_hash' => 'rederived_from_protected_secret',
            'birth_number_masked' => 'rederived_from_protected_secret',
        ];
    }

    /** @return array<string,array<string,string>> */
    public static function secrets(): array
    {
        return [
            'birth_number_ciphertext' => [
                'policy' => TenantSecretPolicy::ProtectedDomainSecret->value,
                'storage' =>
                    CompanyBackupSecretStorage::ApplicationEncryptedContext->value,
                'context' => 'payroll:{supplier_id}:{id}:personal_identifier',
            ],
        ];
    }

    /** @return list<SecretMaterialization> */
    public static function protectedSecretMaterializations(): array
    {
        return [[
            'entity_id_column' => 'id',
            'field' => 'personal_identifier',
            'materializer' =>
                CompanyBackupProtectedSecretMaterializer::PayrollSensitiveV1->value,
            'nullable' => true,
            'secret_column' => 'birth_number_ciphertext',
            'target_columns' => [
                'ciphertext' => 'birth_number_ciphertext',
                'lookup_hash' => 'birth_number_hash',
                'masked' => 'birth_number_masked',
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
                'columns' => ['supplier_id', 'employee_id'],
                'target' => 'table:payroll_employees',
                'target_columns' => ['supplier_id', 'id'],
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
