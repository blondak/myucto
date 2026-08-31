<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayload;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayloadException;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretScope;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretValue;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSecretPayloadTest extends TestCase
{
    public function testRoundTripsCanonicalBinaryValuesAgainstRegistry(): void
    {
        $registry = $this->registry();
        $domainSalt = "\x00synthetic-domain-salt\xff";
        $apiKey = "synthetic-api-key\0bytes";
        $payload = CompanyBackupSecretPayload::fromValues([
            CompanyBackupSecretValue::fromPlaintext(
                'table:supplier',
                CompanyBackupSecretScope::Column,
                'domain_salt',
                ['id' => 7],
                $domainSalt,
            ),
            CompanyBackupSecretValue::fromPlaintext(
                'table:supplier',
                CompanyBackupSecretScope::Column,
                'api_key_enc',
                ['id' => 7],
                $apiKey,
            ),
        ], $registry);

        $expected = [
            'format' => 'myucto-company-secret-payload',
            'version' => 1,
            'registry_fingerprint' => $registry->fingerprint,
            'declarations' => [
                [
                    'registry_key' => 'table:supplier',
                    'scope' => 'column',
                    'name' => 'api_key_enc',
                    'policy' => 'optional_credential',
                    'values' => [[
                        'primary_key' => ['id' => 7],
                        'value_base64' => base64_encode($apiKey),
                    ]],
                ],
                [
                    'registry_key' => 'table:supplier',
                    'scope' => 'column',
                    'name' => 'domain_salt',
                    'policy' => 'protected_domain_secret',
                    'values' => [[
                        'primary_key' => ['id' => 7],
                        'value_base64' => base64_encode($domainSalt),
                    ]],
                ],
            ],
        ];
        self::assertSame($expected, $payload->toArray());
        self::assertSame(CanonicalJson::encode($expected), $payload->toJson());

        $parsed = CompanyBackupSecretPayload::fromJson(
            $payload->toJson(),
            $registry,
        );
        self::assertSame($expected, $parsed->toArray());
        self::assertSame(
            [$apiKey, $domainSalt],
            array_map(
                static fn (CompanyBackupSecretValue $value): string =>
                    $value->plaintext(),
                $parsed->values(),
            ),
        );
    }

    public function testRetainsRequiredDeclarationWhenSourceValueIsNull(): void
    {
        $payload = CompanyBackupSecretPayload::fromValues([], $this->registry());

        self::assertSame([
            [
                'registry_key' => 'table:supplier',
                'scope' => 'column',
                'name' => 'domain_salt',
                'policy' => 'protected_domain_secret',
                'values' => [],
            ],
        ], $payload->toArray()['declarations']);
        self::assertSame([], $payload->values());
    }

    public function testRejectsMissingRequiredOrUnregisteredDeclaration(): void
    {
        $registry = $this->registry();
        $valid = CompanyBackupSecretPayload::fromValues([], $registry)->toArray();

        $missing = $valid;
        $missing['declarations'] = [];
        $this->assertPayloadError(
            'secret_payload_required_missing',
            static fn () => CompanyBackupSecretPayload::fromArray(
                $missing,
                $registry,
            ),
        );

        $unknown = $valid;
        $unknown['declarations'][0]['name'] = 'runtime_token';
        $unknown['declarations'][0]['policy'] = 'omit_and_reconfigure';
        $this->assertPayloadError(
            'secret_payload_scope_mismatch',
            static fn () => CompanyBackupSecretPayload::fromArray(
                $unknown,
                $registry,
            ),
        );
    }

    public function testRejectsWrongPrimaryKeyDuplicateAndNonCanonicalBase64(): void
    {
        $registry = $this->registry();
        $valid = CompanyBackupSecretPayload::fromValues([
            CompanyBackupSecretValue::fromPlaintext(
                'table:supplier',
                CompanyBackupSecretScope::Column,
                'domain_salt',
                ['id' => 7],
                'synthetic-domain-salt-x',
            ),
        ], $registry)->toArray();

        $wrongKey = $valid;
        $wrongKey['declarations'][0]['values'][0]['primary_key'] = ['supplier_id' => 7];
        $this->assertPayloadError(
            'secret_payload_invalid',
            static fn () => CompanyBackupSecretPayload::fromArray(
                $wrongKey,
                $registry,
            ),
        );

        $duplicate = $valid;
        $duplicate['declarations'][0]['values'][] =
            $duplicate['declarations'][0]['values'][0];
        $this->assertPayloadError(
            'secret_payload_invalid',
            static fn () => CompanyBackupSecretPayload::fromArray(
                $duplicate,
                $registry,
            ),
        );

        $base64 = $valid;
        $base64['declarations'][0]['values'][0]['value_base64'] =
            rtrim($base64['declarations'][0]['values'][0]['value_base64'], '=');
        $this->assertPayloadError(
            'secret_payload_invalid',
            static fn () => CompanyBackupSecretPayload::fromArray(
                $base64,
                $registry,
            ),
        );
    }

    public function testRejectsNonCanonicalJsonAndForeignRegistry(): void
    {
        $registry = $this->registry();
        $payload = CompanyBackupSecretPayload::fromValues([], $registry);
        $pretty = json_encode(
            $payload->toArray(),
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );

        $this->assertPayloadError(
            'secret_payload_invalid',
            static fn () => CompanyBackupSecretPayload::fromJson(
                $pretty,
                $registry,
            ),
        );

        $foreign = $payload->toArray();
        $foreign['registry_fingerprint'] = 'sha256:' . str_repeat('f', 64);
        $this->assertPayloadError(
            'secret_payload_registry_mismatch',
            static fn () => CompanyBackupSecretPayload::fromArray(
                $foreign,
                $registry,
            ),
        );
    }

    /** @param callable():mixed $operation */
    private function assertPayloadError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatný secret payload musí být odmítnut.');
        } catch (CompanyBackupSecretPayloadException $e) {
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
                    'file-area:supplier-logos',
                    TenantDataObjectKind::FileArea,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    ['ownership' => ['strategy' => 'database_references']],
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
                                'policy' => TenantSecretPolicy::OptionalCredential->value,
                            ],
                            'domain_salt' => [
                                'policy' => TenantSecretPolicy::ProtectedDomainSecret->value,
                            ],
                            'runtime_token' => [
                                'policy' => TenantSecretPolicy::OmitAndReconfigure->value,
                            ],
                            'transport_mode' => [
                                'policy' => TenantSecretPolicy::NotSecret->value,
                            ],
                        ],
                    ],
                ),
            ],
            [$profile],
        ), $profile);
    }
}
