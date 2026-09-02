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

final class CompanyBackupPayrollDependantsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'relation',
        'full_name',
        'birth_date',
        'birth_number_ciphertext',
        'birth_number_hash',
        'birth_number_masked',
        'ztp_p',
        'student',
        'existence_from',
        'existence_to',
        'note',
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
        'employee_id',
        'relation',
        'full_name',
        'birth_date',
        'ztp_p',
        'student',
        'existence_from',
        'existence_to',
        'note',
        'created_by',
        'updated_by',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactDependantAndNullableSecretContract(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_dependants');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            [],
            ['id'],
            ['birth_number_hash'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'birth_number_ciphertext',
                    'birth_number_hash',
                    'birth_number_masked',
                    'existence_to',
                    'note',
                    'created_by',
                    'updated_by',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'employee_id'],
                        'payroll_employees',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(['created_by'], 'users', ['id']),
                    new CompanyBackupForeignKey(['updated_by'], 'users', ['id']),
                ],
            ),
        );

        self::assertSame(self::DATA_COLUMNS, $projection->dataColumns);
        self::assertSame([
            'birth_number_hash' => 'rederived_from_protected_secret',
            'birth_number_masked' => 'rederived_from_protected_secret',
        ], $projection->omitColumns);
        self::assertSame(
            TenantSecretPolicy::ProtectedDomainSecret,
            $projection->secretPolicies['birth_number_ciphertext'] ?? null,
        );
        self::assertSame(
            'birth_number_ciphertext',
            $projection->requiredSecretEnvelopeColumn(),
        );
        self::assertArrayNotHasKey('natural_key', $definition->details);

        $materialization =
            $projection->protectedSecretMaterializations->materializations[0];
        self::assertTrue($materialization->nullable);
        self::assertSame(
            'birth_number_ciphertext?<-payroll_sensitive_v1:personal_identifier'
                . '@supplier_id,id'
                . '->birth_number_ciphertext,birth_number_hash,birth_number_masked',
            $materialization->signature(),
        );

        $secretProjection = CompanyBackupProtectedSecretProjection::fromDefinition(
            $definition,
        );
        self::assertSame(
            CompanyBackupSecretStorage::ApplicationEncryptedContext,
            $secretProjection->storage['birth_number_ciphertext'] ?? null,
        );
        self::assertSame(
            'payroll:{supplier_id}:{id}:personal_identifier',
            $secretProjection->contexts['birth_number_ciphertext'] ?? null,
        );
        self::assertSame(
            [
                'created_by->users:id',
                'supplier_id,employee_id->payroll_employees:supplier_id,id',
                'updated_by->users:id',
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
