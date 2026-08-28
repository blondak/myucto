<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupSecretInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretInventoryCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretOmissionCountSource;
use MyInvoice\Service\Backup\Company\CompanyBackupSigningCredentialsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretDeclaration;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSecretInventoryTest extends TestCase
{
    public function testRetainsVerifiedZeroForEveryDefaultOmission(): void
    {
        $registry = $this->registry();
        $counts = [];
        foreach (CompanyBackupSecretInventory::requiredDeclarations($registry) as $item) {
            $counts[$item->signature()] = match ($item->name) {
                'api_key_enc' => 0,
                'company_file' => 2,
                default => 1,
            };
        }

        $inventory = CompanyBackupSecretInventory::fromCounts($counts, $registry);

        self::assertSame([
            'table:epo_signing_credentials:column:passphrase_ciphertext',
            'table:epo_signing_credentials:column:pfx_ciphertext',
            'table:signing_credentials:credential_variant:company_file',
            'table:signing_credentials:credential_variant:personal_file',
            'table:signing_credentials:credential_variant:personal_vault',
            'table:supplier:column:api_key_enc',
            'table:supplier:column:runtime_token',
        ], array_map(
            static fn ($item): string => $item->registryKey . ':'
                . $item->scope->value . ':' . $item->name,
            $inventory->omissions,
        ));
        self::assertSame(0, $inventory->omissions[5]->count);
        self::assertSame('credential_not_selected', $inventory->omissions[5]->reason->value);
        self::assertSame(
            'reconfigure_after_restore',
            $inventory->omissions[6]->reason->value,
        );
        self::assertSame(
            $inventory->toArray(),
            CompanyBackupSecretInventory::fromArray(
                $inventory->toArray(),
                $registry,
            )->toArray(),
        );
    }

    public function testRejectsMissingRegistryOmissionEvenWhenItsCountWouldBeZero(): void
    {
        $registry = $this->registry();
        $counts = [];
        foreach (CompanyBackupSecretInventory::requiredDeclarations($registry) as $item) {
            $counts[$item->signature()] = 0;
        }
        unset($counts['table:supplier:column:api_key_enc']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('úplný registry rozsah');
        CompanyBackupSecretInventory::fromCounts($counts, $registry);
    }

    public function testRejectsManifestPolicyThatDiffersFromRegistry(): void
    {
        $registry = $this->registry();
        $counts = [];
        foreach (CompanyBackupSecretInventory::requiredDeclarations($registry) as $item) {
            $counts[$item->signature()] = 0;
        }
        $value = CompanyBackupSecretInventory::fromCounts($counts, $registry)->toArray();
        $value['omissions'][0]['policy'] = TenantSecretPolicy::OptionalCredential->value;

        $this->expectException(\InvalidArgumentException::class);
        CompanyBackupSecretInventory::fromArray($value, $registry);
    }

    public function testCollectorMeasuresEveryDeclarationAgainstSameSnapshot(): void
    {
        $pdo = $this->createStub(PDO::class);
        $source = new class implements CompanyBackupSecretOmissionCountSource {
            /** @var list<PDO> */
            public array $snapshots = [];

            /** @var list<string> */
            public array $calls = [];

            /**
             * @param list<CompanyBackupSecretDeclaration> $declarations
             * @return array<string,int>
             */
            public function counts(
                PDO $snapshot,
                int $supplierId,
                TenantDataDefinition $definition,
                array $declarations,
                TenantDataRegistry $registry,
            ): array {
                $this->snapshots[] = $snapshot;
                $this->calls[] = $definition->key . '@' . $supplierId;
                $counts = [];
                foreach ($declarations as $declaration) {
                    $counts[$declaration->signature()] = $declaration->name === 'api_key_enc'
                        ? 0
                        : 1;
                }
                return $counts;
            }
        };

        $inventory = (new CompanyBackupSecretInventoryCollector($source))->collect(
            $pdo,
            $this->registry(),
            7,
        );

        self::assertSame([$pdo, $pdo, $pdo], $source->snapshots);
        self::assertSame([
            'table:epo_signing_credentials@7',
            'table:signing_credentials@7',
            'table:supplier@7',
        ], $source->calls);
        self::assertSame(0, $inventory->omissions[5]->count);
    }

    public function testCollectorRejectsCountSourceThatDropsVerifiedZero(): void
    {
        $source = new class implements CompanyBackupSecretOmissionCountSource {
            /**
             * @param list<CompanyBackupSecretDeclaration> $declarations
             * @return array<string,int>
             */
            public function counts(
                PDO $snapshot,
                int $supplierId,
                TenantDataDefinition $definition,
                array $declarations,
                TenantDataRegistry $registry,
            ): array {
                return [];
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('secret_count_scope_mismatch');
        (new CompanyBackupSecretInventoryCollector($source))->collect(
            $this->createStub(PDO::class),
            $this->registry(),
            7,
        );
    }

    public function testRejectsOptionalCredentialWithoutExecutableInventoryContract(): void
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $snapshot = TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [new TenantDataDefinition(
                'table:unclassified_credentials',
                TenantDataObjectKind::Table,
                TenantDataPolicy::OptionalCredential,
                [$profile],
                ['primary_key' => ['id']],
            )],
            [$profile],
        ), $profile);

        $this->expectException(\InvalidArgumentException::class);
        CompanyBackupSecretInventory::requiredDeclarations($snapshot);
    }

    public function testRejectsPersonalAttachmentWithoutCountedSecretValues(): void
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $snapshot = TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [new TenantDataDefinition(
                'table:unclassified_personal_credentials',
                TenantDataObjectKind::Table,
                TenantDataPolicy::PersonalSecretAttachment,
                [$profile],
                ['primary_key' => ['id'], 'secrets' => []],
            )],
            [$profile],
        ), $profile);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nemá inventarizované hodnoty');
        CompanyBackupSecretInventory::requiredDeclarations($snapshot);
    }

    private function registry(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
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
                new TenantDataDefinition(
                    'table:epo_signing_credentials',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::PersonalSecretAttachment,
                    [$profile],
                    [
                        'primary_key' => ['id'],
                        'ownership' => [
                            'owner_column' => 'owner_user_id',
                            'strategy' => 'personal_credential_owner',
                        ],
                        'secrets' => [
                            'passphrase_ciphertext' => [
                                'policy' =>
                                    TenantSecretPolicy::PersonalWithDualConsent->value,
                            ],
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
            ],
            [$profile],
        ), $profile);
    }
}
