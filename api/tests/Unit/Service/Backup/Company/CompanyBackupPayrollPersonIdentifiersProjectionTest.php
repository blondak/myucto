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

final class CompanyBackupPayrollPersonIdentifiersProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'identifier_type',
        'value_ciphertext',
        'value_hash',
        'value_masked',
        'row_version',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    private const DATA_COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'identifier_type',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactIdentifiersAndDiscriminatedSecret(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_person_identifiers');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            [],
            ['id'],
            ['value_hash'],
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
            'value_hash' => 'rederived_from_protected_secret',
            'value_masked' => 'rederived_from_protected_secret',
        ], $projection->omitColumns);
        self::assertSame(
            TenantSecretPolicy::ProtectedDomainSecret,
            $projection->secretPolicies['value_ciphertext'] ?? null,
        );
        self::assertSame(
            'value_ciphertext',
            $projection->requiredSecretEnvelopeColumn(),
        );
        self::assertSame(
            ['supplier_id', 'employee_id', 'identifier_type'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            'value_ciphertext<-payroll_sensitive_v1:'
                . '?identifier_type{birth_number=personal_identifier,'
                . 'ecp=personal_identifier,'
                . 'foreign_tax_identifier=foreign_tax_identifier,'
                . 'vcp=personal_identifier}'
                . '@supplier_id,id->value_ciphertext,value_hash,value_masked',
            $projection->protectedSecretMaterializations
                ->materializations[0]
                ->signature(),
        );

        $secretProjection = CompanyBackupProtectedSecretProjection::fromDefinition(
            $definition,
        );
        self::assertSame(
            CompanyBackupSecretStorage::ApplicationEncryptedContext,
            $secretProjection->storage['value_ciphertext'] ?? null,
        );
        foreach ([
            'birth_number' => 'personal_identifier',
            'ecp' => 'personal_identifier',
            'foreign_tax_identifier' => 'foreign_tax_identifier',
            'vcp' => 'personal_identifier',
        ] as $type => $field) {
            self::assertSame(
                'payroll:42:73:' . $field,
                $secretProjection->contextFor('value_ciphertext', [
                    'id' => 73,
                    'supplier_id' => 42,
                    'identifier_type' => $type,
                ]),
            );
        }

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
