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

final class CompanyBackupPayrollShiftsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employment_id',
        'calendar_id',
        'series_key',
        'revision_no',
        'supersedes_id',
        'starts_at_utc',
        'ends_at_utc',
        'timezone_name',
        'break_minutes',
        'remote_work',
        'standby_minutes',
        'status',
        'row_version',
        'created_by',
        'published_by',
        'published_at',
        'created_at',
    ];

    public function testDeclaresExactVersionedShiftProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_shifts');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'calendar_id',
                    'supersedes_id',
                    'created_by',
                    'published_by',
                    'published_at',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'calendar_id'],
                        'payroll_work_calendars',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['created_by'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'employment_id'],
                        'payroll_employments',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['published_by'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'supersedes_id'],
                        'payroll_shifts',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'series_key', 'revision_no'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'created_by->users:id',
                'published_by->users:id',
                'supplier_id,calendar_id->payroll_work_calendars:supplier_id,id',
                'supplier_id,employment_id->payroll_employments:supplier_id,id',
                'supplier_id,supersedes_id->payroll_shifts:supplier_id,id',
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
        self::assertSame(
            ['calendar_id'],
            $projection->references->references[2]->nullableColumns,
        );
        self::assertSame(
            ['supersedes_id'],
            $projection->references->references[4]->nullableColumns,
        );
    }
}
