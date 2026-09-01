<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupProtectedSecretProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretStorage;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollInstitutionAccountsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'institution_id',
        'institution_name',
        'bank_account_ciphertext',
        'bank_account_hash',
        'bank_account_masked',
        'currency_code',
        'variable_symbol',
        'specific_symbol',
        'constant_symbol',
        'valid_from',
        'valid_to',
        'source_kind',
        'source_reference',
        'verified_on',
        'verified_by',
        'created_by',
        'updated_by',
        'row_version',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    private const DATA_COLUMNS = [
        'id',
        'supplier_id',
        'institution_id',
        'institution_name',
        'currency_code',
        'variable_symbol',
        'specific_symbol',
        'constant_symbol',
        'valid_from',
        'valid_to',
        'source_kind',
        'source_reference',
        'verified_on',
        'verified_by',
        'created_by',
        'updated_by',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactAccountAndTargetSecretContract(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_institution_accounts');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            [],
            ['id'],
            ['bank_account_hash'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'variable_symbol',
                    'specific_symbol',
                    'constant_symbol',
                    'valid_to',
                    'verified_by',
                    'created_by',
                    'updated_by',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'institution_id'],
                        'payroll_institutions',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(['verified_by'], 'users', ['id']),
                    new CompanyBackupForeignKey(['created_by'], 'users', ['id']),
                    new CompanyBackupForeignKey(['updated_by'], 'users', ['id']),
                ],
            ),
        );

        self::assertSame(self::DATA_COLUMNS, $projection->dataColumns);
        self::assertSame([
            'bank_account_hash' => 'rederived_from_protected_secret',
            'bank_account_masked' => 'rederived_from_protected_secret',
        ], $projection->omitColumns);
        self::assertSame(
            TenantSecretPolicy::ProtectedDomainSecret,
            $projection->secretPolicies['bank_account_ciphertext'] ?? null,
        );
        self::assertSame(
            'bank_account_ciphertext',
            $projection->requiredSecretEnvelopeColumn(),
        );
        self::assertArrayNotHasKey('natural_key', $definition->details);
        self::assertSame(
            'bank_account_ciphertext<-payroll_sensitive_v1:bank_account'
                . '@supplier_id,id'
                . '->bank_account_ciphertext,bank_account_hash,bank_account_masked',
            $projection->protectedSecretMaterializations
                ->materializations[0]
                ->signature(),
        );

        $secretProjection = CompanyBackupProtectedSecretProjection::fromDefinition(
            $definition,
        );
        self::assertSame(
            CompanyBackupSecretStorage::ApplicationEncryptedContext,
            $secretProjection->storage['bank_account_ciphertext'] ?? null,
        );
        self::assertSame(
            'payroll:{supplier_id}:{id}:bank_account',
            $secretProjection->contexts['bank_account_ciphertext'] ?? null,
        );
        self::assertSame(
            [
                'created_by->users:id',
                'supplier_id,institution_id->payroll_institutions:supplier_id,id',
                'updated_by->users:id',
                'verified_by->users:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        foreach ($projection->references->references as $reference) {
            if ($reference->mapping === CompanyBackupReferenceMapping::Actor) {
                self::assertSame(
                    CompanyBackupReferenceConstraint::Optional,
                    $reference->constraint,
                );
                self::assertSame(['null', 'restore_actor'], $reference->fallbacks);
            }
        }
        self::assertSame([], $projection->restoreOverrides->overrides);
    }
}
