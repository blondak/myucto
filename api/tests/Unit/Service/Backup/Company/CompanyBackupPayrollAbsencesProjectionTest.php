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

final class CompanyBackupPayrollAbsencesProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employment_id',
        'absence_type',
        'date_from',
        'date_to',
        'timezone_name',
        'partial_first_minutes',
        'partial_last_minutes',
        'note',
        'compensation_policy',
        'compensation_rate_basis_points',
        'average_snapshot_id',
        'support_status',
        'status',
        'correction_pending',
        'row_version',
        'requested_by',
        'decided_by',
        'decided_at',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactAbsenceProjectionAndReferences(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_absences');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'partial_first_minutes',
                    'partial_last_minutes',
                    'note',
                    'compensation_rate_basis_points',
                    'average_snapshot_id',
                    'requested_by',
                    'decided_by',
                    'decided_at',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'average_snapshot_id'],
                        'payroll_average_earning_snapshots',
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
                'decided_by->users:id',
                'requested_by->users:id',
                'supplier_id,average_snapshot_id'
                    . '->payroll_average_earning_snapshots:supplier_id,id',
                'supplier_id,employment_id->payroll_employments:supplier_id,id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        foreach ([0, 1] as $actorIndex) {
            $actor = $projection->references->references[$actorIndex];
            self::assertSame(CompanyBackupReferenceMapping::Actor, $actor->mapping);
            self::assertSame(
                CompanyBackupReferenceConstraint::Optional,
                $actor->constraint,
            );
            self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
        }
        $average = $projection->references->references[2];
        self::assertSame(
            CompanyBackupReferenceConstraint::Required,
            $average->constraint,
        );
        self::assertSame(['average_snapshot_id'], $average->nullableColumns);
    }
}
