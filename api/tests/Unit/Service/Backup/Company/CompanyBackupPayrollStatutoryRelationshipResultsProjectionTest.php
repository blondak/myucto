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

final class CompanyBackupPayrollStatutoryRelationshipResultsProjectionTest
    extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'statutory_result_id',
        'person_result_id',
        'revision_id',
        'calculation_kind',
        'employee_id',
        'employment_id',
        'result_status',
        'input_snapshot_json',
        'input_snapshot_hash',
        'result_snapshot_json',
        'result_snapshot_hash',
        'created_at',
    ];

    public function testDeclaresExactImmutableRelationshipProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_statutory_relationship_results',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [],
                [
                    new CompanyBackupForeignKey(
                        [
                            'supplier_id',
                            'person_result_id',
                            'statutory_result_id',
                            'revision_id',
                            'calculation_kind',
                            'employee_id',
                        ],
                        'payroll_statutory_person_results',
                        [
                            'supplier_id',
                            'id',
                            'statutory_result_id',
                            'revision_id',
                            'calculation_kind',
                            'employee_id',
                        ],
                    ),
                    new CompanyBackupForeignKey(
                        [
                            'supplier_id',
                            'revision_id',
                            'employee_id',
                            'employment_id',
                        ],
                        'payroll_run_employments',
                        [
                            'supplier_id',
                            'revision_id',
                            'employee_id',
                            'employment_id',
                        ],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            [
                'supplier_id,person_result_id,statutory_result_id,'
                    . 'revision_id,calculation_kind,employee_id'
                    . '->payroll_statutory_person_results:supplier_id,id,'
                    . 'statutory_result_id,revision_id,calculation_kind,'
                    . 'employee_id',
                'supplier_id,revision_id,employee_id,employment_id'
                    . '->payroll_run_employments:supplier_id,revision_id,'
                    . 'employee_id,employment_id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            [
                'input_snapshot_hash<-sha256_canonical_json:'
                    . 'input_snapshot_json!',
                'result_snapshot_hash<-sha256_canonical_json:'
                    . 'result_snapshot_json!',
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
        self::assertCount(31, $embeddedSignatures);
        foreach ([
            'input_snapshot_json:employment.id->payroll_employments:id',
            'input_snapshot_json:inputs.*.component.component_id'
                . '->payroll_component_definitions:id',
            'input_snapshot_json:inputs.*.id->payroll_inputs:id',
            'result_snapshot_json:excluded_assessment_base_components.*'
                . '->payroll_inputs:id@input.~.',
            'result_snapshot_json:participation.relationship_id'
                . '->payroll_employments:id@employment:',
            'result_snapshot_json:person_reference'
                . '->payroll_employees:id@employee:',
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

    public function testRemapsFrozenRelationshipAndRefreshesBothSeals(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition(
            'table:payroll_statutory_relationship_results',
        );
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
            'excluded_assessment_base_components' => ['input.24.excluded'],
            'excluded_participation_components' => ['input.25.excluded'],
            'included_assessment_base_components' => ['input.23.base'],
            'included_participation_components' => ['input.23.base'],
            'participation' => ['relationship_id' => 'employment:19'],
            'relationship_id' => 'employment:19',
        ]);
        $row = [
            'supplier_id' => 7,
            'statutory_result_id' => 31,
            'person_result_id' => 41,
            'revision_id' => 51,
            'calculation_kind' => 'social_insurance',
            'employee_id' => 17,
            'employment_id' => 19,
            'input_snapshot_json' => $input,
            'input_snapshot_hash' => hash('sha256', $input),
            'result_snapshot_json' => $result,
            'result_snapshot_hash' => hash('sha256', $result),
        ];

        $restored = $projection->remapReferences(
            $row,
            static fn (
                CompanyBackupReferenceOccurrence $occurrence,
            ): CompanyBackupSourceKey => match ($occurrence->targetRegistryKey) {
                'table:payroll_statutory_person_results' =>
                    CompanyBackupSourceKey::fromValues(
                        $occurrence->targetRegistryKey,
                        [
                            'supplier_id' => 107,
                            'id' => 141,
                            'statutory_result_id' => 131,
                            'revision_id' => 151,
                            'calculation_kind' => 'social_insurance',
                            'employee_id' => 117,
                        ],
                    ),
                'table:payroll_run_employments' =>
                    CompanyBackupSourceKey::fromValues(
                        $occurrence->targetRegistryKey,
                        [
                            'supplier_id' => 107,
                            'revision_id' => 151,
                            'employee_id' => 117,
                            'employment_id' => 219,
                        ],
                    ),
                default => CompanyBackupSourceKey::fromValues(
                    $occurrence->targetRegistryKey,
                    ['id' => ((int) array_values($occurrence->sourceKey)[0])
                        + match ($occurrence->targetRegistryKey) {
                            'table:payroll_employees' => 100,
                            'table:payroll_employments' => 200,
                            'table:payroll_inputs' => 300,
                            'table:payroll_component_definitions' => 400,
                            default => throw new \LogicException(
                                'Test zachytil neočekávanou referenci.',
                            ),
                        },
                    ],
                ),
            },
        );
        $restoredInput = json_decode(
            (string) $restored['input_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $restoredResult = json_decode(
            (string) $restored['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(107, $restored['supplier_id']);
        self::assertSame(131, $restored['statutory_result_id']);
        self::assertSame(141, $restored['person_result_id']);
        self::assertSame(151, $restored['revision_id']);
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
        self::assertSame(
            'input.323.base',
            $restoredResult['included_assessment_base_components'][0],
        );
        self::assertSame(
            'input.324.excluded',
            $restoredResult['excluded_assessment_base_components'][0],
        );
        self::assertSame(
            'input.325.excluded',
            $restoredResult['excluded_participation_components'][0],
        );
        self::assertSame(
            'employment:219',
            $restoredResult['participation']['relationship_id'],
        );
        self::assertSame(
            hash('sha256', (string) $restored['input_snapshot_json']),
            $restored['input_snapshot_hash'],
        );
        self::assertSame(
            hash('sha256', (string) $restored['result_snapshot_json']),
            $restored['result_snapshot_hash'],
        );

        $blockedResult = PayrollCanonicalJson::encode([
            'blocked_by_person' => true,
            'issues' => ['synthetic_issue'],
            'person_reference' => 'employee:17',
            'status' => 'manual_review',
        ]);
        $blocked = $projection->remapEmbeddedReferences(
            [
                'input_snapshot_json' => $input,
                'input_snapshot_hash' => hash('sha256', $input),
                'result_snapshot_json' => $blockedResult,
                'result_snapshot_hash' => hash('sha256', $blockedResult),
            ],
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $sourceValue,
            ): int => (int) $sourceValue + match ($reference->target) {
                'table:payroll_employees' => 100,
                'table:payroll_employments' => 200,
                'table:payroll_inputs' => 300,
                'table:payroll_component_definitions' => 400,
                default => throw new \LogicException(
                    'Test zachytil neočekávanou referenci.',
                ),
            },
        );
        $blockedPayload = json_decode(
            (string) $blocked['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('employee:117', $blockedPayload['person_reference']);
    }
}
