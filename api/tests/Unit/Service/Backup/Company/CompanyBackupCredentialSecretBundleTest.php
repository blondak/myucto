<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupCredentialSecretBundle;
use MyInvoice\Service\Backup\Company\CompanyBackupCredentialTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayload;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayloadException;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretScope;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretValue;
use MyInvoice\Service\Backup\Company\CompanyBackupSigningCredentialsProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\TestCase;

final class CompanyBackupCredentialSecretBundleTest extends TestCase
{
    public function testRoundTripsCompanyPfxWithoutSourcePathOrCiphertext(): void
    {
        $projection = $this->projection();
        $pfx = "synthetic-pfx\0bytes";
        $passphrase = 'synthetic-pfx-passphrase';
        $row = $this->row();
        $bundle = CompanyBackupCredentialSecretBundle::fromExportRow(
            $projection,
            'company_file',
            null,
            $row,
            ['certificate_path' => $pfx],
            ['encrypted_passphrase' => $passphrase],
        );

        $json = $bundle->toJson();
        self::assertIsString($row['certificate_path']);
        self::assertIsString($row['encrypted_passphrase']);
        self::assertStringNotContainsString($row['certificate_path'], $json);
        self::assertStringNotContainsString($row['encrypted_passphrase'], $json);

        $parsed = CompanyBackupCredentialSecretBundle::fromJson(
            $json,
            $projection,
            'company_file',
            ['id' => 11],
        );
        self::assertSame(['id' => 11], $parsed->primaryKey);
        self::assertSame($pfx, $parsed->attachment('certificate_path'));
        self::assertSame(
            $passphrase,
            $parsed->secret('encrypted_passphrase'),
        );
        self::assertSame(0, $parsed->restorableRow()['is_active']);
        self::assertNull($parsed->restorableRow()['certificate_path']);
        self::assertNull($parsed->restorableRow()['encrypted_passphrase']);
    }

    public function testSecretPayloadRejectsUntypedCredentialBytes(): void
    {
        $registry = $this->registry();
        $bundle = CompanyBackupCredentialSecretBundle::fromExportRow(
            $this->projection(),
            'company_file',
            null,
            $this->row(),
            ['certificate_path' => 'synthetic-pfx'],
            ['encrypted_passphrase' => 'synthetic-passphrase'],
        );
        $payload = CompanyBackupSecretPayload::fromValues([
            CompanyBackupSecretValue::fromPlaintext(
                'table:signing_credentials',
                CompanyBackupSecretScope::CredentialVariant,
                'company_file',
                ['id' => 11],
                $bundle->toJson(),
            ),
        ], $registry);
        self::assertSame(
            'company_file',
            CompanyBackupCredentialSecretBundle::fromJson(
                $payload->values()[0]->plaintext(),
                $this->projection(),
                'company_file',
                ['id' => 11],
            )->variant,
        );

        $untyped = $payload->toArray();
        $untyped['declarations'][0]['values'][0]['value_base64'] = base64_encode(
            CanonicalJson::encode(['arbitrary' => 'authenticated']),
        );
        $this->assertPayloadError(
            static fn () => CompanyBackupSecretPayload::fromArray(
                $untyped,
                $registry,
            ),
        );
        $this->assertPayloadError(
            static fn () => CompanyBackupSecretPayload::fromValues([
                CompanyBackupSecretValue::fromPlaintext(
                    'table:signing_credentials',
                    CompanyBackupSecretScope::CredentialVariant,
                    'company_file',
                    ['id' => 11],
                    CanonicalJson::encode(['arbitrary' => 'authenticated']),
                ),
            ], $registry),
        );
    }

    public function testKeepsExternalPassphraseReferenceWithoutInventingSecret(): void
    {
        $row = $this->row();
        $row['passphrase_policy'] = 'passphrase_file';
        $row['passphrase_profile_id'] = 'synthetic-instance-profile';
        $row['encrypted_passphrase'] = null;
        $bundle = CompanyBackupCredentialSecretBundle::fromExportRow(
            $this->projection(),
            'company_file',
            null,
            $row,
            ['certificate_path' => 'synthetic-pfx'],
            ['encrypted_passphrase' => null],
        );

        self::assertNull($bundle->secret('encrypted_passphrase'));
        self::assertSame(
            'synthetic-instance-profile',
            $bundle->restorableRow()['passphrase_profile_id'],
        );
        self::assertSame(0, $bundle->restorableRow()['is_active']);
    }

