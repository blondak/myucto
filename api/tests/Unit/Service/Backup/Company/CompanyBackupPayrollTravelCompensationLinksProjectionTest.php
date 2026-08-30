<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupEncodedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollTravelCompensationLinksProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'input_id',
        'trip_id',
        'source_system',
        'source_reference',
        'classification_status',
        'created_at',
    ];

    public function testDeclaresExactTravelCompensationLinkProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_travel_compensation_links',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->encodedReferences->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['trip_id'],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'input_id'],
                        'payroll_inputs',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'trip_id'],
                        'payroll_business_trips',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'source_system', 'source_reference'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'supplier_id,input_id->payroll_inputs:supplier_id,id',
                'supplier_id,trip_id->payroll_business_trips:supplier_id,id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Required,
            $projection->references->references[0]->constraint,
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Required,
            $projection->references->references[1]->constraint,
        );
        self::assertSame(
            ['trip_id'],
            $projection->references->references[1]->nullableColumns,
        );
        self::assertSame(
            [
                'source_reference->payroll_business_trips:id'
                    . '?source_system=payroll_business_trip@trip:~:',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->encodedReferences->references,
            ),
        );

        $restored = $projection->remapPayloadReferences(
            [
                'source_system' => 'payroll_business_trip',
                'source_reference' => 'trip:17:exempt',
            ],
            static fn (
                CompanyBackupEncodedReference|CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ): int => $reference->target === 'table:payroll_business_trips'
                ? (int) $value + 100
                : throw new \LogicException(
                    'Test zachytil neočekávanou referenci.',
                ),
        );
        self::assertSame('trip:117:exempt', $restored['source_reference']);
    }
}
