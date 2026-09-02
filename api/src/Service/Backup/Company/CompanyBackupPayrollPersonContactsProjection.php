<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/**
 * Úplná company projekce chráněných kontaktů zaměstnance.
 *
 * Hodnota jde povinně přes secret envelope. Typ kontaktu určuje normalizaci,
 * hash, masku i AAD; zdroj a cílový materializer proto sdílejí jeden
 * fingerprintovaný selektor. Unikátnost hodnoty v DB obsahuje instalační hash,
 * takže se nad ní nedeklaruje nepřenositelný natural key.
 *
 * @phpstan-type FieldSelector array{
 *   cases:list<array{equals:string,field:string}>,
 *   discriminator_column:string
 * }
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
 *   field:FieldSelector,
 *   materializer:string,
 *   nullable:bool,
 *   secret_column:string,
 *   target_columns:array{ciphertext:string,lookup_hash:string,masked:string},
 *   tenant_id_column:string
 * }
 */
final class CompanyBackupPayrollPersonContactsProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employee_id',
            'contact_type',
            'is_primary',
            'is_active',
            'row_version',
            'created_at',
            'updated_at',
        ];
    }

    /** @return array<string,string> */
    public static function omitColumns(): array
    {
        return [
            'contact_value_hash' => 'rederived_from_protected_secret',
            'contact_value_masked' => 'rederived_from_protected_secret',
        ];
    }

    /** @return FieldSelector */
    public static function fieldSelector(): array
    {
        return [
            'cases' => [
                ['equals' => 'email', 'field' => 'contact_email'],
                ['equals' => 'phone', 'field' => 'contact_phone'],
            ],
            'discriminator_column' => 'contact_type',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function secrets(): array
    {
        return [
            'contact_value_ciphertext' => [
                'policy' => TenantSecretPolicy::ProtectedDomainSecret->value,
                'storage' =>
                    CompanyBackupSecretStorage::ApplicationEncryptedContext->value,
                'context' => [
                    'entity_id_column' => 'id',
                    'field' => self::fieldSelector(),
                    'scheme' =>
                        CompanyBackupProtectedSecretMaterializer::PayrollSensitiveV1->value,
                    'tenant_id_column' => 'supplier_id',
                ],
            ],
        ];
    }

    /** @return list<SecretMaterialization> */
    public static function protectedSecretMaterializations(): array
    {
        return [[
            'entity_id_column' => 'id',
            'field' => self::fieldSelector(),
            'materializer' =>
                CompanyBackupProtectedSecretMaterializer::PayrollSensitiveV1->value,
            'nullable' => false,
            'secret_column' => 'contact_value_ciphertext',
            'target_columns' => [
                'ciphertext' => 'contact_value_ciphertext',
                'lookup_hash' => 'contact_value_hash',
                'masked' => 'contact_value_masked',
            ],
            'tenant_id_column' => 'supplier_id',
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
