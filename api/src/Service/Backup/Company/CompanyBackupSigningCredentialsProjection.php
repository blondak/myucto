<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/** Registry metadata smíšených firemních a osobních podpisových credentialů. */
final class CompanyBackupSigningCredentialsProjection
{
    /** @return list<string> */
    public static function columns(): array
    {
        return [
            'id',
            'profile_id',
            'vault_credential_id',
            'certificate_path',
            'certificate_fingerprint',
            'certificate_subject',
            'certificate_email',
            'certificate_valid_from',
            'certificate_valid_to',
            'certificate_usage_json',
            'passphrase_policy',
            'passphrase_profile_id',
            'encrypted_passphrase',
            'is_active',
            'created_by',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    /** @return list<string> */
    public static function nullableColumns(): array
    {
        return [
            'vault_credential_id',
            'certificate_path',
            'certificate_fingerprint',
            'certificate_subject',
            'certificate_email',
            'certificate_valid_from',
            'certificate_valid_to',
            'certificate_usage_json',
            'passphrase_profile_id',
            'encrypted_passphrase',
            'created_by',
            'deleted_at',
        ];
    }

    /** @return array<string,string> */
    public static function ownership(): array
    {
        return [
            'owner_column' => 'owner_user_id',
            'profile_column' => 'profile_id',
            'profile_table' => 'signing_profiles',
            'strategy' => 'profile_scope',
            'supplier_column' => 'supplier_id',
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function references(): array
    {
        return [
            [
                'columns' => ['created_by'],
                'target' => 'table:users',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::Actor->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['created_by'],
                'fallbacks' => ['null', 'restore_actor'],
            ],
            [
                'columns' => ['profile_id'],
                'target' => 'table:signing_profiles',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ],
            [
                'columns' => ['vault_credential_id'],
                'target' => 'table:epo_signing_credentials',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::CredentialDecision->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['vault_credential_id'],
                'fallbacks' => [],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function metadata(): array
    {
        return [
            'columns' => self::columns(),
            'default_action' => 'omit_row',
            'nullable_columns' => self::nullableColumns(),
            'references' => self::references(),
            'restore_overrides' => [
                'is_active' => [
                    'value' => 0,
                    'reason' => 'disable_signing_credential_after_restore',
                ],
            ],
            'source_columns' => [
                'file' => 'certificate_path',
                'vault' => 'vault_credential_id',
            ],
            'transport_columns' => [
                'certificate_path' =>
                    CompanyBackupCredentialTransport::SecretAttachment->value,
                'encrypted_passphrase' =>
                    CompanyBackupCredentialTransport::SecretEnvelope->value,
                'passphrase_profile_id' =>
                    CompanyBackupCredentialTransport::ExternalReference->value,
            ],
            'variants' => [
                [
                    'name' => 'company_file',
                    'owner' => 'company',
                    'policy' => TenantSecretPolicy::OptionalCredential->value,
                    'source' => 'file',
                ],
                [
                    'name' => 'personal_file',
                    'owner' => 'personal',
                    'policy' => TenantSecretPolicy::PersonalWithDualConsent->value,
                    'source' => 'file',
                ],
                [
                    'name' => 'personal_vault',
                    'owner' => 'personal',
                    'policy' => TenantSecretPolicy::PersonalWithDualConsent->value,
                    'source' => 'vault',
                ],
            ],
        ];
    }
}
