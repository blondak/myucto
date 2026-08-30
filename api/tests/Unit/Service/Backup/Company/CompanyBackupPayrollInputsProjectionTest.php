<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupColumnCodec;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollInputsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'employment_id',
        'component_id',
        'period_start',
        'source_period_start',
        'amount_minor',
        'quantity_milliunits',
        'source_kind',
        'external_id',
        'import_id',
        'recurring_component_id',
        'source_snapshot_json',
        'source_snapshot_hash',
        'status',
        'component_snapshot_json',
        'component_snapshot_hash',
        'row_version',
        'created_by',
        'approved_by',
        'approved_at',
        'created_at',
        'updated_at',
        'external_dedupe_key',
        'benefit_basket',
        'benefit_exempt_minor',
        'benefit_taxable_minor',
    ];

    /** @var list<string> */
    private const DATA_COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'employment_id',
        'component_id',
        'period_start',
        'source_period_start',
        'amount_minor',
        'quantity_milliunits',
        'source_kind',
        'external_id',
        'import_id',
        'recurring_component_id',
        'source_snapshot_json',
        'source_snapshot_hash',
        'status',
        'component_snapshot_json',
        'component_snapshot_hash',
        'row_version',
        'created_by',
        'approved_by',
        'approved_at',
        'created_at',
        'updated_at',
        'benefit_basket',
        'benefit_exempt_minor',
        'benefit_taxable_minor',
    ];

    public function testDeclaresAndResealsCompleteInputProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_inputs');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            ['external_dedupe_key'],
            ['id'],
            ['source_snapshot_hash', 'component_snapshot_hash'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->embeddedReferences->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'source_period_start',
                    'quantity_milliunits',
                    'external_id',
                    'import_id',
                    'recurring_component_id',
                    'source_snapshot_json',
                    'source_snapshot_hash',
                    'component_snapshot_json',
                    'component_snapshot_hash',
                    'created_by',
                    'approved_by',
                    'approved_at',
                    'external_dedupe_key',
                    'benefit_basket',
                    'benefit_exempt_minor',
                    'benefit_taxable_minor',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['approved_by'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'component_id'],
                        'payroll_component_definitions',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['created_by'],
                        'users',
                        ['id'],
                    ),
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
                        ['supplier_id', 'import_id'],
                        'payroll_input_imports',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'recurring_component_id'],
                        'payroll_recurring_components',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::DATA_COLUMNS, $projection->dataColumns);
        self::assertSame(['external_dedupe_key'], $projection->generatedColumns);
        self::assertSame(['external_id'], $projection->preservedIdentifiers->columns);
        self::assertSame(
            CompanyBackupColumnCodec::BinaryHex,
            $projection->columnCodecs['component_snapshot_hash'] ?? null,
        );
        self::assertSame(
            CompanyBackupColumnCodec::BinaryHex,
            $projection->columnCodecs['source_snapshot_hash'] ?? null,
        );
        self::assertSame(
            [
                'approved_by->users:id',
                'created_by->users:id',
                'supplier_id,component_id'
                    . '->payroll_component_definitions:supplier_id,id',
                'supplier_id,employee_id->payroll_employees:supplier_id,id',
                'supplier_id,employment_id->payroll_employments:supplier_id,id',
                'supplier_id,import_id->payroll_input_imports:supplier_id,id',
                'supplier_id,recurring_component_id'
                    . '->payroll_recurring_components:supplier_id,id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            [
                'component_snapshot_json:component_id'
                    . '->payroll_component_definitions:id',
                'source_snapshot_json:average_snapshot_id'
                    . '->payroll_average_earning_snapshots:id',
                'source_snapshot_json:business_trip_id->payroll_business_trips:id',
                'source_snapshot_json:recurring_component_id'
                    . '->payroll_recurring_components:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->embeddedReferences->references,
            ),
        );
        self::assertSame(
            [
                'component_snapshot_hash'
                    . '<-sha256_canonical_json:component_snapshot_json?',
                'source_snapshot_hash'
                    . '<-sha256_canonical_json:source_snapshot_json?',
            ],
            array_map(
                static fn ($hash): string => $hash->signature(),
                $projection->derivedHashes->hashes,
            ),
        );

        $component = CanonicalJson::encode([
            'code' => 'BASE',
            'component_id' => 17,
            'component_row_version' => 1,
        ]);
        $source = CanonicalJson::encode([
            'calculation_kind' => 'fixed_amount',
            'recurring_component_id' => 23,
            'recurring_row_version' => 1,
        ]);
        $restored = $projection->remapEmbeddedReferences(
            [
                'component_snapshot_json' => $component,
                'component_snapshot_hash' => hash('sha256', $component),
                'source_snapshot_json' => $source,
                'source_snapshot_hash' => hash('sha256', $source),
            ],
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ): int => (int) $value + match ($reference->target) {
                'table:payroll_component_definitions' => 100,
                'table:payroll_recurring_components' => 200,
                default => throw new \LogicException(
                    'Test zachytil neočekávanou referenci.',
                ),
            },
        );
        $restoredComponent = json_decode(
            (string) $restored['component_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $restoredSource = json_decode(
            (string) $restored['source_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(117, $restoredComponent['component_id']);
        self::assertSame(223, $restoredSource['recurring_component_id']);
        self::assertSame(
            hash('sha256', (string) $restored['component_snapshot_json']),
            $restored['component_snapshot_hash'],
        );
        self::assertSame(
            hash('sha256', (string) $restored['source_snapshot_json']),
            $restored['source_snapshot_hash'],
        );
    }
}
