<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupSecretSelection;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretSelectionException;
use MyInvoice\Service\Backup\Company\CompanyBackupSigningCredentialsProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSecretSelectionTest extends TestCase
{
    public function testCanonicalizesExplicitColumnAndRowSelections(): void
    {
        $registry = $this->registry();
        $selection = CompanyBackupSecretSelection::fromArray([
            'registry_fingerprint' => $registry->fingerprint,
            'entries' => [
                [
                    'registry_key' => 'table:supplier',
                    'scope' => 'column',
                    'name' => 'api_key_enc',
                    'primary_key' => ['id' => 7],
                ],
                [
                    'registry_key' => 'table:email_profiles',
                    'scope' => 'column',
                    'name' => 'smtp_password_enc',
                    'primary_key' => ['id' => 31],
                ],
            ],
        ], $registry);

        self::assertFalse($selection->isEmpty());
        self::assertSame([
            'registry_fingerprint' => $registry->fingerprint,
            'entries' => [
                [
                    'registry_key' => 'table:email_profiles',
                    'scope' => 'column',
                    'name' => 'smtp_password_enc',
                    'primary_key' => ['id' => 31],
                ],
                [
                    'registry_key' => 'table:supplier',
                    'scope' => 'column',
                    'name' => 'api_key_enc',
                    'primary_key' => ['id' => 7],
                ],
            ],
        ], $selection->toArray());
        self::assertSame([
            'table:email_profiles:column:smtp_password_enc' => 1,
            'table:supplier:column:api_key_enc' => 1,
        ], $selection->countsByDeclaration());
    }

    public function testEmptySelectionRemainsExplicitlyBoundToRegistry(): void
    {
        $registry = $this->registry();
        $selection = CompanyBackupSecretSelection::none($registry);

        self::assertTrue($selection->isEmpty());
        self::assertSame([
            'registry_fingerprint' => $registry->fingerprint,
            'entries' => [],
        ], $selection->toArray());

        $foreign = $selection->toArray();
        $foreign['registry_fingerprint'] = 'sha256:' . str_repeat('f', 64);
        $this->assertSelectionError(
            'secret_selection_registry_mismatch',
            static fn () => CompanyBackupSecretSelection::fromArray(
                $foreign,
                $registry,
            ),
        );
    }

    public function testRejectsDuplicateUnknownAndWrongPrimaryKey(): void
    {
        $registry = $this->registry();
        $entry = [
            'registry_key' => 'table:supplier',
            'scope' => 'column',
            'name' => 'api_key_enc',
            'primary_key' => ['id' => 7],
        ];
        $this->assertSelectionError(
            'secret_selection_duplicate',
            static fn () => CompanyBackupSecretSelection::fromArray([
                'registry_fingerprint' => $registry->fingerprint,
                'entries' => [$entry, $entry],
            ], $registry),
        );

        $unknown = $entry;
        $unknown['name'] = 'unknown_secret';
        $this->assertSelectionError(
            'secret_selection_scope_mismatch',
            static fn () => CompanyBackupSecretSelection::fromArray([
                'registry_fingerprint' => $registry->fingerprint,
                'entries' => [$unknown],
            ], $registry),
        );

        $wrongKey = $entry;
        $wrongKey['primary_key'] = ['supplier_id' => 7];
        $this->assertSelectionError(
            'secret_selection_primary_key_invalid',
            static fn () => CompanyBackupSecretSelection::fromArray([
                'registry_fingerprint' => $registry->fingerprint,
                'entries' => [$wrongKey],
            ], $registry),
        );
    }

    public function testAcceptsCompanyCredentialButRejectsProtectedAndPersonalSelections(): void
    {
        $registry = $this->registry();
        $this->assertSelectionError(
            'secret_selection_policy_forbidden',
            fn () => $this->selection($registry, [
                'registry_key' => 'table:supplier',
                'scope' => 'column',
                'name' => 'domain_salt',
                'primary_key' => ['id' => 7],
            ]),
        );
        $this->assertSelectionError(
            'secret_selection_consent_required',
            fn () => $this->selection($registry, [
                'registry_key' => 'table:personal_credentials',
                'scope' => 'column',
                'name' => 'pfx_ciphertext',
                'primary_key' => ['id' => 9],
            ]),
        );
        $selection = $this->selection($registry, [
            'registry_key' => 'table:signing_credentials',
            'scope' => 'credential_variant',
            'name' => 'company_file',
            'primary_key' => ['id' => 11],
        ]);
        self::assertSame([
            'table:signing_credentials:credential_variant:company_file' => 1,
        ], $selection->countsByDeclaration());

        foreach (['personal_file', 'personal_vault'] as $variant) {
            $this->assertSelectionError(
                'secret_selection_consent_required',
                fn () => $this->selection($registry, [
                'registry_key' => 'table:signing_credentials',
                'scope' => 'credential_variant',
                'name' => $variant,
                'primary_key' => ['id' => 11],
                ]),
            );
        }
    }

    /** @param array<string,mixed> $entry */
    private function selection(
        TenantDataRegistrySnapshot $registry,
        array $entry,
    ): CompanyBackupSecretSelection {
        return CompanyBackupSecretSelection::fromArray([
            'registry_fingerprint' => $registry->fingerprint,
            'entries' => [$entry],
        ], $registry);
    }

    /** @param callable():mixed $operation */
    private function assertSelectionError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatný výběr credentialu musí být odmítnut.');
        } catch (CompanyBackupSecretSelectionException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    private function registry(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                new TenantDataDefinition(
                    'table:email_profiles',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    [
                        'primary_key' => ['id'],
                        'ownership' => [
                            'strategy' => 'supplier_id',
                            'column' => 'supplier_id',
                        ],
                        'secrets' => [
                            'smtp_password_enc' => [
                                'policy' =>
                                    TenantSecretPolicy::OptionalCredential->value,
                                'storage' => 'application_encrypted',
                            ],
                        ],
                    ],
                ),
                new TenantDataDefinition(
                    'table:personal_credentials',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::PersonalSecretAttachment,
                    [$profile],
                    [
                        'primary_key' => ['id'],
                        'secrets' => [
                            'pfx_ciphertext' => [
                                'policy' =>
                                    TenantSecretPolicy::PersonalWithDualConsent->value,
                            ],
                        ],
                    ],
                ),
                new TenantDataDefinition(
                    'table:signing_credentials',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::OptionalCredential,
                    [$profile],
                    [
                        'primary_key' => ['id'],
                        'ownership' =>
                            CompanyBackupSigningCredentialsProjection::ownership(),
                        'company_backup_credential' =>
                            CompanyBackupSigningCredentialsProjection::metadata(),
                    ],
                ),
                new TenantDataDefinition(
                    'table:supplier',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::TenantRoot,
                    [$profile],
                    [
                        'primary_key' => ['id'],
                        'ownership' => [
                            'strategy' => 'selected_supplier',
                            'column' => 'id',
                        ],
                        'secrets' => [
                            'api_key_enc' => [
                                'policy' =>
                                    TenantSecretPolicy::OptionalCredential->value,
                                'storage' => 'application_encrypted',
                            ],
                            'domain_salt' => [
                                'policy' =>
                                    TenantSecretPolicy::ProtectedDomainSecret->value,
                                'storage' => 'raw',
                            ],
                        ],
                    ],
                ),
            ],
            [$profile],
        ), $profile);
    }
}
