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

final class CompanyBackupPayrollInstitutionsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'institution_type',
        'institution_code',
        'created_at',
    ];

    public function testDeclaresExactPayrollInstitutionProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_institutions');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [],
                [new CompanyBackupForeignKey(
                    ['supplier_id'],
                    'supplier',
                    ['id'],
                )],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'institution_type', 'institution_code'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            ['supplier_id->supplier:id'],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        $supplier = $projection->references->references[0];
        self::assertSame(CompanyBackupReferenceMapping::TenantId, $supplier->mapping);
        self::assertSame(
            CompanyBackupReferenceConstraint::Required,
            $supplier->constraint,
        );
        self::assertSame([], $supplier->nullableColumns);
        self::assertSame([], $supplier->fallbacks);
        self::assertSame([], $projection->restoreOverrides->overrides);
    }
}
