<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/**
 * Úplná company projekce chráněných identifikátorů zaměstnance.
 *
 * EČP, VČP a rodné číslo sdílejí pole osobního identifikátoru, zatímco
 * zahraniční daňový identifikátor má vlastní normalizaci a AAD. Hodnota jde
 * jen přes secret envelope a cíl z plaintextu znovu vytvoří celou instalační
 * trojici. Natural key odpovídá fyzické unikátnosti typu u osoby.
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
final class CompanyBackupPayrollPersonIdentifiersProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'supplier_id',
            'employee_id',
            'identifier_type',
            'row_version',
            'created_at',
            'updated_at',
        ];
    }

    /** @return array<string,string> */
    public static function omitColumns(): array
    {
        return [
            'value_hash' => 'rederived_from_protected_secret',
            'value_masked' => 'rederived_from_protected_secret',
        ];
    }

    /** @return FieldSelector */
    public static function fieldSelector(): array
    {
        return [
            'cases' => [
                ['equals' => 'birth_number', 'field' => 'personal_identifier'],
                ['equals' => 'ecp', 'field' => 'personal_identifier'],
                [
                    'equals' => 'foreign_tax_identifier',
                    'field' => 'foreign_tax_identifier',
                ],
                ['equals' => 'vcp', 'field' => 'personal_identifier'],
            ],
            'discriminator_column' => 'identifier_type',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function secrets(): array
    {
        return [
            'value_ciphertext' => [
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
            'secret_column' => 'value_ciphertext',
            'target_columns' => [
                'ciphertext' => 'value_ciphertext',
                'lookup_hash' => 'value_hash',
                'masked' => 'value_masked',
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
