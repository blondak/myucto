<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupColumnCodec;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollBusinessTripsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'employment_id',
        'country_code',
        'timezone_name',
        'departure_at_utc',
        'arrival_at_utc',
        'origin_place',
        'destination_place',
        'purpose',
        'transport_mode',
        'meal_rate_band_1_minor',
        'meal_rate_band_2_minor',
        'meal_rate_band_3_minor',
        'advance_minor',
        'settlement_period_start',
        'status',
        'entitlement_total_minor',
        'exempt_total_minor',
        'taxable_total_minor',
        'ruleset_id',
        'calculation_json',
        'calculation_hash',
        'row_version',
        'created_by',
        'approved_by',
        'approved_at',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactBusinessTripProjectionAndCalculationSeal(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_business_trips');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            [],
            ['id'],
            ['calculation_hash'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'meal_rate_band_1_minor',
                    'meal_rate_band_2_minor',
                    'meal_rate_band_3_minor',
                    'entitlement_total_minor',
                    'exempt_total_minor',
                    'taxable_total_minor',
                    'ruleset_id',
                    'calculation_json',
                    'calculation_hash',
                    'created_by',
                    'approved_by',
                    'approved_at',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'employee_id'],
                        'payroll_employees',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'employment_id'],
                        'payroll_employments',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['created_by'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['approved_by'],
                        'users',
                        ['id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertArrayNotHasKey('natural_key', $definition->details);
        self::assertSame(
            CompanyBackupColumnCodec::BinaryHex,
            $projection->columnCodecs['calculation_hash'] ?? null,
        );
        self::assertSame(
            ['ruleset_id'],
            $projection->preservedIdentifiers->columns,
        );
        self::assertSame(
            ['calculation_hash<-sha256_canonical_json:calculation_json?'],
            array_map(
                static fn ($hash): string => $hash->signature(),
                $projection->derivedHashes->hashes,
            ),
        );
        self::assertSame(
            [
                'approved_by->users:id',
                'created_by->users:id',
                'supplier_id,employee_id->payroll_employees:supplier_id,id',
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

        $calculation = CanonicalJson::encode([
            'entitlement_total_minor' => 23_100,
            'ruleset_ids' => ['synthetic-travel-v1'],
            'status' => 'supported',
        ]);
        $row = [
            'calculation_json' => $calculation,
            'calculation_hash' => hash('sha256', $calculation),
        ];
        $projection->assertExportRow($row);
        self::assertSame(
            $row,
            $projection->remapEmbeddedReferences(
                $row,
                static fn (): never => throw new \LogicException(
                    'Výpočet pracovní cesty nesmí obsahovat instanční ID.',
                ),
            ),
        );
    }
}
