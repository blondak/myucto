<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollBusinessTripItemsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'trip_id',
        'item_kind',
        'spent_on',
        'description',
        'amount_minor',
        'is_documented',
        'document_reference',
        'vehicle_kind',
        'distance_m',
        'consumption_ml_per_100km',
        'fuel_kind',
        'documented_fuel_price_minor',
        'sort_order',
        'created_at',
    ];

    public function testDeclaresExactBusinessTripItemProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_business_trip_items');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'amount_minor',
                    'document_reference',
                    'vehicle_kind',
                    'distance_m',
                    'consumption_ml_per_100km',
                    'fuel_kind',
                    'documented_fuel_price_minor',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'trip_id'],
                        'payroll_business_trips',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertArrayNotHasKey('natural_key', $definition->details);
        self::assertSame(
            [
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
    }
}
