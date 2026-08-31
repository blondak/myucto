<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupProtectedSecretProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupProtectedSecretSource;
use MyInvoice\Service\Backup\Company\CompanyBackupCredentialSecretBundle;
use MyInvoice\Service\Backup\Company\CompanyBackupCredentialSecretSource;
use MyInvoice\Service\Backup\Company\CompanyBackupCredentialTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupOptionalSecretProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupOptionalSecretSource;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeCipher;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeException;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayload;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayloadException;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretSelection;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretScope;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretValue;
use MyInvoice\Service\Backup\Company\CompanyBackupSigningCredentialsProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSecretEnvelopeCollectorTest extends TestCase
{
    private const PASSWORD = 'synthetic-backup-password-42';
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';

    public function testCollectsEveryProtectedProjectionInsideSameSnapshot(): void
    {
        $pdo = $this->createStub(PDO::class);
        $source = new class implements CompanyBackupProtectedSecretSource {
            /** @var list<PDO> */
            public array $snapshots = [];

            /** @var list<string> */
            public array $calls = [];

            /** @return iterable<CompanyBackupSecretValue> */
            public function values(
                PDO $snapshot,
                int $supplierId,
                CompanyBackupProtectedSecretProjection $projection,
            ): iterable {
                $this->snapshots[] = $snapshot;
                $this->calls[] = $projection->registryKey . '@' . $supplierId;
                if ($projection->registryKey === 'table:supplier') {
                    yield CompanyBackupSecretValue::fromPlaintext(
                        $projection->registryKey,
                        CompanyBackupSecretScope::Column,
                        'domain_salt',
                        ['id' => $supplierId],
                        "synthetic-domain\0salt",
                    );
                    return;
                }
                yield CompanyBackupSecretValue::fromPlaintext(
                    $projection->registryKey,
                    CompanyBackupSecretScope::Column,
                    'sealed_payload',
                    ['id' => 91],
                    'synthetic-ledger-secret',
                );
            }
        };
        $registry = $this->registry();

        $sealed = (new CompanyBackupSecretEnvelopeCollector($source))->collect(
            $pdo,
            $registry,
            7,
            self::PASSWORD,
            self::BACKUP_ID,
        );

        self::assertSame([$pdo, $pdo], $source->snapshots);
        self::assertSame([
            'table:protected_ledger@7',
            'table:supplier@7',
        ], $source->calls);
        self::assertStringNotContainsString(
            'synthetic-ledger-secret',
            $sealed->ciphertext,
        );
        $plaintext = (new CompanyBackupSecretEnvelopeCipher())->open(
            $sealed,
            self::PASSWORD,
            self::BACKUP_ID,
            $registry->fingerprint,
        );
        $payload = CompanyBackupSecretPayload::fromJson($plaintext, $registry);
        self::assertSame(
            ['synthetic-ledger-secret', "synthetic-domain\0salt"],
            array_map(
                static fn (CompanyBackupSecretValue $value): string =>
                    $value->plaintext(),
                $payload->values(),
            ),
        );
    }

    public function testSealsRequiredEmptyDeclarationInsteadOfDroppingIt(): void
    {
        $source = new class implements CompanyBackupProtectedSecretSource {
            /** @return iterable<CompanyBackupSecretValue> */
            public function values(
                PDO $snapshot,
                int $supplierId,
                CompanyBackupProtectedSecretProjection $projection,
            ): iterable {
                return [];
            }
        };
        $registry = $this->singleRegistry();
        $sealed = (new CompanyBackupSecretEnvelopeCollector($source))->collect(
            $this->createStub(PDO::class),
            $registry,
            7,
            self::PASSWORD,
            self::BACKUP_ID,
        );
        $plaintext = (new CompanyBackupSecretEnvelopeCipher())->open(
            $sealed,
            self::PASSWORD,
            self::BACKUP_ID,
            $registry->fingerprint,
        );

        $payload = CompanyBackupSecretPayload::fromJson(
            $plaintext,
            $registry,
        );
        self::assertSame([], $payload->values());
        self::assertSame(
            'protected_domain_secret',
            $payload->toArray()['declarations'][0]['policy'] ?? null,
        );
        self::assertSame(
            [],
            $payload->toArray()['declarations'][0]['values'] ?? null,
        );
    }

    public function testRejectsValueOutsideProjectionBeforeSealing(): void
    {
        $source = new class implements CompanyBackupProtectedSecretSource {
            /** @return iterable<CompanyBackupSecretValue> */
            public function values(
                PDO $snapshot,
                int $supplierId,
                CompanyBackupProtectedSecretProjection $projection,
            ): iterable {
                yield CompanyBackupSecretValue::fromPlaintext(
                    'table:foreign_supplier',
                    CompanyBackupSecretScope::Column,
                    'domain_salt',
                    ['id' => $supplierId],
                    'must-not-be-sealed',
                );
            }
        };

        try {
            (new CompanyBackupSecretEnvelopeCollector($source))->collect(
                $this->createStub(PDO::class),
                $this->singleRegistry(),
                7,
                self::PASSWORD,
                self::BACKUP_ID,
            );
            self::fail('Cizí registry deklarace nesmí vstoupit do envelope.');
        } catch (CompanyBackupSecretPayloadException $e) {
            self::assertSame('secret_payload_scope_mismatch', $e->errorCode);
            self::assertStringNotContainsString(
                'must-not-be-sealed',
                $e->getMessage(),
            );
        }
    }

    public function testAddsOnlyExplicitOptionalValueToRequiredPayload(): void
    {
        $pdo = $this->createStub(PDO::class);
        $protected = new class implements CompanyBackupProtectedSecretSource {
            /** @return iterable<CompanyBackupSecretValue> */
            public function values(
                PDO $snapshot,
                int $supplierId,
                CompanyBackupProtectedSecretProjection $projection,
            ): iterable {
                yield CompanyBackupSecretValue::fromPlaintext(
                    $projection->registryKey,
                    CompanyBackupSecretScope::Column,
                    'domain_salt',
                    ['id' => $supplierId],
                    'synthetic-domain-salt',
                );
            }
        };
        $optional = new class implements CompanyBackupOptionalSecretSource {
            /** @var list<PDO> */
            public array $snapshots = [];

            /** @var list<string> */
            public array $selected = [];

            /** @return iterable<CompanyBackupSecretValue> */
            public function values(
                PDO $snapshot,
                int $supplierId,
                CompanyBackupOptionalSecretProjection $projection,
            ): iterable {
                $this->snapshots[] = $snapshot;
                $this->selected = array_map(
                    static fn ($entry): string => $entry->name,
                    $projection->entries,
                );
                yield CompanyBackupSecretValue::fromPlaintext(
                    $projection->registryKey,
                    CompanyBackupSecretScope::Column,
                    'api_key_enc',
                    ['id' => $supplierId],
                    'synthetic-selected-api-key',
                );
            }
        };
        $registry = $this->singleRegistry();
        $selection = CompanyBackupSecretSelection::fromArray([
            'registry_fingerprint' => $registry->fingerprint,
            'entries' => [[
                'registry_key' => 'table:supplier',
                'scope' => 'column',
                'name' => 'api_key_enc',
                'primary_key' => ['id' => 7],
            ]],
        ], $registry);

        $sealed = (new CompanyBackupSecretEnvelopeCollector(
            $protected,
            optionalSource: $optional,
        ))->collect(
            $pdo,
            $registry,
            7,
            self::PASSWORD,
            self::BACKUP_ID,
            $selection,
        );
        $plaintext = (new CompanyBackupSecretEnvelopeCipher())->open(
            $sealed,
            self::PASSWORD,
            self::BACKUP_ID,
            $registry->fingerprint,
        );
        $payload = CompanyBackupSecretPayload::fromJson($plaintext, $registry);

        self::assertSame([$pdo], $optional->snapshots);
        self::assertSame(['api_key_enc'], $optional->selected);
        self::assertSame(
            ['synthetic-selected-api-key', 'synthetic-domain-salt'],
            array_map(
                static fn (CompanyBackupSecretValue $value): string =>
                    $value->plaintext(),
                $payload->values(),
            ),
        );
    }

    public function testSelectedOptionalValueRequiresDedicatedSource(): void
    {
        $source = new class implements CompanyBackupProtectedSecretSource {
            /** @return iterable<CompanyBackupSecretValue> */
            public function values(
                PDO $snapshot,
                int $supplierId,
                CompanyBackupProtectedSecretProjection $projection,
            ): iterable {
                return [];
            }
        };
        $registry = $this->singleRegistry();
        $selection = CompanyBackupSecretSelection::fromArray([
            'registry_fingerprint' => $registry->fingerprint,
            'entries' => [[
                'registry_key' => 'table:supplier',
                'scope' => 'column',
                'name' => 'api_key_enc',
                'primary_key' => ['id' => 7],
            ]],
        ], $registry);

        try {
            (new CompanyBackupSecretEnvelopeCollector($source))->collect(
                $this->createStub(PDO::class),
                $registry,
                7,
                self::PASSWORD,
                self::BACKUP_ID,
                $selection,
            );
            self::fail('Optional credential nesmí číst obecný protected zdroj.');
        } catch (CompanyBackupSecretEnvelopeException $e) {
            self::assertSame('secret_selection_source_required', $e->errorCode);
        }
    }

    public function testAddsSelectedCompanyCredentialThroughDedicatedSource(): void
    {
        $pdo = $this->createStub(PDO::class);
        $protected = new class implements CompanyBackupProtectedSecretSource {
            public function values(
                PDO $snapshot,
                int $supplierId,
                CompanyBackupProtectedSecretProjection $projection,
            ): iterable {
                return [];
            }
        };
        $credential = new class implements CompanyBackupCredentialSecretSource {
            /** @var list<PDO> */
            public array $snapshots = [];

            /** @return iterable<CompanyBackupSecretValue> */
            public function values(
                PDO $snapshot,
                int $supplierId,
                CompanyBackupCredentialTableProjection $projection,
                array $entries,
            ): iterable {
                $this->snapshots[] = $snapshot;
                $row = [
                    'id' => 11,
                    'profile_id' => 41,
                    'vault_credential_id' => null,
                    'certificate_path' =>
                        'signing/profiles/supplier-7/profile-41/profile.p12',
                    'certificate_fingerprint' => str_repeat('a', 64),
                    'certificate_subject' => 'CN=Synthetic company',
                    'certificate_email' => null,
                    'certificate_valid_from' => '2026-01-01 00:00:00',
                    'certificate_valid_to' => '2028-01-01 00:00:00',
                    'certificate_usage_json' => null,
                    'passphrase_policy' => 'encrypted_store',
                    'passphrase_profile_id' => null,
                    'encrypted_passphrase' => 'source-ciphertext',
                    'is_active' => 1,
                    'created_by' => null,
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-02 00:00:00',
                    'deleted_at' => null,
                ];
                $bundle = CompanyBackupCredentialSecretBundle::fromExportRow(
                    $projection,
                    'company_file',
                    null,
                    $row,
                    ['certificate_path' => 'synthetic-pfx'],
                    ['encrypted_passphrase' => 'synthetic-passphrase'],
                );
                yield CompanyBackupSecretValue::fromPlaintext(
                    $projection->registryKey,
                    CompanyBackupSecretScope::CredentialVariant,
                    'company_file',
                    ['id' => 11],
                    $bundle->toJson(),
                );
            }
        };
        $registry = $this->credentialRegistry();
        $selection = CompanyBackupSecretSelection::fromArray([
            'registry_fingerprint' => $registry->fingerprint,
            'entries' => [[
                'registry_key' => 'table:signing_credentials',
                'scope' => 'credential_variant',
                'name' => 'company_file',
                'primary_key' => ['id' => 11],
            ]],
        ], $registry);

        $sealed = (new CompanyBackupSecretEnvelopeCollector(
            $protected,
            credentialSource: $credential,
        ))->collect(
            $pdo,
            $registry,
            7,
            self::PASSWORD,
            self::BACKUP_ID,
            $selection,
        );
        $plaintext = (new CompanyBackupSecretEnvelopeCipher())->open(
            $sealed,
            self::PASSWORD,
            self::BACKUP_ID,
            $registry->fingerprint,
        );
        $values = CompanyBackupSecretPayload::fromJson(
            $plaintext,
            $registry,
        )->values();

        self::assertSame([$pdo], $credential->snapshots);
        self::assertCount(1, $values);
        self::assertSame('company_file', $values[0]->name);
        self::assertSame(
            'synthetic-pfx',
            CompanyBackupCredentialSecretBundle::fromJson(
                $values[0]->plaintext(),
                CompanyBackupCredentialTableProjection::fromDefinition(
                    $registry->registry->definition('table:signing_credentials')
                        ?? throw new \LogicException('Chybí testovací credential.'),
                ),
                'company_file',
                ['id' => 11],
            )->attachment('certificate_path'),
        );
    }

    private function singleRegistry(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [$this->supplierDefinition($profile)],
            [$profile],
        ), $profile);
    }

    private function credentialRegistry(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [new TenantDataDefinition(
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
            )],
            [$profile],
        ), $profile);
    }

    private function registry(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                new TenantDataDefinition(
                    'table:protected_ledger',
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
                            'sealed_payload' => [
                                'policy' =>
                                    TenantSecretPolicy::ProtectedDomainSecret->value,
                                'storage' => 'application_encrypted',
                            ],
                        ],
                    ],
                ),
                $this->supplierDefinition($profile),
            ],
            [$profile],
        ), $profile);
    }

    private function supplierDefinition(string $profile): TenantDataDefinition
    {
        return new TenantDataDefinition(
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
                        'storage' => 'application_encrypted',
                    ],
                    'domain_salt' => [
                        'policy' => TenantSecretPolicy::ProtectedDomainSecret->value,
                        'storage' => 'raw',
                    ],
                ],
            ],
        );
    }
}
