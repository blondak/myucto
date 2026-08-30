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

final class CompanyBackupPayrollAverageEarningSnapshotsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employment_id',
        'applicable_year',
        'applicable_quarter',
        'revision_no',
        'source_kind',
        'decisive_from',
        'decisive_to',
        'gross_earnings_minor',
        'longer_period_allocated_minor',
        'worked_minutes',
        'worked_days',
        'average_hourly_minor',
        'rationale',
        'support_status',
        'status',
        'ruleset_id',
        'ruleset_hash',
        'input_hash',
        'input_trace',
        'row_version',
        'created_by',
        'approved_by',
        'approved_at',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactAverageSnapshotProjectionAndReferences(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_average_earning_snapshots',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            [],
            ['id'],
            ['input_hash'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['rationale', 'created_by', 'approved_by', 'approved_at'],
                [
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
            [
                'supplier_id',
                'employment_id',
                'applicable_year',
                'applicable_quarter',
                'revision_no',
            ],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            CompanyBackupColumnCodec::BinaryHex,
            $projection->columnCodecs['input_hash'] ?? null,
        );
        self::assertSame(
            ['ruleset_id'],
            $projection->preservedIdentifiers->columns,
        );
        self::assertSame(
            [
                'approved_by->users:id',
                'created_by->users:id',
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
    }
}
