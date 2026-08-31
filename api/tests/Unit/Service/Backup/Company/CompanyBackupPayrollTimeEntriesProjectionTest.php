<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupColumnCodec;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollTimeEntriesProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employment_id',
        'series_key',
        'revision_no',
        'supersedes_id',
        'category',
        'starts_at_utc',
        'ends_at_utc',
        'timezone_name',
        'break_minutes',
        'source_kind',
        'source_reference',
        'source_hash',
        'status',
        'row_version',
        'created_by',
        'approved_by',
        'approved_at',
        'created_at',
    ];

    public function testDeclaresExactVersionedTimeEntryProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_time_entries');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            [],
            ['id'],
            ['source_hash'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'supersedes_id',
                    'source_reference',
                    'created_by',
                    'approved_by',
                    'approved_at',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['approved_by'],
                        'users',
                        ['id'],
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
                        ['supplier_id', 'supersedes_id'],
                        'payroll_time_entries',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            CompanyBackupColumnCodec::BinaryHex,
            $projection->columnCodecs['source_hash'] ?? null,
        );
        self::assertSame(
            ['supplier_id', 'series_key', 'revision_no'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'approved_by->users:id',
                'created_by->users:id',
                'supplier_id,employment_id->payroll_employments:supplier_id,id',
                'supplier_id,supersedes_id->payroll_time_entries:supplier_id,id',
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
            ['supersedes_id'],
            $projection->references->references[3]->nullableColumns,
        );
    }
}
