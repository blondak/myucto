<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretScope;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretStorage;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretValue;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PHPUnit\Framework\TestCase;

final class CompanyBackupProtectedSecretMaterializationSetTest extends TestCase
{
    public function testResealsPlaintextForRemappedTenantAndEntity(): void
    {
        $service = $this->sensitiveData();
        $value = $this->secretValue();

        $stored = $this->projection()->protectedSecretMaterializations->materialize(
            $value,
            ['id' => 73, 'supplier_id' => 42, 'name' => 'Synthetic office'],
            ['id' => 173, 'supplier_id' => 142, 'name' => 'Synthetic office'],
            $service,
        );

        self::assertSame(
            [
                'bank_account_ciphertext',
                'bank_account_hash',
                'bank_account_masked',
            ],
            array_keys($stored),
        );
        self::assertStringStartsWith('enc:v2:', $stored['bank_account_ciphertext']);
        self::assertStringNotContainsString(
            $value->plaintext(),
            $stored['bank_account_ciphertext'],
        );
        self::assertSame(32, strlen($stored['bank_account_hash']));
        self::assertSame(
            $service->lookupHash(
                $value->plaintext(),
                PayrollSensitiveField::BANK_ACCOUNT,
                142,
            ),
            $stored['bank_account_hash'],
        );
        self::assertSame(
            $service->mask(
                $value->plaintext(),
                PayrollSensitiveField::BANK_ACCOUNT,
            ),
            $stored['bank_account_masked'],
        );
        self::assertSame(
            $value->plaintext(),
            $service->reveal(
                $stored['bank_account_ciphertext'],
                PayrollSensitiveField::BANK_ACCOUNT,
                142,
                173,
            ),
        );

        $this->expectException(\RuntimeException::class);
        $service->reveal(
            $stored['bank_account_ciphertext'],
            PayrollSensitiveField::BANK_ACCOUNT,
            42,
            73,
        );
    }

    public function testRejectsContextOrDerivedColumnsOutsideExactContract(): void
    {
        $wrongContext = $this->definition();
        $details = $wrongContext->details;
        $details['secrets']['bank_account_ciphertext']['context'] =
            'payroll:{supplier_id}:{id}:personal_identifier';
        $this->assertProjectionError(new TenantDataDefinition(
            $wrongContext->key,
            $wrongContext->kind,
            $wrongContext->policy,
            $wrongContext->profiles,
            $details,
        ), 'bank_account_ciphertext');

        $missingDerivedOmission = $this->definition();
        $details = $missingDerivedOmission->details;
        unset($details['company_backup']['omit_columns']['bank_account_hash']);
        $this->assertProjectionError(new TenantDataDefinition(
            $missingDerivedOmission->key,
            $missingDerivedOmission->kind,
            $missingDerivedOmission->policy,
            $missingDerivedOmission->profiles,
            $details,
        ), 'bank_account_hash');
    }

    public function testRejectsForeignSecretAndNonIntegerTargetWithoutLeakingValue(): void
    {
        $set = $this->projection()->protectedSecretMaterializations;
        foreach ([
            [
                CompanyBackupSecretValue::fromPlaintext(
                    'table:foreign_records',
                    CompanyBackupSecretScope::Column,
                    'bank_account_ciphertext',
                    ['id' => 73],
                    '1000000005 / 0100',
                ),
                ['id' => 73, 'supplier_id' => 42],
                ['id' => 173, 'supplier_id' => 142],
            ],
            [
                $this->secretValue(),
                ['id' => 74, 'supplier_id' => 42],
                ['id' => 173, 'supplier_id' => 142],
            ],
            [
                $this->secretValue(),
                ['id' => 73, 'supplier_id' => 42],
                ['id' => 173, 'supplier_id' => '142'],
            ],
        ] as [$value, $sourceRow, $targetRow]) {
            try {
                $set->materialize(
                    $value,
                    $sourceRow,
                    $targetRow,
                    $this->sensitiveData(),
                );
                self::fail('Cizí secret ani nekanonické cílové ID nesmí projít.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame(
                    'secret_restore_materialization_invalid',
                    $e->errorCode,
                );
                self::assertSame('bank_account_ciphertext', $e->column);
                self::assertStringNotContainsString(
                    $value->plaintext(),
                    $e->getMessage(),
                );
            }
        }
    }

