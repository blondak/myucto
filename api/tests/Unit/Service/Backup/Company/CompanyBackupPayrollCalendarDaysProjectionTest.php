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

final class CompanyBackupPayrollCalendarDaysProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'calendar_id',
        'day_date',
        'day_kind',
        'planned_minutes',
        'holiday_code',
        'holiday_name',
        'note',
        'row_version',
        'created_by',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactCalendarDayProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_calendar_days');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['holiday_code', 'holiday_name', 'note', 'created_by'],
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
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'calendar_id', 'day_date'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'created_by->users:id',
                'supplier_id,calendar_id->payroll_work_calendars:supplier_id,id',
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
        self::assertSame(['created_by'], $actor->nullableColumns);
        self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
    }
}
