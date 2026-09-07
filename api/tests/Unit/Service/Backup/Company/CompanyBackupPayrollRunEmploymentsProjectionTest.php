<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceOccurrence;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceKey;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson as PayrollCanonicalJson;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollRunEmploymentsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'revision_id',
        'employee_id',
        'employment_id',
        'input_json',
        'input_hash',
        'result_json',
        'result_hash',
        'status',
    ];

    public function testDeclaresExactSealedRunEmploymentProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_run_employments');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['result_json', 'result_hash'],
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
                        ['supplier_id', 'employment_id', 'employee_id'],
                        'payroll_employments',
                        ['supplier_id', 'id', 'employee_id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'revision_id'],
                        'payroll_run_revisions',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            [[
                'supplier_id',
                'revision_id',
                'employee_id',
                'employment_id',
            ]],
            $definition->details['reference_keys'] ?? null,
        );
        self::assertSame(
            [
                'supplier_id,employee_id->payroll_employees:supplier_id,id',
                'supplier_id,employment_id,employee_id'
                    . '->payroll_employments:supplier_id,id,employee_id',
                'supplier_id,employment_id->payroll_employments:supplier_id,id',
                'supplier_id,revision_id->payroll_run_revisions:supplier_id,id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            [
                'input_hash<-sha256_canonical_json:input_json!',
                'result_hash<-sha256_canonical_json:result_json?',
            ],
            array_map(
                static fn ($hash): string => $hash->signature(),
                $projection->derivedHashes->hashes,
            ),
        );

        $embeddedSignatures = array_map(
            static fn ($reference): string => $reference->signature(),
            $projection->embeddedReferences->references,
        );
        self::assertCount(25, $embeddedSignatures);
        foreach ([
            'input_json:employment.employee_id->payroll_employees:id',
            'input_json:employment.id->payroll_employments:id',
            'input_json:inputs.*.id->payroll_inputs:id',
            'result_json:employment_id->payroll_employments:id',
            'result_json:inputs.*.input_id->payroll_inputs:id',
        ] as $signature) {
            self::assertContains($signature, $embeddedSignatures);
        }
        self::assertSame(
            [
                'input_component_snapshot',
                'input_jmhz_source_snapshot',
                'input_jmhz_summary',
            ],
            array_map(
                static fn ($hash): string => $hash->name,
                $projection->embeddedHashes->hashes,
            ),
        );
    }

    public function testRemapsFrozenInputAndCalculatedResultBeforeResealing(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()
            ->definition('table:payroll_run_employments');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $component = ['code' => 'BASE', 'component_id' => 29];
        $input = PayrollCanonicalJson::encode([
            'employment' => [
                'employee_id' => 17,
                'id' => 19,
                'office_id' => null,
            ],
            'inputs' => [[
                'component' => $component,
                'component_snapshot_hash' => hash(
                    'sha256',
                    PayrollCanonicalJson::encode($component),
                ),
                'id' => 23,
            ]],
        ]);
        $result = PayrollCanonicalJson::encode([
            'employment_id' => 19,
            'inputs' => [['input_id' => 23]],
            'totals' => ['source_amount_minor' => 42_000],
        ]);
        $row = [
            'supplier_id' => 7,
            'revision_id' => 51,
            'employee_id' => 17,
            'employment_id' => 19,
            'input_json' => $input,
            'input_hash' => hash('sha256', $input),
            'result_json' => $result,
            'result_hash' => hash('sha256', $result),
        ];

        $restored = $projection->remapReferences(
            $row,
            static function (
                CompanyBackupReferenceOccurrence $occurrence,
            ): CompanyBackupSourceKey {
                $sourceValue = (int) (
                    $occurrence->sourceKey['id']
                    ?? array_values($occurrence->sourceKey)[0]
                );
                $offset = match ($occurrence->targetRegistryKey) {
                    'table:payroll_employees' => 100,
                    'table:payroll_employments' => 200,
                    'table:payroll_inputs' => 300,
                    'table:payroll_component_definitions' => 400,
                    'table:payroll_run_revisions' => 500,
                    default => throw new \LogicException(
                        'Test zachytil neočekávanou referenci.',
                    ),
                };
                if (isset($occurrence->sourceKey['employee_id'])) {
                    $values = [
                        'supplier_id' => 107,
                        'id' => $sourceValue + $offset,
                        'employee_id' => 117,
                    ];
                } elseif (count($occurrence->sourceKey) === 1) {
                    $values = ['id' => $sourceValue + $offset];
                } else {
                    $values = [
                        'supplier_id' => 107,
                        'id' => $sourceValue + $offset,
                    ];
                }
                return CompanyBackupSourceKey::fromValues(
                    $occurrence->targetRegistryKey,
                    $values,
                );
            },
        );
        $restoredInput = json_decode(
            (string) $restored['input_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $restoredResult = json_decode(
            (string) $restored['result_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(107, $restored['supplier_id']);
        self::assertSame(551, $restored['revision_id']);
        self::assertSame(117, $restored['employee_id']);
        self::assertSame(219, $restored['employment_id']);
        self::assertSame(117, $restoredInput['employment']['employee_id']);
        self::assertSame(219, $restoredInput['employment']['id']);
        self::assertSame(323, $restoredInput['inputs'][0]['id']);
        self::assertSame(
            429,
            $restoredInput['inputs'][0]['component']['component_id'],
        );
        self::assertSame(
            hash(
                'sha256',
                PayrollCanonicalJson::encode(
                    $restoredInput['inputs'][0]['component'],
                ),
            ),
            $restoredInput['inputs'][0]['component_snapshot_hash'],
        );
        self::assertSame(219, $restoredResult['employment_id']);
        self::assertSame(323, $restoredResult['inputs'][0]['input_id']);
        self::assertSame(
            hash('sha256', (string) $restored['input_json']),
            $restored['input_hash'],
        );
        self::assertSame(
            hash('sha256', (string) $restored['result_json']),
            $restored['result_hash'],
        );
    }

    public function testPreservesPendingNullableResultPair(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()
            ->definition('table:payroll_run_employments');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $input = PayrollCanonicalJson::encode([
            'employment' => [
                'employee_id' => 17,
                'id' => 19,
                'office_id' => null,
            ],
            'inputs' => [],
        ]);

        $row = [
            'input_json' => $input,
            'input_hash' => hash('sha256', $input),
            'result_json' => null,
            'result_hash' => null,
        ];
        $projection->assertExportRow($row);
        $restored = $projection->remapEmbeddedReferences(
            $row,
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $sourceValue,
            ): int|string => $sourceValue,
        );

        self::assertNull($restored['result_json']);
        self::assertNull($restored['result_hash']);
    }
}
