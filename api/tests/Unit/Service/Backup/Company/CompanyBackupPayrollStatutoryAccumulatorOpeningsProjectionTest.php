<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupColumnCodec;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceOccurrence;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceKey;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollStatutoryAccumulatorOpeningsProjectionTest
    extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'tax_year',
        'calculation_kind',
        'values_json',
        'source_reference',
        'evidence_json',
        'replaces_opening_id',
        'predecessor_scope_id',
        'idempotency_key_hash',
        'record_hash',
        'created_by',
        'created_at',
    ];

    public function testDeclaresExactAppendOnlyOpeningProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_statutory_accumulator_openings',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            ['predecessor_scope_id'],
            ['id'],
            ['idempotency_key_hash'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['replaces_opening_id', 'created_by'],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'employee_id'],
                        'payroll_employees',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        [
                            'supplier_id',
                            'employee_id',
                            'tax_year',
                            'calculation_kind',
                            'replaces_opening_id',
                        ],
                        'payroll_statutory_accumulator_openings',
                        [
                            'supplier_id',
                            'employee_id',
                            'tax_year',
                            'calculation_kind',
                            'id',
                        ],
                    ),
                    new CompanyBackupForeignKey(
                        ['created_by'],
                        'users',
                        ['id'],
                    ),
                ],
            ),
        );

        self::assertSame(
            CompanyBackupColumnCodec::BinaryHex,
            $projection->columnCodecs['idempotency_key_hash'] ?? null,
        );
        self::assertSame(
            [[
                'supplier_id',
                'employee_id',
                'tax_year',
                'calculation_kind',
                'id',
            ]],
            $definition->details['reference_keys'] ?? null,
        );
        self::assertSame(
            ['predecessor_scope_id'],
            $projection->generatedColumns,
        );
        self::assertSame(
            ['record_hash<-sha256_canonical_projection:{'
                . 'calculation_kind=column:calculation_kind,'
                . 'employee_id=column:employee_id,'
                . 'evidence=json_column:evidence_json,'
                . 'replaces_opening_id=column:replaces_opening_id,'
                . 'schema_version=literal:"payroll-statutory-opening.v1",'
                . 'source_reference=column:source_reference,'
                . 'supplier_id=column:supplier_id,'
                . 'values=json_column:values_json,'
                . 'year=column:tax_year}!'],
            array_map(
                static fn ($hash): string => $hash->signature(),
                $projection->derivedHashes->hashes,
            ),
        );
    }

    public function testRemapsOpeningChainAndRefreshesRecordHash(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition(
            'table:payroll_statutory_accumulator_openings',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $values = ['annual_base_minor' => 420_000];
        $evidence = ['confirmed_by' => 'synthetic-test'];
        $row = [
            'id' => 13,
            'supplier_id' => 7,
            'employee_id' => 17,
            'tax_year' => 2026,
            'calculation_kind' => 'income_tax',
            'values_json' => CanonicalJson::encode($values),
            'source_reference' => 'synthetic-opening',
            'evidence_json' => CanonicalJson::encode($evidence),
            'replaces_opening_id' => 11,
            'idempotency_key_hash' => str_repeat('42', 32),
            'record_hash' => CanonicalJson::sha256([
                'schema_version' => 'payroll-statutory-opening.v1',
                'supplier_id' => 7,
                'employee_id' => 17,
                'year' => 2026,
                'calculation_kind' => 'income_tax',
                'values' => $values,
                'source_reference' => 'synthetic-opening',
                'evidence' => $evidence,
                'replaces_opening_id' => 11,
            ]),
            'created_by' => 3,
            'created_at' => '2026-09-07 10:00:00',
        ];

        $projection->assertExportRow($row);
        $restored = $projection->remapReferences(
            $row,
            static function (
                CompanyBackupReferenceOccurrence $occurrence,
            ): CompanyBackupSourceKey {
                return match ($occurrence->targetRegistryKey) {
                    'table:payroll_employees' => CompanyBackupSourceKey::fromValues(
                        $occurrence->targetRegistryKey,
                        ['supplier_id' => 107, 'id' => 117],
                    ),
                    'table:payroll_statutory_accumulator_openings' =>
                        CompanyBackupSourceKey::fromValues(
                            $occurrence->targetRegistryKey,
                            [
                                'supplier_id' => 107,
                                'employee_id' => 117,
                                'tax_year' => 2026,
                                'calculation_kind' => 'income_tax',
                                'id' => 211,
                            ],
                        ),
                    'table:users' => CompanyBackupSourceKey::fromValues(
                        $occurrence->targetRegistryKey,
                        ['id' => 303],
                    ),
                    default => throw new \LogicException('Nečekaná reference.'),
                };
            },
        );

        self::assertSame(107, $restored['supplier_id']);
        self::assertSame(117, $restored['employee_id']);
        self::assertSame(211, $restored['replaces_opening_id']);
        self::assertSame(303, $restored['created_by']);
        self::assertSame(
            CanonicalJson::sha256([
                'schema_version' => 'payroll-statutory-opening.v1',
                'supplier_id' => 107,
                'employee_id' => 117,
                'year' => 2026,
                'calculation_kind' => 'income_tax',
                'values' => $values,
                'source_reference' => 'synthetic-opening',
                'evidence' => $evidence,
                'replaces_opening_id' => 211,
            ]),
            $restored['record_hash'],
        );
    }
}
