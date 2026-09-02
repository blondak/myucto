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

final class CompanyBackupPayrollPersonContactsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'contact_type',
        'contact_value_ciphertext',
        'contact_value_hash',
        'contact_value_masked',
        'is_primary',
        'is_active',
        'row_version',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    private const DATA_COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'contact_type',
        'is_primary',
        'is_active',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactContactsAndDiscriminatedSecret(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_person_contacts');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            [],
            ['id'],
            ['contact_value_hash'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [],
                [new CompanyBackupForeignKey(
                    ['supplier_id', 'employee_id'],
                    'payroll_employees',
                    ['supplier_id', 'id'],
                )],
            ),
        );

        self::assertSame(self::DATA_COLUMNS, $projection->dataColumns);
        self::assertSame([
            'contact_value_hash' => 'rederived_from_protected_secret',
            'contact_value_masked' => 'rederived_from_protected_secret',
        ], $projection->omitColumns);
        self::assertSame(
            TenantSecretPolicy::ProtectedDomainSecret,
            $projection->secretPolicies['contact_value_ciphertext'] ?? null,
        );
        self::assertSame(
            'contact_value_ciphertext',
            $projection->requiredSecretEnvelopeColumn(),
        );
        self::assertArrayNotHasKey('natural_key', $definition->details);
        self::assertSame(
            'contact_value_ciphertext<-payroll_sensitive_v1:'
                . '?contact_type{email=contact_email,phone=contact_phone}'
                . '@supplier_id,id->contact_value_ciphertext,'
                . 'contact_value_hash,contact_value_masked',
            $projection->protectedSecretMaterializations
                ->materializations[0]
                ->signature(),
        );

        $secretProjection = CompanyBackupProtectedSecretProjection::fromDefinition(
            $definition,
        );
        self::assertSame(
            CompanyBackupSecretStorage::ApplicationEncryptedContext,
            $secretProjection->storage['contact_value_ciphertext'] ?? null,
        );
        self::assertSame(
            'payroll:42:73:contact_email',
            $secretProjection->contextFor('contact_value_ciphertext', [
                'id' => 73,
                'supplier_id' => 42,
                'contact_type' => 'email',
            ]),
        );
        self::assertSame(
            'payroll:42:74:contact_phone',
            $secretProjection->contextFor('contact_value_ciphertext', [
                'id' => 74,
                'supplier_id' => 42,
                'contact_type' => 'phone',
            ]),
        );

        $reference = $projection->references->references[0];
        self::assertSame(
            'supplier_id,employee_id->payroll_employees:supplier_id,id',
            $reference->signature(),
        );
        self::assertSame(CompanyBackupReferenceMapping::TenantId, $reference->mapping);
        self::assertSame(CompanyBackupReferenceConstraint::Required, $reference->constraint);
        self::assertSame([], $reference->fallbacks);
        self::assertSame([], $projection->restoreOverrides->overrides);
    }
}