    public function testRejectsAttachmentTamperAndPassphrasePolicyMismatch(): void
    {
        $projection = $this->projection();
        $bundle = CompanyBackupCredentialSecretBundle::fromExportRow(
            $projection,
            'company_file',
            null,
            $this->row(),
            ['certificate_path' => 'synthetic-pfx'],
            ['encrypted_passphrase' => 'synthetic-passphrase'],
        )->toArray();
        $bundle['attachments']['certificate_path']['sha256'] = str_repeat('0', 64);
        $this->assertPayloadError(
            static fn () => CompanyBackupCredentialSecretBundle::fromJson(
                CanonicalJson::encode($bundle),
                $projection,
                'company_file',
                ['id' => 11],
            ),
        );

        $crossedVariant = CompanyBackupCredentialSecretBundle::fromExportRow(
            $projection,
            'company_file',
            null,
            $this->row(),
            ['certificate_path' => 'synthetic-pfx'],
            ['encrypted_passphrase' => 'synthetic-passphrase'],
        )->toArray();
        $crossedVariant['record']['vault_credential_id'] = 73;
        $this->assertPayloadError(
            static fn () => CompanyBackupCredentialSecretBundle::fromJson(
                CanonicalJson::encode($crossedVariant),
                $projection,
                'company_file',
                ['id' => 11],
            ),
        );

        $row = $this->row();
        $row['passphrase_policy'] = 'passphrase_file';
        $row['passphrase_profile_id'] = 'instance-passphrase-profile';
        $row['encrypted_passphrase'] = null;
        $this->assertPayloadError(
            static fn () => CompanyBackupCredentialSecretBundle::fromExportRow(
                $projection,
                'company_file',
                null,
                $row,
                ['certificate_path' => 'synthetic-pfx'],
                ['encrypted_passphrase' => 'must-not-be-present'],
            ),
        );
    }

    public function testProjectionRejectsUnboundAttachmentAndSecretStorage(): void
    {
        $definition = $this->definition();
        $details = $definition->details;
        $details['company_backup_credential']['attachment_sources']
            ['certificate_path']['path_template'] =
                'signing/profiles/profile-{profile_id}/profile.p12';
        $this->assertProjectionError(
            'credential_attachment_contract_invalid',
            new TenantDataDefinition(
                $definition->key,
                $definition->kind,
                $definition->policy,
                $definition->profiles,
                $details,
            ),
        );

        $details = $definition->details;
        unset(
            $details['company_backup_credential']['secret_storage']
                ['encrypted_passphrase'],
        );
        $this->assertProjectionError(
            'credential_secret_storage_invalid',
            new TenantDataDefinition(
                $definition->key,
                $definition->kind,
                $definition->policy,
                $definition->profiles,
                $details,
            ),
        );
    }

    /** @param callable():mixed $operation */
    private function assertPayloadError(callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatný credential bundle musí být odmítnut.');
        } catch (CompanyBackupSecretPayloadException $e) {
            self::assertSame('secret_payload_invalid', $e->errorCode);
        }
    }

    private function assertProjectionError(
        string $code,
        TenantDataDefinition $definition,
    ): void {
        try {
            CompanyBackupCredentialTableProjection::fromDefinition($definition);
            self::fail('Neúplný transportní kontrakt musí být odmítnut.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    private function projection(): CompanyBackupCredentialTableProjection
    {
        return CompanyBackupCredentialTableProjection::fromDefinition(
            $this->definition(),
        );
    }

    private function registry(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [$this->definition()],
            [$profile],
        ), $profile);
    }

    private function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:signing_credentials',
            TenantDataObjectKind::Table,
            TenantDataPolicy::OptionalCredential,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' =>
                    CompanyBackupSigningCredentialsProjection::ownership(),
                'company_backup_credential' =>
                    CompanyBackupSigningCredentialsProjection::metadata(),
            ],
        );
    }

    /** @return array<string,int|string|null> */
    private function row(): array
    {
        return [
            'id' => 11,
            'profile_id' => 41,
            'vault_credential_id' => null,
            'certificate_path' =>
                'signing/profiles/supplier-7/profile-41/profile.p12',
            'certificate_fingerprint' => str_repeat('a', 64),
            'certificate_subject' => 'CN=Synthetic company',
            'certificate_email' => 'synthetic@example.test',
            'certificate_valid_from' => '2026-01-01 00:00:00',
            'certificate_valid_to' => '2028-01-01 00:00:00',
            'certificate_usage_json' => '{"pdf":true}',
            'passphrase_policy' => 'encrypted_store',
            'passphrase_profile_id' => null,
            'encrypted_passphrase' => 'enc:v1:source-instance-ciphertext',
            'is_active' => 1,
            'created_by' => 3,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
            'deleted_at' => null,
        ];
    }
}
