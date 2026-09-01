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

final class CompanyBackupPayrollEmploymentDimensionsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employment_id',
        'dimension_id',
        'valid_from',
        'valid_to',
        'created_by',
        'updated_by',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactEmploymentDimensionProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_employment_dimensions',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['valid_to', 'created_by', 'updated_by'],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'dimension_id'],
                        'payroll_dimensions',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'employment_id'],
                        'payroll_employments',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertArrayNotHasKey('natural_key', $definition->details);
        self::assertSame(
            [
                'created_by->users:id',
                'supplier_id,dimension_id->payroll_dimensions:supplier_id,id',
                'supplier_id,employment_id->payroll_employments:supplier_id,id',
                'updated_by->users:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        foreach ([0, 3] as $actorIndex) {
            $actor = $projection->references->references[$actorIndex];
            self::assertSame(CompanyBackupReferenceMapping::Actor, $actor->mapping);
            self::assertSame(
                CompanyBackupReferenceConstraint::Optional,
                $actor->constraint,
            );
            self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
        }
        foreach ([1, 2] as $tenantIndex) {
            $tenant = $projection->references->references[$tenantIndex];
            self::assertSame(
                CompanyBackupReferenceMapping::TenantId,
                $tenant->mapping,
            );
            self::assertSame(
                CompanyBackupReferenceConstraint::Required,
                $tenant->constraint,
            );
            self::assertSame([], $tenant->nullableColumns);
        }
        self::assertSame([], $projection->restoreOverrides->overrides);
    }
}
