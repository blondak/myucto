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

final class CompanyBackupPayrollEmploymentChecklistItemsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employment_id',
        'phase',
        'item_key',
        'status',
        'due_date',
        'completed_at',
        'completed_by',
        'note',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactEmploymentChecklistProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_employment_checklist_items',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['due_date', 'completed_at', 'completed_by', 'note'],
                [
                    new CompanyBackupForeignKey(
                        ['completed_by'],
                        'users',
                        ['id'],
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
        self::assertSame(
            ['supplier_id', 'employment_id', 'phase', 'item_key'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'completed_by->users:id',
                'supplier_id,employment_id->payroll_employments:supplier_id,id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        $actor = $projection->references->references[0];
        self::assertSame(CompanyBackupReferenceMapping::Actor, $actor->mapping);
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional,
            $actor->constraint,
        );
        self::assertSame(['completed_by'], $actor->nullableColumns);
        self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
        $employment = $projection->references->references[1];
        self::assertSame(
            CompanyBackupReferenceMapping::TenantId,
            $employment->mapping,
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Required,
            $employment->constraint,
        );
        self::assertSame([], $projection->restoreOverrides->overrides);
    }
}
