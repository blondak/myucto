<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupCredentialTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupCredentialTransport;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupFileAreaProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupPolymorphicReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupPolymorphicReferenceTransform;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretStorage;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Company\CompanyBackupTenantSqlSelector;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PHPUnit\Framework\TestCase;

final class CompanyBackupProductionProjectionTest extends TestCase
{
    public function testCompanyBackupJobsAreRuntimeMetadataOutsidePayload(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition(
            'table:company_backup_jobs',
        );

        self::assertNotNull($definition);
        self::assertSame(TenantDataPolicy::RuntimeDerived, $definition->policy);
        self::assertFalse($definition->policy->hasMachineDataPayload());
        self::assertSame(
            'ephemeral_company_backup_job',
            $definition->details['reason'] ?? null,
        );
        self::assertSame(
            TenantSecretPolicy::OmitAndReconfigure->value,
            $definition->details['secrets']['password_ciphertext']['policy'] ?? null,
        );
    }

    public function testBrandingProfilesDeclareSupplierEmailProfileAndLogo(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:branding_profiles');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'name',
            'display_name',
            'tagline',
            'email',
            'reply_to',
            'email_profile_id',
            'phone',
            'web',
            'email_footer',
            'logo_path',
            'accent_color',
            'branding_enabled',
            'pdf_logo_show_name',
            'is_active',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'display_name',
                    'tagline',
                    'email',
                    'reply_to',
                    'email_profile_id',
                    'phone',
                    'web',
                    'email_footer',
                    'logo_path',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'email_profile_id'],
                        'email_profiles',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id'],
                        'supplier',
                        ['id'],
                    ),
                ],
            ),
        );

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'name'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'supplier_id,email_profile_id->email_profiles:supplier_id,id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            ['email_profile_id'],
            $projection->references->references[0]->nullableColumns,
        );
        self::assertSame([], $projection->restoreOverrides->overrides);

        $emailProfiles = $registry->definition('table:email_profiles');
        self::assertNotNull($emailProfiles);
        self::assertSame(
            ['supplier_id', 'code'],
            $emailProfiles->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'imap_encryption' => [
                    'policy' => TenantSecretPolicy::NotSecret->value,
                    'reason' => 'transport_encryption_mode_without_secret',
                ],
                'imap_password_enc' => [
                    'policy' => TenantSecretPolicy::OptionalCredential->value,
                    'storage' =>
                        CompanyBackupSecretStorage::ApplicationEncrypted->value,
                ],
                'smtp_encryption' => [
                    'policy' => TenantSecretPolicy::NotSecret->value,
                    'reason' => 'transport_encryption_mode_without_secret',
                ],
                'smtp_password_enc' => [
                    'policy' => TenantSecretPolicy::OptionalCredential->value,
                    'storage' =>
                        CompanyBackupSecretStorage::ApplicationEncrypted->value,
                ],
            ],
            $emailProfiles->details['secrets'] ?? null,
        );
        self::assertArrayHasKey('company_backup', $emailProfiles->details);

        $selection = (new CompanyBackupTenantSqlSelector())->select(
            $projection,
            37,
        );
        self::assertSame([37], $selection->params);
        self::assertStringContainsString(
            '`_company_source`.`supplier_id` = ?',
            $selection->where,
        );
    }

    public function testEmailProfilesOmitCredentialsAndDisableDeliveryOnRestore(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:email_profiles');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $runtimeColumns = [
            'id',
            'supplier_id',
            'name',
            'code',
            'from_email',
            'from_name',
            'reply_to_email',
            'reply_to_name',
            'reply_to_enabled',
            'signing_profile_id',
            'dkim_domain',
            'dkim_selector',
            'dkim_enabled',
            'transport_type',
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_auth_enabled',
            'smtp_auth_type',
            'smtp_username',
            'smtp_password_enc',
            'smtp_verify_peer',
            'smtp_verify_peer_name',
            'smtp_allow_self_signed',
            'smtp_timeout',
            'smtp_keepalive',
            'sendmail_command',
            'imap_sent_enabled',
            'imap_host',
            'imap_port',
            'imap_encryption',
            'imap_validate_cert',
            'imap_username',
            'imap_password_enc',
            'imap_folder',
            'imap_create_folder',
            'imap_mark_seen',
            'imap_timeout',
            'imap_on_failure',
            'is_default',
            'is_active',
            'created_by',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
        $dataColumns = array_values(array_diff(
            $runtimeColumns,
            ['imap_password_enc', 'smtp_password_enc'],
        ));

        $projection->assertRuntimeSchema($runtimeColumns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'from_name',
                    'reply_to_email',
                    'reply_to_name',
                    'signing_profile_id',
                    'dkim_domain',
                    'dkim_selector',
                    'smtp_host',
                    'smtp_port',
                    'smtp_username',
                    'smtp_password_enc',
                    'smtp_timeout',
                    'sendmail_command',
                    'imap_host',
                    'imap_port',
                    'imap_username',
                    'imap_password_enc',
                    'imap_folder',
                    'created_by',
                    'deleted_at',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['created_by'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['signing_profile_id'],
                        'signing_profiles',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id'],
                        'supplier',
                        ['id'],
                    ),
                ],
            ),
        );

        self::assertSame($dataColumns, $projection->dataColumns);
        self::assertSame(
            TenantSecretPolicy::OptionalCredential,
            $projection->secretPolicies['imap_password_enc'] ?? null,
        );
        self::assertSame(
            TenantSecretPolicy::OptionalCredential,
            $projection->secretPolicies['smtp_password_enc'] ?? null,
        );
        self::assertSame(
            TenantSecretPolicy::NotSecret,
            $projection->secretPolicies['imap_encryption'] ?? null,
        );
        self::assertSame(
            TenantSecretPolicy::NotSecret,
            $projection->secretPolicies['smtp_encryption'] ?? null,
        );
        self::assertSame(
            [
                'created_by->users:id',
                'signing_profile_id->signing_profiles:id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            ['null', 'restore_actor'],
            $projection->references->references[0]->fallbacks,
        );
        $restored = $projection->restoreOverrides->apply([
            'is_active' => 1,
            'is_default' => 1,
        ]);
        self::assertSame(0, $restored['is_active']);
        self::assertSame(0, $restored['is_default']);

        $signingProfiles = $registry->definition('table:signing_profiles');
        self::assertNotNull($signingProfiles);
        self::assertSame(
            ['supplier_id', 'code'],
            $signingProfiles->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'pdf_tsa_password_enc' => [
                    'policy' => TenantSecretPolicy::OptionalCredential->value,
                    'storage' =>
                        CompanyBackupSecretStorage::ApplicationEncrypted->value,
                ],
            ],
            $signingProfiles->details['secrets'] ?? null,
        );
        self::assertArrayHasKey('company_backup', $signingProfiles->details);
    }

    public function testSigningProfilesKeepPersonalOwnershipAndDisableSigningOnRestore(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:signing_profiles');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $runtimeColumns = [
            'id',
            'supplier_id',
            'owner_user_id',
            'name',
            'code',
            'allowed_usages_json',
            'default_backend',
            'pdf_tsa_url',
            'pdf_tsa_username',
            'pdf_tsa_password_enc',
            'pdf_reason',
            'is_active',
            'created_by',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
        $dataColumns = array_values(array_diff(
            $runtimeColumns,
            ['pdf_tsa_password_enc'],
        ));

        $projection->assertRuntimeSchema($runtimeColumns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'owner_user_id',
                    'pdf_tsa_url',
                    'pdf_tsa_username',
                    'pdf_tsa_password_enc',
                    'pdf_reason',
                    'created_by',
                    'deleted_at',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['created_by'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['owner_user_id'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id'],
                        'supplier',
                        ['id'],
                    ),
                ],
            ),
        );

        self::assertSame($dataColumns, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'code'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            TenantSecretPolicy::OptionalCredential,
            $projection->secretPolicies['pdf_tsa_password_enc'] ?? null,
        );
        self::assertSame(
            [
                'created_by->users:id',
                'owner_user_id->users:id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            ['null', 'restore_actor'],
            $projection->references->references[0]->fallbacks,
        );
        self::assertSame(
            ['restore_actor'],
            $projection->references->references[1]->fallbacks,
        );
        self::assertSame(
            ['owner_user_id'],
            $projection->references->references[1]->nullableColumns,
        );
        $restored = $projection->restoreOverrides->apply(['is_active' => 1]);
        self::assertSame(0, $restored['is_active']);

        $selection = (new CompanyBackupTenantSqlSelector())->select(
            $projection,
            37,
        );
        self::assertSame([37], $selection->params);
        self::assertStringContainsString(
            '`_company_source`.`supplier_id` = ?',
            $selection->where,
        );
    }

    public function testSigningCredentialsDefaultToOmissionAndSeparatePersonalConsent(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $credentials = $registry->definition('table:signing_credentials');
        self::assertNotNull($credentials);
        self::assertSame(TenantDataPolicy::OptionalCredential, $credentials->policy);
        self::assertFalse($credentials->policy->hasMachineDataPayload());
        self::assertSame(
            'omit_row',
            $credentials->details['company_backup_credential']['default_action']
                ?? null,
        );
        self::assertSame(
            [
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
            $credentials->details['company_backup_credential']['variants'] ?? null,
        );
        $projection = CompanyBackupCredentialTableProjection::fromDefinition(
            $credentials,
        );
        $projection->assertRegistryTargets($registry);
        self::assertSame(
            [
                'certificate_path' =>
                    CompanyBackupCredentialTransport::SecretAttachment,
                'encrypted_passphrase' =>
                    CompanyBackupCredentialTransport::SecretEnvelope,
                'passphrase_profile_id' =>
                    CompanyBackupCredentialTransport::ExternalReference,
            ],
            $projection->transportColumns,
        );
        self::assertSame(
            [
                'certificate_path' => [
                    'max_bytes' => 128 * 1024,
                    'path_template' =>
                        'signing/profiles/supplier-{supplier_id}'
                        . '/profile-{profile_id}/profile.p12',
                    'storage_subdirectory' => 'signing/profiles',
                ],
            ],
            $projection->attachmentSources,
        );
        self::assertSame(
            [
                'encrypted_passphrase' =>
                    CompanyBackupSecretStorage::ApplicationEncrypted,
            ],
            $projection->secretStorage,
        );
        self::assertSame(
            'signing/profiles/supplier-37/profile-41/profile.p12',
            $projection->attachmentPath(
                'certificate_path',
                37,
                ['profile_id' => 41],
            ),
        );
        self::assertSame(
            [
                CompanyBackupReferenceMapping::Actor,
                CompanyBackupReferenceMapping::TenantId,
                CompanyBackupReferenceMapping::CredentialDecision,
            ],
            array_map(
                static fn ($reference): CompanyBackupReferenceMapping =>
                    $reference->mapping,
                $projection->references->references,
            ),
        );
        self::assertSame(
            TenantSecretPolicy::OptionalCredential,
            $projection->policyFor(null, 'signing/pdf/synthetic.p12', null),
        );
        self::assertSame(
            TenantSecretPolicy::PersonalWithDualConsent,
            $projection->policyFor(41, 'signing/pdf/personal.p12', null),
        );
        self::assertSame(
            TenantSecretPolicy::PersonalWithDualConsent,
            $projection->policyFor(41, null, 73),
        );
        self::assertSame(
            0,
            $projection->restoreOverrides->apply(['is_active' => 1])['is_active'],
        );

        foreach (
            [
                [null, null, 73, 'credential_variant_unsupported'],
                [41, 'signing/pdf/personal.p12', 73, 'credential_variant_ambiguous'],
                [41, null, null, 'credential_variant_ambiguous'],
            ] as [$owner, $path, $vaultId, $errorCode]
        ) {
            try {
                $projection->policyFor($owner, $path, $vaultId);
                self::fail('Neznámá credential varianta nesmí projít klasifikací.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame($errorCode, $e->errorCode);
            }
        }

        try {
            CompanyBackupTableProjection::fromDefinition($credentials);
            self::fail('Výchozí vynechání nesmí vytvořit běžný JSONL řádek.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_object_kind_unsupported', $e->errorCode);
        }

        $vault = $registry->definition('table:epo_signing_credentials');
        self::assertNotNull($vault);
        self::assertSame(TenantDataPolicy::PersonalSecretAttachment, $vault->policy);
        self::assertFalse($vault->policy->hasMachineDataPayload());

        $supplierLinks = $registry->definition(
            'table:epo_signing_credential_suppliers',
        );
        self::assertNotNull($supplierLinks);
        self::assertSame(TenantDataPolicy::TenantRelation, $supplierLinks->policy);
        self::assertFalse($supplierLinks->policy->hasMachineDataPayload());
        self::assertSame(
            [
                'actor_column' => 'enabled_by',
                'credential_column' => 'credential_id',
                'strategy' => 'credential_consent_relation',
                'supplier_column' => 'supplier_id',
            ],
            $supplierLinks->details['ownership'] ?? null,
        );
    }

    public function testSigningSettingsDisablePersonalAccountantProfilesOnRestore(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:signing_settings');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'supplier_id',
            'accountant_profiles_enabled',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['supplier_id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [],
                [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
            ),
        );

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            ['supplier_id->supplier:id'],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        $restored = $projection->restoreOverrides->apply([
            'accountant_profiles_enabled' => 1,
        ]);
        self::assertSame(0, $restored['accountant_profiles_enabled']);
    }

    public function testSignatureOutputSettingsDisableEveryOutputOnRestore(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:pdf_signature_output_settings');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'supplier_id',
            'usage',
            'output_type',
            'enabled',
            'backend',
            'selection_source',
            'user_profile_fallback',
            'default_profile_id',
            'failure_policy',
            'signature_config_json',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema(
            $columns,
            [],
            ['supplier_id', 'output_type'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['default_profile_id', 'signature_config_json'],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'default_profile_id'],
                        'signing_profiles',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
                ],
            ),
        );

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'supplier_id,default_profile_id->signing_profiles:supplier_id,id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            ['default_profile_id'],
            $projection->references->references[0]->nullableColumns,
        );
        $restored = $projection->restoreOverrides->apply(['enabled' => 1]);
        self::assertSame(0, $restored['enabled']);
    }

    public function testSignatureRoleProfilesKeepTenantProfileMapping(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:signature_role_profiles');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'supplier_id',
            'usage',
            'output_type',
            'role',
            'profile_id',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema(
            $columns,
            [],
            ['supplier_id', 'usage', 'output_type', 'role'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'profile_id'],
                        'signing_profiles',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
                ],
            ),
        );

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'supplier_id,profile_id->signing_profiles:supplier_id,id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
    }

    public function testSignatureDocumentOverridesRemapBothDocumentTypes(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:signature_document_overrides');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'supplier_id',
            'usage',
            'entity_type',
            'entity_id',
            'selection_source',
            'admin_profile_id',
            'created_by',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema(
            $columns,
            [],
            ['supplier_id', 'usage', 'entity_type', 'entity_id'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['admin_profile_id', 'created_by'],
                [
                    new CompanyBackupForeignKey(['created_by'], 'users', ['id']),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'admin_profile_id'],
                        'signing_profiles',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
                ],
            ),
        );
        $projection->polymorphicReferences->assertRegistryTargets($registry);

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'created_by->users:id',
                'supplier_id,admin_profile_id->signing_profiles:supplier_id,id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            ['invoice', 'work_report'],
            array_map(
                static fn ($case): string => $case->equals,
                $projection->polymorphicReferences->references[0]->cases,
            ),
        );
        foreach (['invoice', 'work_report'] as $entityType) {
            $restored = $projection->polymorphicReferences->remap(
                ['entity_type' => $entityType, 'entity_id' => 41],
                static fn ($case, int $value): int => $value + 100,
            );
            self::assertSame(141, $restored['entity_id']);
        }
    }

    public function testSignatureUserProfilesAreRecreatedFromActorDecisions(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition(
            'table:signature_user_profiles',
        );
        self::assertNotNull($definition);

        self::assertSame(TenantDataPolicy::TenantRelation, $definition->policy);
        self::assertFalse($definition->policy->hasMachineDataPayload());
        self::assertSame(
            [
                'actor_column' => 'user_id',
                'strategy' => 'restore_actor_relation',
                'supplier_column' => 'supplier_id',
            ],
            $definition->details['ownership'] ?? null,
        );
        self::assertArrayNotHasKey('company_backup', $definition->details);
    }

    public function testSupplierLogoAreaRegistersEveryCurrentAndHistoricalOwner(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('file-area:supplier-logos');
        self::assertNotNull($definition);

        $area = CompanyBackupFileAreaProjection::fromDefinition(
            $definition,
            $registry,
        );

        self::assertSame('historical_optional', $area->policy->value);
        self::assertSame('supplier_logo', $area->pathPolicy->value);
        self::assertSame('supplier-logos', $area->storageSubdirectory);
        self::assertSame(
            [
                'table:branding_profiles:logo_path:[]',
                'table:invoices:supplier_snapshot:["logo_path"]',
                'table:supplier:logo_path:[]',
            ],
            array_map(
                static fn ($owner): string => $owner->signature(),
                $area->owners->owners,
            ),
        );
    }

    public function testCurrenciesHaveFirstExplicitProductionColumnProjection(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:currencies');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'code',
            'label',
            'symbol',
            'name_cs',
            'name_en',
            'decimals',
            'is_active',
            'is_default',
            'account_number',
            'bank_code',
            'bank_name',
            'iban',
            'bic',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame([], $projection->generatedColumns);
        self::assertSame([], $projection->omitColumns);
        self::assertNull($projection->requiredSecretEnvelopeColumn());
        self::assertCount(1, $projection->references->references);
        self::assertSame(
            CompanyBackupReferenceMapping::TenantId,
            $projection->references->references[0]->mapping,
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Required,
            $projection->references->references[0]->constraint,
        );
        $projection->references->assertRegistryTargets(TenantDataRegistryFactory::draftV1());
    }

    public function testAccountingPeriodsDeclareNullableHistoricalActors(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:accounting_periods');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'fiscal_year',
            'starts_on',
            'ends_on',
            'status',
            'closed_at',
            'row_version',
            'closed_by',
            'approved_at',
            'approved_by',
            'reviewed_at',
            'reviewed_by',
            'approval_body',
            'approval_decision_ref',
            'approval_document_hash',
            'created_at',
            'small_asset_accrual_mode',
            'small_asset_accrual_pct',
            'small_asset_flat_pct_materiality_limit',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['approved_by', 'closed_by', 'reviewed_by'],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            ['approved_by', 'closed_by', 'reviewed_by', 'supplier_id'],
            array_map(
                static fn ($reference): string => $reference->firstColumn(),
                $projection->references->references,
            ),
        );
        foreach (array_slice($projection->references->references, 0, 3) as $actor) {
            self::assertSame(CompanyBackupReferenceMapping::Actor, $actor->mapping);
            self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
        }

        $users = $registry->definition('table:users');
        self::assertNotNull($users);
        self::assertSame(TenantDataPolicy::InstanceOwned, $users->policy);
        self::assertFalse($users->policy->hasMachineDataPayload());
    }

    public function testChartOfAccountsDeclaresNullableSelfReference(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:chart_of_accounts');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'account_code',
            'name',
            'account_type',
            'normal_side',
            'is_synthetic',
            'parent_id',
            'is_active',
            'created_at',
            'tax_deductibility',
            'is_clearing',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['parent_id'],
            [
                new CompanyBackupForeignKey(['parent_id'], 'chart_of_accounts', ['id']),
                new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
            ],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            ['parent_id', 'supplier_id'],
            array_map(
                static fn ($reference): string => $reference->firstColumn(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            ['parent_id'],
            $projection->references->references[0]->nullableColumns,
        );
    }

    public function testPostingRulesDeclareTenantNaturalKeyReferences(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:posting_rules');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'rule_key',
            'description',
            'debit_account_code',
            'credit_account_code',
            'priority',
            'is_active',
            'created_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['supplier_id', 'debit_account_code', 'credit_account_code'],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'supplier_id,credit_account_code->chart_of_accounts:supplier_id,account_code',
                'supplier_id,debit_account_code->chart_of_accounts:supplier_id,account_code',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            CompanyBackupReferenceMapping::TenantNaturalKey,
            $projection->references->references[0]->mapping,
        );
    }

    public function testCostCentersDeclareSupplierReference(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:cost_centers');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'code',
            'name',
            'is_active',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            [],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertCount(1, $projection->references->references);
    }

    public function testAccountingSupplierSettingsDisableAutomationOnRestore(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:accounting_supplier_settings');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'supplier_id',
            'avg_employees',
            'statement_scope_override',
            'updated_at',
            'statutory_audit',
            'manual_doc_series',
            'fx_reversal_at_open',
            'locked_until',
            'fx_rate_mode',
            'automation_level',
            'automation_daily_limit_czk',
            'automation_digest_enabled',
            'automation_digest_hour',
            'small_asset_accrual_mode',
            'small_asset_accrual_pct',
            'fuel_account_code',
            'vehicle_repair_account_code',
            'single_analytic_redirect',
        ];

        $projection->assertRuntimeSchema($columns, [], ['supplier_id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['fuel_account_code', 'vehicle_repair_account_code'],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));
        $restored = $projection->restoreOverrides->apply([
            'supplier_id' => 9,
            'automation_level' => 'full',
            'automation_digest_enabled' => 1,
        ]);

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame('off', $restored['automation_level']);
        self::assertSame(0, $restored['automation_digest_enabled']);
        self::assertSame(
            [
                'supplier_id,fuel_account_code->chart_of_accounts:supplier_id,account_code',
                'supplier_id,vehicle_repair_account_code'
                    . '->chart_of_accounts:supplier_id,account_code',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
    }

    public function testAccountingDocumentSeriesDeclaresZeroSentinelRegister(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:accounting_document_series');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'series_code',
            'register_id',
            'fiscal_year',
            'prefix',
            'number_format',
            'next_number',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['number_format'],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'register_id->cash_registers:id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            CompanyBackupReferenceMapping::TenantIdOrZero,
            $projection->references->references[0]->mapping,
        );
    }

    public function testAccountingClosingStepsDeclareEveryPayloadReference(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:accounting_closing_steps',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'period_id',
            'step_key',
            'status',
            'payload',
            'note',
            'done_at',
            'done_by',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['payload', 'note', 'done_at', 'done_by'],
            [
                new CompanyBackupForeignKey(
                    ['supplier_id', 'period_id'],
                    'accounting_periods',
                    ['supplier_id', 'id'],
                ),
                new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
            ],
        ));
        $projection->embeddedReferences->assertRegistryTargets($registry);

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'done_by->users:id',
                'supplier_id,period_id->accounting_periods:supplier_id,id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            [
                'payload:checks.*.value.documented.*.entry_id->journal_entries:id'
                    . '?checks.*.key=transit_261_open',
                'payload:checks.*.value.documented.*.pair_tx_id->bank_transactions:id'
                    . '?checks.*.key=transit_261_open',
                'payload:checks.*.value.documented.*.tx_id->bank_transactions:id'
                    . '?checks.*.key=transit_261_open',
                'payload:checks.*.value.findings.*.account_id->chart_of_accounts:id',
                'payload:checks.*.value.findings.*.doc_id->assets:id'
                    . '?checks.*.value.findings.*.doc_type=asset',
                'payload:checks.*.value.findings.*.doc_id->cash_documents:id'
                    . '?checks.*.value.findings.*.doc_type=cash',
                'payload:checks.*.value.findings.*.doc_id->invoices:id'
                    . '?checks.*.value.findings.*.doc_type=invoice',
                'payload:checks.*.value.findings.*.doc_id->journal_entries:id'
                    . '?checks.*.value.findings.*.doc_type=journal_entry',
                'payload:checks.*.value.findings.*.doc_id->purchase_invoices:id'
                    . '?checks.*.value.findings.*.doc_type=purchase_invoice',
                'payload:checks.*.value.findings.*.entry_id->journal_entries:id',
                'payload:detail.*.doc_id->invoices:id?detail.*.doc_type=invoice',
                'payload:detail.*.doc_id->purchase_invoices:id'
                    . '?detail.*.doc_type=purchase_invoice',
                'payload:entries.*.entry_id->journal_entries:id',
                'payload:entries.*.invoice_id->invoices:id',
                'payload:entry_id->journal_entries:id',
                'payload:entry_ids.*->journal_entries:id',
                'payload:fx_reversal_entry_id->journal_entries:id',
                'payload:next_period_id->accounting_periods:id',
                'payload:prepaid_expense_accrual.entry_id->journal_entries:id',
                'payload:prepaid_expense_release_entry_id->journal_entries:id',
                'payload:reversed.*.entry_id->journal_entries:id',
                'payload:reversed.*.reversal_entry_id->journal_entries:id',
                'payload:saldo_lines.*.account_id->chart_of_accounts:id',
                'payload:small_asset_accrual.entry_id->journal_entries:id',
                'payload:small_asset_release_entry_id->journal_entries:id',
                'payload:stock_release_entry_id->journal_entries:id',
                'payload:unposted_override.invoice_ids.*->invoices:id',
                'payload:unposted_override.overridden_by->users:id',
                'payload:unposted_override.purchase_ids.*->purchase_invoices:id',
                'payload:warnings.*.items.*.id->purchase_invoices:id'
                    . '?warnings.*.key=stock_in_transit',
                'payload:warnings.*.items.*.id->stock_documents:id'
                    . '?warnings.*.key=stock_unbilled_receipts',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->embeddedReferences->references,
            ),
        );
    }

    public function testJournalEntriesDeclareEverySourceIdVariant(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:journal_entries');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'period_id',
            'entry_date',
            'document_date',
            'document_no',
            'description',
            'source_type',
            'source_id',
            'posted_at',
            'posted_by',
            'reversed_by',
            'row_version',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            [
                'document_date',
                'document_no',
                'description',
                'source_id',
                'posted_at',
                'posted_by',
                'reversed_by',
            ],
            [
                new CompanyBackupForeignKey(
                    ['supplier_id', 'period_id'],
                    'accounting_periods',
                    ['supplier_id', 'id'],
                ),
                new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
                new CompanyBackupForeignKey(['posted_by'], 'users', ['id']),
                new CompanyBackupForeignKey(
                    ['reversed_by'],
                    'journal_entries',
                    ['id'],
                ),
            ],
        ));
        $projection->polymorphicReferences->assertRegistryTargets($registry);

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'posted_by->users:id',
                'reversed_by->journal_entries:id',
                'supplier_id,period_id->accounting_periods:supplier_id,id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );

        $polymorphic = $projection->polymorphicReferences->references;
        self::assertCount(1, $polymorphic);
        $cases = $polymorphic[0]->cases;
        self::assertSame(
            [
                'asset',
                'asset_disposal',
                'bank',
                'cash',
                'closing',
                'deferred_tax',
                'depreciation',
                'fx_revaluation',
                'income_tax',
                'invoice',
                'manual',
                'offset',
                'opening',
                'payroll',
                'prepaid_expense_accrual',
                'profit_distribution',
                'provision',
                'purchase_invoice',
                'settlement',
                'small_asset_accrual',
                'stock',
                'vat_clearing',
            ],
            array_map(static fn ($case): string => $case->equals, $cases),
        );

        $byType = [];
        foreach ($cases as $case) {
            $byType[$case->equals] = $case;
        }
        self::assertSame('table:invoices', $byType['invoice']->target);
        self::assertSame('table:invoices', $byType['provision']->target);
        self::assertSame('table:payroll_run_revisions', $byType['payroll']->target);
        self::assertSame(
            CompanyBackupPolymorphicReferenceMapping::Preserve,
            $byType['manual']->mapping,
        );
        self::assertSame(
            CompanyBackupPolymorphicReferenceMapping::Preserve,
            $byType['vat_clearing']->mapping,
        );
        self::assertSame(
            CompanyBackupPolymorphicReferenceTransform::IdentityOrDecimalSlot,
            $byType['closing']->transform,
        );
        self::assertSame(
            CompanyBackupPolymorphicReferenceTransform::DecimalSlot,
            $byType['fx_revaluation']->transform,
        );
        self::assertSame(
            CompanyBackupPolymorphicReferenceTransform::IdentityOrOffset,
            $byType['small_asset_accrual']->transform,
        );
    }

    public function testJournalEntryLinesDeclareDimensionsAndTenantReferences(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:journal_entry_lines');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'entry_id',
            'supplier_id',
            'account_id',
            'side',
            'amount',
            'currency_code',
            'fx_rate',
            'amount_foreign',
            'cost_center',
            'project_id',
            'line_no',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['currency_code', 'fx_rate', 'amount_foreign', 'cost_center', 'project_id'],
            [
                new CompanyBackupForeignKey(
                    ['supplier_id', 'account_id'],
                    'chart_of_accounts',
                    ['supplier_id', 'id'],
                ),
                new CompanyBackupForeignKey(
                    ['supplier_id', 'entry_id'],
                    'journal_entries',
                    ['supplier_id', 'id'],
                ),
                new CompanyBackupForeignKey(['project_id'], 'projects', ['id']),
                new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
            ],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'project_id->projects:id',
                'supplier_id,account_id->chart_of_accounts:supplier_id,id',
                'supplier_id,cost_center->cost_centers:supplier_id,code',
                'supplier_id,entry_id->journal_entries:supplier_id,id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        $costCenterReference = array_values(array_filter(
            $projection->references->references,
            static fn ($reference): bool =>
                $reference->columns === ['supplier_id', 'cost_center'],
        ))[0] ?? null;
        self::assertNotNull($costCenterReference);
        self::assertSame(
            CompanyBackupReferenceMapping::TenantNaturalKey,
            $costCenterReference->mapping,
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional,
            $costCenterReference->constraint,
        );
        foreach ($projection->references->references as $reference) {
            self::assertNotContains('currency_code', $reference->columns);
        }

        $projects = $registry->definition('table:projects');
        self::assertNotNull($projects);
        self::assertSame(TenantDataPolicy::TenantOwnedIndirect, $projects->policy);
        self::assertSame('foreign_key_path', $projects->details['ownership']['strategy'] ?? null);
    }

    public function testProjectsDeclareIndirectOwnershipAndBusinessReferences(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:projects');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'client_id',
            'name',
            'payment_due_days',
            'billing_emails_mode',
            'payment_due_unit',
            'project_number',
            'contract_number',
            'budget_total',
            'budget_yearly',
            'budget_monthly',
            'hourly_rate',
            'currency_id',
            'status',
            'requires_work_report_approval',
            'note',
            'default_revenue_category_id',
            'archived_at',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            [
                'payment_due_unit',
                'project_number',
                'contract_number',
                'budget_total',
                'budget_yearly',
                'budget_monthly',
                'note',
                'default_revenue_category_id',
                'archived_at',
            ],
            [
                new CompanyBackupForeignKey(['client_id'], 'clients', ['id']),
                new CompanyBackupForeignKey(['currency_id'], 'currencies', ['id']),
            ],
        ));

        self::assertSame(TenantDataPolicy::TenantOwnedIndirect, $projection->policy);
        self::assertSame('foreign_key_path', $projection->ownership['strategy'] ?? null);
        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'client_id->clients:id',
                'currency_id->currencies:id',
                'default_revenue_category_id->revenue_categories:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional,
            $projection->references->references[2]->constraint,
        );
    }

    public function testRevenueCategoriesDeclareNaturalKeyAndSupplierReference(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:revenue_categories');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'code',
            'label',
            'display_order',
            'invoice_number_format',
            'proforma_number_format',
            'credit_note_number_format',
            'invoice_number_period',
            'archived',
            'created_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            [
                'invoice_number_format',
                'proforma_number_format',
                'credit_note_number_format',
                'invoice_number_period',
            ],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'code'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            ['supplier_id->supplier:id'],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
    }

    public function testExpenseCategoriesDeclareNaturalKeyAndSupplierReference(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:expense_categories');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'code',
            'label',
            'fixed_or_var',
            'display_order',
            'archived',
            'created_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            [],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'code'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            ['supplier_id->supplier:id'],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
    }

    public function testCountriesAreGlobalReferencesSelectedFromCompanyRows(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:countries');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = ['id', 'iso2', 'iso3', 'name_cs', 'name_en', 'is_eu'];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema([], []),
        );
        $selection = (new CompanyBackupTenantSqlSelector())->select($projection, 31);

        self::assertSame(TenantDataPolicy::GlobalReference, $projection->policy);
        self::assertSame(['iso2'], $definition->details['natural_key'] ?? null);
        self::assertSame([31, 31], $selection->params);
        self::assertStringContainsString('`clients`', $selection->where);
        self::assertStringContainsString('`supplier`', $selection->where);
    }

    public function testVatRatesAreGlobalReferencesSelectedFromCompanyRows(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:vat_rates');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'code',
            'rate_percent',
            'country',
            'label_cs',
            'label_en',
            'is_default',
            'is_reverse_charge',
            'valid_from',
            'valid_to',
            'display_order',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(['valid_to'], []),
        );
        $selection = (new CompanyBackupTenantSqlSelector())->select($projection, 37);

        self::assertSame(TenantDataPolicy::GlobalReference, $projection->policy);
        self::assertSame(['code'], $definition->details['natural_key'] ?? null);
        self::assertSame([37, 37], $selection->params);
        self::assertStringContainsString('`clients`', $selection->where);
        self::assertStringContainsString('`supplier`', $selection->where);
    }

    public function testClientsDeclareGlobalTenantAndExternalIdentifiers(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:clients');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'company_name',
            'first_name',
            'last_name',
            'ic',
            'dic',
            'tax_number',
            'street',
            'city',
            'zip',
            'country_id',
            'main_email',
            'phone',
            'language',
            'currency_default_id',
            'vat_rate_default_id',
            'reverse_charge',
            'oss_mode',
            'oss_default_supply_type',
            'is_customer',
            'is_vendor',
            'is_fuel_station',
            'idoklad_id',
            'auto_send_reminders',
            'payment_due_default',
            'payment_due_unit',
            'hourly_rate',
            'note',
            'default_expense_category_id',
            'default_revenue_category_id',
            'invoice_number_format',
            'proforma_number_format',
            'credit_note_number_format',
            'invoice_number_period',
            'default_branding_profile_id',
            'archived_at',
            'created_at',
            'updated_at',
            'fakturoid_id',
            'is_vat_payer',
            'default_payment_method',
            'related_party',
            'related_party_type',
            'related_party_note',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            [
                'first_name',
                'last_name',
                'ic',
                'dic',
                'tax_number',
                'main_email',
                'phone',
                'vat_rate_default_id',
                'oss_default_supply_type',
                'idoklad_id',
                'payment_due_default',
                'payment_due_unit',
                'note',
                'default_expense_category_id',
                'default_revenue_category_id',
                'invoice_number_format',
                'proforma_number_format',
                'credit_note_number_format',
                'invoice_number_period',
                'default_branding_profile_id',
                'archived_at',
                'fakturoid_id',
                'default_payment_method',
                'related_party_type',
                'related_party_note',
            ],
            [
                new CompanyBackupForeignKey(
                    ['default_branding_profile_id'],
                    'branding_profiles',
                    ['id'],
                ),
                new CompanyBackupForeignKey(['country_id'], 'countries', ['id']),
                new CompanyBackupForeignKey(
                    ['currency_default_id'],
                    'currencies',
                    ['id'],
                ),
                new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
                new CompanyBackupForeignKey(
                    ['vat_rate_default_id'],
                    'vat_rates',
                    ['id'],
                ),
            ],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            ['fakturoid_id', 'idoklad_id'],
            $projection->preservedIdentifiers->columns,
        );
        self::assertSame(
            [
                'country_id->countries:id',
                'currency_default_id->currencies:id',
                'default_branding_profile_id->branding_profiles:id',
                'default_expense_category_id->expense_categories:id',
                'default_revenue_category_id->revenue_categories:id',
                'supplier_id->supplier:id',
                'vat_rate_default_id->vat_rates:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            CompanyBackupReferenceMapping::GlobalNaturalKey,
            $projection->references->references[0]->mapping,
        );
        self::assertSame(
            CompanyBackupReferenceMapping::GlobalNaturalKey,
            $projection->references->references[6]->mapping,
        );
    }

    public function testInvoiceSettlementsDeclareDocumentsPostingAndActor(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:invoice_settlements');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'doc_type',
            'doc_id',
            'settled_on',
            'amount',
            'account_id',
            'note',
            'status',
            'journal_entry_id',
            'reversal_entry_id',
            'invoice_payment_id',
            'created_by',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            [
                'note',
                'journal_entry_id',
                'reversal_entry_id',
                'invoice_payment_id',
                'created_by',
            ],
            [
                new CompanyBackupForeignKey(
                    ['account_id'],
                    'chart_of_accounts',
                    ['id'],
                ),
                new CompanyBackupForeignKey(
                    ['journal_entry_id'],
                    'journal_entries',
                    ['id'],
                ),
                new CompanyBackupForeignKey(
                    ['reversal_entry_id'],
                    'journal_entries',
                    ['id'],
                ),
                new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
            ],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'account_id->chart_of_accounts:id',
                'created_by->users:id',
                'doc_id->invoices:id?doc_type=invoice',
                'doc_id->purchase_invoices:id?doc_type=purchase_invoice',
                'invoice_payment_id->invoice_payments:id',
                'journal_entry_id->journal_entries:id',
                'reversal_entry_id->journal_entries:id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            CompanyBackupReferenceMapping::Actor,
            $projection->references->references[1]->mapping,
        );
        self::assertSame(
            ['null', 'restore_actor'],
            $projection->references->references[1]->fallbacks,
        );
    }

    public function testOffsetAgreementsDeclarePartnerPostingAndActor(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:offset_agreements');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'partner_id',
            'agreement_date',
            'document_no',
            'total_amount',
            'status',
            'journal_entry_id',
            'note',
            'created_by',
            'created_at',
            'updated_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['journal_entry_id', 'note', 'created_by'],
            [
                new CompanyBackupForeignKey(
                    ['journal_entry_id'],
                    'journal_entries',
                    ['id'],
                ),
                new CompanyBackupForeignKey(['partner_id'], 'clients', ['id']),
                new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
            ],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'document_no'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'created_by->users:id',
                'journal_entry_id->journal_entries:id',
                'partner_id->clients:id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            CompanyBackupReferenceMapping::Actor,
            $projection->references->references[0]->mapping,
        );
        self::assertSame(
            ['null', 'restore_actor'],
            $projection->references->references[0]->fallbacks,
        );
    }

    public function testOffsetAgreementItemsDeclareAgreementDocumentsAndPayment(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:offset_agreement_items');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'agreement_id',
            'supplier_id',
            'doc_type',
            'doc_id',
            'amount',
            'invoice_payment_id',
            'created_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['invoice_payment_id'],
            [
                new CompanyBackupForeignKey(
                    ['agreement_id'],
                    'offset_agreements',
                    ['id'],
                ),
                new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
            ],
        ));

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [
                'agreement_id->offset_agreements:id',
                'doc_id->invoices:id?doc_type=invoice',
                'doc_id->purchase_invoices:id?doc_type=purchase_invoice',
                'invoice_payment_id->invoice_payments:id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
    }

    public function testAccountingClosingPayloadReferencesUseTheirDeclaredTargets(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition(
            'table:accounting_closing_steps',
        );
        self::assertNotNull($definition);
        $references = CompanyBackupTableProjection::fromDefinition($definition)
            ->embeddedReferences;
        $offsets = [
            'table:assets' => 1000,
            'table:bank_transactions' => 2000,
            'table:chart_of_accounts' => 3000,
            'table:cash_documents' => 4000,
            'table:invoices' => 5000,
            'table:journal_entries' => 6000,
            'table:purchase_invoices' => 7000,
            'table:accounting_periods' => 8000,
            'table:users' => 9000,
            'table:stock_documents' => 10000,
        ];
        $payload = [
            'checks' => [[
                'key' => 'transit_261_open',
                'value' => [
                    'documented' => [
                        ['entry_id' => 1, 'tx_id' => 2, 'pair_tx_id' => null],
                        ['entry_id' => 3, 'tx_id' => 4, 'pair_tx_id' => 5],
                    ],
                    'findings' => [
                        ['account_id' => 6],
                        ['doc_type' => 'asset', 'doc_id' => 7, 'entry_id' => 8],
                        ['doc_type' => 'cash', 'doc_id' => 9],
                        ['doc_type' => 'invoice', 'doc_id' => 10],
                        ['doc_type' => 'journal_entry', 'doc_id' => 11],
                        ['doc_type' => 'purchase_invoice', 'doc_id' => 12],
                    ],
                ],
            ]],
            'detail' => [
                ['doc_type' => 'invoice', 'doc_id' => 13],
                ['doc_type' => 'purchase_invoice', 'doc_id' => 14],
            ],
            'entries' => [['entry_id' => 15, 'invoice_id' => 16]],
            'entry_id' => 17,
            'entry_ids' => ['saldo' => 18, 'bank' => 19],
            'fx_reversal_entry_id' => null,
            'next_period_id' => 20,
            'prepaid_expense_accrual' => ['entry_id' => 21],
            'prepaid_expense_release_entry_id' => 22,
            'reversed' => [['entry_id' => 23, 'reversal_entry_id' => 24]],
            'saldo_lines' => [['account_id' => 25]],
            'small_asset_accrual' => ['entry_id' => 26],
            'small_asset_release_entry_id' => 27,
            'stock_release_entry_id' => 28,
            'unposted_override' => [
                'invoice_ids' => [29, 30],
                'overridden_by' => 31,
                'purchase_ids' => [32],
            ],
            'warnings' => [
                ['key' => 'stock_unbilled_receipts', 'items' => [['id' => 33]]],
                ['key' => 'stock_in_transit', 'items' => [['id' => 34]]],
            ],
        ];

        $restored = $references->remap(
            ['payload' => $payload],
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ): int => (int) $value + $offsets[$reference->target],
        )['payload'];

        self::assertSame(6001, $restored['checks'][0]['value']['documented'][0]['entry_id']);
        self::assertSame(2002, $restored['checks'][0]['value']['documented'][0]['tx_id']);
        self::assertNull($restored['checks'][0]['value']['documented'][0]['pair_tx_id']);
        self::assertSame(2005, $restored['checks'][0]['value']['documented'][1]['pair_tx_id']);
        self::assertSame(3006, $restored['checks'][0]['value']['findings'][0]['account_id']);
        self::assertSame(1007, $restored['checks'][0]['value']['findings'][1]['doc_id']);
        self::assertSame(6008, $restored['checks'][0]['value']['findings'][1]['entry_id']);
        self::assertSame(4009, $restored['checks'][0]['value']['findings'][2]['doc_id']);
        self::assertSame(5010, $restored['checks'][0]['value']['findings'][3]['doc_id']);
        self::assertSame(6011, $restored['checks'][0]['value']['findings'][4]['doc_id']);
        self::assertSame(7012, $restored['checks'][0]['value']['findings'][5]['doc_id']);
        self::assertSame(5013, $restored['detail'][0]['doc_id']);
        self::assertSame(7014, $restored['detail'][1]['doc_id']);
        self::assertSame(6015, $restored['entries'][0]['entry_id']);
        self::assertSame(5016, $restored['entries'][0]['invoice_id']);
        self::assertSame(6017, $restored['entry_id']);
        self::assertSame(['saldo' => 6018, 'bank' => 6019], $restored['entry_ids']);
        self::assertNull($restored['fx_reversal_entry_id']);
        self::assertSame(8020, $restored['next_period_id']);
        self::assertSame(6021, $restored['prepaid_expense_accrual']['entry_id']);
        self::assertSame(6022, $restored['prepaid_expense_release_entry_id']);
        self::assertSame(6023, $restored['reversed'][0]['entry_id']);
        self::assertSame(6024, $restored['reversed'][0]['reversal_entry_id']);
        self::assertSame(3025, $restored['saldo_lines'][0]['account_id']);
        self::assertSame(6026, $restored['small_asset_accrual']['entry_id']);
        self::assertSame(6027, $restored['small_asset_release_entry_id']);
        self::assertSame(6028, $restored['stock_release_entry_id']);
        self::assertSame([5029, 5030], $restored['unposted_override']['invoice_ids']);
        self::assertSame(9031, $restored['unposted_override']['overridden_by']);
        self::assertSame([7032], $restored['unposted_override']['purchase_ids']);
        self::assertSame(10033, $restored['warnings'][0]['items'][0]['id']);
        self::assertSame(7034, $restored['warnings'][1]['items'][0]['id']);
    }
}
