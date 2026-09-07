<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupHashReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceOccurrence;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceKey;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson as PayrollCanonicalJson;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollStatutoryAccumulatorEntriesProjectionTest
    extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'tax_year',
        'period_start',
        'revision_id',
        'calculation_kind',
        'values_json',
        'source_result_hash',
        'replaces_entry_id',
        'predecessor_scope_id',
        'record_hash',
        'created_by',
        'created_at',
    ];

    public function testDeclaresExactAppendOnlyEntryProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_statutory_accumulator_entries',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $referenceSchema = new CompanyBackupTableReferenceSchema(
            ['replaces_entry_id', 'created_by'],
            [
                new CompanyBackupForeignKey(
                    ['supplier_id', 'revision_id', 'employee_id'],
                    'payroll_run_persons',
                    ['supplier_id', 'revision_id', 'employee_id'],
                ),
                new CompanyBackupForeignKey(
                    [
                        'supplier_id',
                        'employee_id',
                        'tax_year',
                        'period_start',
                        'calculation_kind',
                        'replaces_entry_id',
                    ],
                    'payroll_statutory_accumulator_entries',
                    [
                        'supplier_id',
                        'employee_id',
                        'tax_year',
                        'period_start',
                        'calculation_kind',
                        'id',
                    ],
                ),
                new CompanyBackupForeignKey(['created_by'], 'users', ['id']),
            ],
        );

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            ['predecessor_scope_id'],
            ['id'],
        );
        $projection->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema($referenceSchema);
        $projection->hashReferences->assertRuntimeSchema($referenceSchema);

        self::assertSame(
            [[
                'supplier_id',
                'employee_id',
                'tax_year',
                'period_start',
                'calculation_kind',
                'id',
            ]],
            $definition->details['reference_keys'] ?? null,
        );
        self::assertSame(
            [
                'source_result_hash->payroll_statutory_person_results:'
                    . 'result_snapshot_hash!',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->hashReferences->references,
            ),
        );
        self::assertSame(
            ['record_hash<-sha256_canonical_projection:{'
                . 'calculation_kind=column:calculation_kind,'
                . 'employee_id=column:employee_id,'
                . 'period_start=column:period_start,'
                . 'replaces_entry_id=column:replaces_entry_id,'
                . 'revision_id=column:revision_id,'
                . 'schema_version=literal:'
                    . '"payroll-statutory-accumulator-entry.v1",'
                . 'source_result_hash=column:source_result_hash,'
                . 'supplier_id=column:supplier_id,'
                . 'values=json_column:values_json,'
                . 'year=column:tax_year}!'],
            array_map(
                static fn ($hash): string => $hash->signature(),
                $projection->derivedHashes->hashes,
            ),
        );
    }

    public function testRemapsEntryChainSourceHashAndRecordSeal(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition(
            'table:payroll_statutory_accumulator_entries',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $values = ['assessment_base_minor_units' => 420_000];
        $sourceResultHash = str_repeat('a', 64);
        $mappedResultHash = str_repeat('b', 64);
        $row = [
            'id' => 13,
            'supplier_id' => 7,
            'employee_id' => 17,
            'tax_year' => 2026,
            'period_start' => '2026-08-01',
            'revision_id' => 31,
            'calculation_kind' => 'social_insurance',
            'values_json' => PayrollCanonicalJson::encode($values),
            'source_result_hash' => $sourceResultHash,
            'replaces_entry_id' => 11,
            'record_hash' => hash('sha256', PayrollCanonicalJson::encode([
                'schema_version' => 'payroll-statutory-accumulator-entry.v1',
                'supplier_id' => 7,
                'employee_id' => 17,
                'year' => 2026,
                'period_start' => '2026-08-01',
                'revision_id' => 31,
                'calculation_kind' => 'social_insurance',
                'values' => $values,
                'source_result_hash' => $sourceResultHash,
                'replaces_entry_id' => 11,
            ])),
            'created_by' => 3,
            'created_at' => '2026-09-07 11:00:00',
        ];

        $projection->assertExportRow($row);
        $referenceMapper = static function (
            CompanyBackupReferenceOccurrence $occurrence,
        ): CompanyBackupSourceKey {
            return match ($occurrence->targetRegistryKey) {
                'table:payroll_run_persons' => CompanyBackupSourceKey::fromValues(
                    $occurrence->targetRegistryKey,
                    [
                        'supplier_id' => 107,
                        'revision_id' => 131,
                        'employee_id' => 117,
                    ],
                ),
                'table:payroll_statutory_accumulator_entries' =>
                    CompanyBackupSourceKey::fromValues(
                        $occurrence->targetRegistryKey,
                        [
                            'supplier_id' => 107,
                            'employee_id' => 117,
                            'tax_year' => 2026,
                            'period_start' => '2026-08-01',
                            'calculation_kind' => 'social_insurance',
                            'id' => 211,
                        ],
                    ),
                'table:users' => CompanyBackupSourceKey::fromValues(
                    $occurrence->targetRegistryKey,
                    ['id' => 303],
                ),
                default => throw new \LogicException('Nečekaná reference.'),
            };
        };
        try {
            $projection->remapReferences($row, $referenceMapper);
            self::fail('Přímá hashová reference musí vyžadovat vlastní mapu.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_hash_reference_mapper_missing', $e->errorCode);
            self::assertSame('source_result_hash', $e->column);
        }

        $restored = $projection->remapReferences(
            $row,
            $referenceMapper,
            hashReferenceMapper: static function (
                CompanyBackupHashReference $reference,
                string $hash,
            ) use ($sourceResultHash, $mappedResultHash): string {
                self::assertSame(
                    'table:payroll_statutory_person_results',
                    $reference->target,
                );
                self::assertSame($sourceResultHash, $hash);
                return $mappedResultHash;
            },
        );

        self::assertSame(107, $restored['supplier_id']);
        self::assertSame(117, $restored['employee_id']);
        self::assertSame(131, $restored['revision_id']);
        self::assertSame(211, $restored['replaces_entry_id']);
        self::assertSame(303, $restored['created_by']);
        self::assertSame($mappedResultHash, $restored['source_result_hash']);
        self::assertSame(
            hash('sha256', PayrollCanonicalJson::encode([
                'schema_version' => 'payroll-statutory-accumulator-entry.v1',
                'supplier_id' => 107,
                'employee_id' => 117,
                'year' => 2026,
                'period_start' => '2026-08-01',
                'revision_id' => 131,
                'calculation_kind' => 'social_insurance',
                'values' => $values,
                'source_result_hash' => $mappedResultHash,
                'replaces_entry_id' => 211,
            ])),
            $restored['record_hash'],
        );
    }
}
