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

final class CompanyBackupPayrollSicknessEventsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'absence_id',
        'first_day_fully_worked',
        'insurance_eligibility_confirmed',
        'conflicting_benefit_excluded',
        'average_snapshot_id',
        'compensation_window_from',
        'compensation_window_to',
        'reduced_hourly_minor',
        'compensation_minor',
        'support_status',
        'ruleset_id',
        'ruleset_hash',
        'calculation_trace',
        'row_version',
        'calculated_by',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactSicknessEventProjectionAndReferences(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_sickness_events');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['calculated_by'],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'absence_id'],
                        'payroll_absences',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'average_snapshot_id'],
                        'payroll_average_earning_snapshots',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'absence_id'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            ['ruleset_id'],
            $projection->preservedIdentifiers->columns,
        );
        self::assertSame(
            [
                'calculated_by->users:id',
                'supplier_id,absence_id->payroll_absences:supplier_id,id',
                'supplier_id,average_snapshot_id'
                    . '->payroll_average_earning_snapshots:supplier_id,id',
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
        self::assertSame(['calculated_by'], $actor->nullableColumns);
        self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
    }
}
