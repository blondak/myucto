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

final class CompanyBackupPayrollPersonAccountsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'label',
        'bank_account_ciphertext',
        'bank_account_hash',
        'bank_account_masked',
        'allocation_basis_points',
        'effective_from',
        'effective_to',
        'is_active',
        'row_version',
        'verification_source',
        'verified_on',
        'verified_by',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    private const DATA_COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'label',
        'allocation_basis_points',
        'effective_from',
        'effective_to',
        'is_active',
        'row_version',
        'verification_source',
        'verified_on',
        'verified_by',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactAccountAndRequiredTargetSecret(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_person_accounts');
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
                    'effective_to',
                    'verification_source',
                    'verified_on',
                    'verified_by',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'employee_id'],
                        'payroll_employees',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(['verified_by'], 'users', ['id']),
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
                'supplier_id,employee_id->payroll_employees:supplier_id,id',
                'verified_by->users:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );

        $employee = $projection->references->references[0];
        self::assertSame(CompanyBackupReferenceMapping::TenantId, $employee->mapping);
        self::assertSame(CompanyBackupReferenceConstraint::Required, $employee->constraint);
        self::assertSame([], $employee->fallbacks);

        $verifier = $projection->references->references[1];
        self::assertSame(CompanyBackupReferenceMapping::Actor, $verifier->mapping);
        self::assertSame(CompanyBackupReferenceConstraint::Optional, $verifier->constraint);
        self::assertSame(['restore_actor'], $verifier->fallbacks);
        self::assertSame([], $projection->restoreOverrides->overrides);
    }
}
