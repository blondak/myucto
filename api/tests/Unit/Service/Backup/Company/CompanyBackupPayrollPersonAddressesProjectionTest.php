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

final class CompanyBackupPayrollPersonAddressesProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'address_type',
        'street_line',
        'city',
        'postal_code',
        'country_code',
        'effective_from',
        'effective_to',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactAddressHistoryProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_person_addresses');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['effective_to'],
                [new CompanyBackupForeignKey(
                    ['supplier_id', 'employee_id'],
                    'payroll_employees',
                    ['supplier_id', 'id'],
                )],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            [
                'supplier_id',
                'employee_id',
                'address_type',
                'effective_from',
            ],
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
