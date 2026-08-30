<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollSicknessCompensationSegmentsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'sickness_event_id',
        'shift_id',
        'local_date',
        'planned_minutes',
        'eligible_minutes',
        'hourly_average_minor',
        'reduced_hourly_minor',
        'compensation_minor',
        'trace',
    ];

    public function testDeclaresAndRemapsExactSicknessSegmentProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_sickness_compensation_segments',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->embeddedReferences->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['shift_id'],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'sickness_event_id'],
                        'payroll_sickness_events',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertArrayNotHasKey('natural_key', $definition->details);
        self::assertSame(
            [
                'supplier_id,shift_id->payroll_shifts:supplier_id,id',
                'supplier_id,sickness_event_id'
                    . '->payroll_sickness_events:supplier_id,id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional,
            $projection->references->references[0]->constraint,
        );
        self::assertSame(
            ['shift_id'],
            $projection->references->references[0]->nullableColumns,
        );
        self::assertSame(
            ['trace:shift_id->payroll_shifts:id'],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->embeddedReferences->references,
            ),
        );
        self::assertTrue($projection->embeddedReferences->references[0]->nullable);

        $trace = CanonicalJson::encode([
            'eligible_minutes' => 240,
            'shift_id' => 17,
        ]);
        $restored = $projection->remapEmbeddedReferences(
            ['trace' => $trace],
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ): int => $reference->target === 'table:payroll_shifts'
                ? (int) $value + 100
                : throw new \LogicException(
                    'Test zachytil neočekávanou referenci.',
                ),
        );
        self::assertSame(
            ['eligible_minutes' => 240, 'shift_id' => 117],
            json_decode(
                (string) $restored['trace'],
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }
}
