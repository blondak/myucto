<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollPersonIdentityHistoryProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'full_name',
        'first_name',
        'last_name',
        'title_prefix',
        'title_suffix',
        'birth_surname',
        'birth_date',
        'birth_place',
        'birth_country_code',
        'citizenship_country_code',
        'sex',
        'effective_from',
        'effective_to',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactIdentityHistoryProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_identity_history',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'first_name',
                    'last_name',
                    'title_prefix',
                    'title_suffix',
                    'birth_surname',
                    'birth_date',
                    'birth_place',
                    'birth_country_code',
                    'citizenship_country_code',
                    'sex',
                    'effective_to',
                ],
                [new CompanyBackupForeignKey(
                    ['supplier_id', 'employee_id'],
                    'payroll_employees',
                    ['supplier_id', 'id'],
                )],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'employee_id', 'effective_from'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'supplier_id,employee_id->payroll_employees:supplier_id,id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        $employee = $projection->references->references[0];
        self::assertSame(CompanyBackupReferenceMapping::TenantId, $employee->mapping);
        self::assertSame(
            CompanyBackupReferenceConstraint::Required,
            $employee->constraint,
        );
        self::assertSame([], $employee->nullableColumns);
        self::assertSame([], $employee->fallbacks);
        self::assertSame([], $projection->restoreOverrides->overrides);
    }
}