    public function testMaterializesMissingNullableSecretAsAtomicNullTriple(): void
    {
        $definition = $this->definition();
        $details = $definition->details;
        $details['company_backup']['protected_secret_materializations'][0]
            ['nullable'] = true;
        $projection = CompanyBackupTableProjection::fromDefinition(
            new TenantDataDefinition(
                $definition->key,
                $definition->kind,
                $definition->policy,
                $definition->profiles,
                $details,
            ),
        );

        self::assertTrue(
            $projection->protectedSecretMaterializations
                ->materializations[0]
                ->nullable,
        );
        self::assertSame(
            [
                'bank_account_ciphertext' => null,
                'bank_account_hash' => null,
                'bank_account_masked' => null,
            ],
            $projection->protectedSecretMaterializations->materializeForColumn(
                'bank_account_ciphertext',
                null,
                ['id' => 73, 'supplier_id' => 42, 'name' => 'Synthetic office'],
                ['id' => 173, 'supplier_id' => 142, 'name' => 'Synthetic office'],
                $this->sensitiveData(),
            ),
        );
    }

    public function testRejectsMissingValueForRequiredSecret(): void
    {
        try {
            $this->projection()->protectedSecretMaterializations
                ->materializeForColumn(
                    'bank_account_ciphertext',
                    null,
                    ['id' => 73, 'supplier_id' => 42],
                    ['id' => 173, 'supplier_id' => 142],
                    $this->sensitiveData(),
                );
            self::fail('Chybějící povinný secret nesmí vytvořit NULL trojici.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame(
                'secret_restore_materialization_invalid',
                $e->errorCode,
            );
            self::assertSame('bank_account_ciphertext', $e->column);
        }
    }

    private function projection(): CompanyBackupTableProjection
    {
        return CompanyBackupTableProjection::fromDefinition($this->definition());
    }

    private function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:synthetic_records',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [
                    'bank_account_ciphertext' => [
                        'policy' =>
                            TenantSecretPolicy::ProtectedDomainSecret->value,
                        'storage' =>
                            CompanyBackupSecretStorage::ApplicationEncryptedContext->value,
                        'context' =>
                            'payroll:{supplier_id}:{id}:bank_account',
                    ],
                ],
                'company_backup' => [
                    'data_columns' => ['id', 'supplier_id', 'name'],
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [
                        'bank_account_hash' =>
                            'rederived_from_protected_secret',
                        'bank_account_masked' =>
                            'rederived_from_protected_secret',
                    ],
                    'protected_secret_materializations' => [[
                        'entity_id_column' => 'id',
                        'field' => 'bank_account',
                        'materializer' => 'payroll_sensitive_v1',
                        'nullable' => false,
                        'secret_column' => 'bank_account_ciphertext',
                        'target_columns' => [
                            'ciphertext' => 'bank_account_ciphertext',
                            'lookup_hash' => 'bank_account_hash',
                            'masked' => 'bank_account_masked',
                        ],
                        'tenant_id_column' => 'supplier_id',
                    ]],
                    'references' => [[
                        'columns' => ['supplier_id'],
                        'target' => 'table:supplier',
                        'target_columns' => ['id'],
                        'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                        'constraint' =>
                            CompanyBackupReferenceConstraint::Required->value,
                        'nullable_columns' => [],
                        'fallbacks' => [],
                    ]],
                    'restore_overrides' => [],
                ],
            ],
        );
    }

    private function secretValue(): CompanyBackupSecretValue
    {
        return CompanyBackupSecretValue::fromPlaintext(
            'table:synthetic_records',
            CompanyBackupSecretScope::Column,
            'bank_account_ciphertext',
            ['id' => 73],
            '1000000005 / 0100',
        );
    }

    private function sensitiveData(): PayrollSensitiveData
    {
        $config = new Config([
            'app' => [
                'secret_encryption_key' => base64_encode(str_repeat('t', 32)),
                'payroll_hash_key' => base64_encode(str_repeat('k', 32)),
            ],
        ]);
        return new PayrollSensitiveData(new SecretEncryption($config), $config);
    }

    private function assertProjectionError(
        TenantDataDefinition $definition,
        string $column,
    ): void {
        try {
            CompanyBackupTableProjection::fromDefinition($definition);
            self::fail('Neúplný cílový secret kontrakt nesmí projít.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame(
                'data_protected_secret_materialization_metadata_invalid',
                $e->errorCode,
            );
            self::assertSame($column, $e->column);
        }
    }
}
