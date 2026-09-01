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

final class CompanyBackupPayrollOvertimeAveragingPeriodsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'valid_from',
        'valid_to',
        'weeks',
        'basis',
        'collective_agreement_reference',
        'note',
        'row_version',
        'created_by',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactOvertimeAveragingPeriodProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_overtime_averaging_periods',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'valid_to',
                    'collective_agreement_reference',
                    'note',
                    'created_by',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['created_by'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id'],
                        'supplier',
                        ['id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'valid_from'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            ['created_by->users:id', 'supplier_id->supplier:id'],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        $actor = $projection->references->references[0];
        self::assertSame(CompanyBackupReferenceMapping::Actor, $actor->mapping);
        self::assertSame(CompanyBackupReferenceConstraint::Optional, $actor->constraint);
        self::assertSame(['created_by'], $actor->nullableColumns);
        self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
        $supplier = $projection->references->references[1];
        self::assertSame(CompanyBackupReferenceMapping::TenantId, $supplier->mapping);
        self::assertSame(CompanyBackupReferenceConstraint::Required, $supplier->constraint);
        self::assertSame([], $supplier->nullableColumns);
        self::assertSame([], $projection->restoreOverrides->overrides);
    }
}
