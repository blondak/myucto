<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupColumnCodec;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedHashReference;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollRunRevisionsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'run_id',
        'revision_no',
        'previous_revision_id',
        'revision_kind',
        'status',
        'schema_version',
        'ruleset_manifest_hash',
        'input_snapshot_json',
        'input_snapshot_hash',
        'result_snapshot_json',
        'result_snapshot_hash',
        'idempotency_key_hash',
        'calculated_by',
        'reviewed_by',
        'approved_by',
        'calculated_at',
        'reviewed_at',
        'approved_at',
        'created_at',
    ];

    public function testDeclaresExactColumnsReferencesCodecAndSnapshotTargets(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_run_revisions');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            [],
            ['id'],
            ['idempotency_key_hash'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->embeddedReferences->assertRegistryTargets($registry);
        $projection->embeddedHashReferences->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['approved_by', 'calculated_by', 'previous_revision_id', 'reviewed_by'],
                [
                    new CompanyBackupForeignKey(
                        ['approved_by'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['calculated_by'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['reviewed_by'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'previous_revision_id'],
                        'payroll_run_revisions',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'run_id'],
                        'payroll_runs',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            CompanyBackupColumnCodec::BinaryHex,
            $projection->columnCodecs['idempotency_key_hash'] ?? null,
        );
        self::assertSame(
            [['supplier_id', 'id', 'run_id']],
            $definition->details['reference_keys'] ?? null,
        );
        self::assertSame(
            [
                'approved_by->users:id',
                'calculated_by->users:id',
                'reviewed_by->users:id',
                'supplier_id,previous_revision_id'
                    . '->payroll_run_revisions:supplier_id,id',
                'supplier_id,run_id->payroll_runs:supplier_id,id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            [
                'input_snapshot_hash<-sha256_canonical_json:input_snapshot_json!',
                'result_snapshot_hash<-sha256_canonical_json:result_snapshot_json?'
                    . '[source_snapshot_hash<-input_snapshot_hash]',
            ],
            array_map(
                static fn ($hash): string => $hash->signature(),
                $projection->derivedHashes->hashes,
            ),
        );

        $signatures = array_map(
            static fn ($reference): string => $reference->signature(),
            $projection->embeddedReferences->references,
        );
        self::assertCount(108, $signatures);
        foreach (
            [
                'input_snapshot_json:employer_policy.id'
                    . '->payroll_employer_policies:id',
                'input_snapshot_json:office_id->payroll_offices:id',
                'input_snapshot_json:supplier_id->supplier:id',
                'result_snapshot_json:people.*.enforcement.input.income.trace.*.id'
                    . '->payroll_employees:id@revision-person-~-',
                'result_snapshot_json:people.*.statutory.net_pay.deductions.*.'
                    . 'deduction_reference'
                    . '->payroll_deduction_agreements:id@agreement:',
                'result_snapshot_json:people.*.statutory.social_insurance.'
                    . 'relationships.*.included_assessment_base_components.*'
                    . '->payroll_inputs:id@input.~.',
                'result_snapshot_json:statutory.people.*.income_tax.'
                    . 'relationships.*.relationship_reference'
                    . '->payroll_employments:id@employment:',
                'result_snapshot_json:statutory.people.*.social_insurance.'
                    . 'person_reference->payroll_employees:id@employee:',
                'result_snapshot_json:statutory.result_set_ids.*'
                    . '->payroll_statutory_results:id',
                'result_snapshot_json:statutory.risky_savings.*.source_evidence_id'
                    . '->payroll_risky_savings_evidence:id',
            ] as $signature
        ) {
            self::assertContains($signature, $signatures);
        }
    }

    public function testRemapsCompleteRunSnapshotAndRefreshesHashChain(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_run_revisions');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $input = CanonicalJson::encode([
            'employer_policy' => ['id' => 9, 'row_version' => 1],
            'office_id' => 8,
            'people' => [[
                'employee' => ['id' => 17],
                'employments' => [],
                'statutory_accumulators' => [
                    'income_tax' => ['state' => null],
                    'social_insurance' => ['state' => null],
                ],
            ]],
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
        ]);
        $person = $this->statutoryPerson();
        $result = CanonicalJson::encode([
            'people' => [[
                'employee_id' => 17,
                'employments' => [[
                    'employment_id' => 19,
                    'inputs' => [['input_id' => 23]],
                ]],
                'enforcement' => ['input' => ['income' => ['trace' => [[
                    'id' => 'revision-person-17-garnishable',
                    'payer_id' => 'supplier-7',
                ]]]]],
                'statutory' => $person,
            ]],
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $input),
            'statutory' => [
                'people' => [$person],
                'result_set_ids' => [
                    'health_insurance' => 31,
                    'income_tax' => 32,
                    'net_pay' => 33,
                    'social_insurance' => 34,
                ],
                'risky_savings' => [[
                    'employment_id' => 19,
                    'institution_account_id' => 41,
                    'source_evidence_id' => 37,
                ]],
            ],
        ]);
        $row = [
            'input_snapshot_json' => $input,
            'input_snapshot_hash' => hash('sha256', $input),
            'result_snapshot_json' => $result,
            'result_snapshot_hash' => hash('sha256', $result),
        ];

        $restored = $projection->remapEmbeddedReferences(
            $row,
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $sourceValue,
            ): int => (int) $sourceValue + match ($reference->target) {
                'table:supplier' => 1_000,
                'table:payroll_offices' => 2_000,
                'table:payroll_employer_policies' => 3_000,
                'table:payroll_employees' => 100,
                'table:payroll_employments' => 200,
                'table:payroll_inputs' => 300,
                'table:payroll_deduction_agreements' => 400,
                'table:payroll_statutory_results' => 500,
                'table:payroll_risky_savings_evidence' => 600,
                'table:payroll_institution_accounts' => 700,
                default => throw new \LogicException(
                    'Test zachytil neočekávanou referenci.',
                ),
            },
            static fn (
                CompanyBackupEmbeddedHashReference $reference,
                string $sourceHash,
            ): string => throw new \LogicException(
                'Prázdný akumulátor nesmí mapovat hash výsledku.',
            ),
        );

        $restoredInput = json_decode(
            (string) $restored['input_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(3_009, $restoredInput['employer_policy']['id']);
        self::assertSame(2_008, $restoredInput['office_id']);
        self::assertSame(1_007, $restoredInput['supplier_id']);
        self::assertSame(117, $restoredInput['people'][0]['employee']['id']);

        $restoredResult = json_decode(
            (string) $restored['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $runPerson = $restoredResult['people'][0];
        self::assertSame(117, $runPerson['employee_id']);
        self::assertSame(219, $runPerson['employments'][0]['employment_id']);
        self::assertSame(323, $runPerson['employments'][0]['inputs'][0]['input_id']);
        self::assertSame(
            'revision-person-117-garnishable',
            $runPerson['enforcement']['input']['income']['trace'][0]['id'],
        );
        self::assertSame(
            'supplier-1007',
            $runPerson['enforcement']['input']['income']['trace'][0]['payer_id'],
        );
        $this->assertRestoredStatutoryPerson($runPerson['statutory']);
        $this->assertRestoredStatutoryPerson(
            $restoredResult['statutory']['people'][0],
        );
        self::assertSame(
            [531, 532, 533, 534],
            array_values($restoredResult['statutory']['result_set_ids']),
        );
        self::assertSame(
            [
                'employment_id' => 219,
                'institution_account_id' => 741,
                'source_evidence_id' => 637,
            ],
            $restoredResult['statutory']['risky_savings'][0],
        );
        self::assertSame(
            hash('sha256', (string) $restored['input_snapshot_json']),
            $restored['input_snapshot_hash'],
        );
        self::assertSame(
            $restored['input_snapshot_hash'],
            $restoredResult['source_snapshot_hash'],
        );
        self::assertSame(
            hash('sha256', (string) $restored['result_snapshot_json']),
            $restored['result_snapshot_hash'],
        );
    }

    /** @return array<string,mixed> */
    private function statutoryPerson(): array
    {
        $insurance = [
            'person_id' => 'employee:17',
            'relationships' => [[
                'included_assessment_base_components' => ['input.23.base'],
                'included_participation_components' => ['input.23.base'],
                'participation' => ['relationship_id' => 'employment:19'],
                'relationship_id' => 'employment:19',
            ]],
        ];
        return [
            'health_insurance' => $insurance,
            'income_tax' => [
                'employee_reference' => 'employee:17',
                'payer_reference' => 'supplier:7',
                'relationships' => [[
                    'relationship_reference' => 'employment:19',
                ]],
            ],
            'net_pay' => [
                'deductions' => [[
                    'deduction_reference' => 'agreement:29',
                ]],
                'person_reference' => 'employee:17',
                'relationships' => [[
                    'relationship_reference' => 'employment:19',
                ]],
            ],
            'person_reference' => 'employee:17',
            'social_insurance' => $insurance,
        ];
    }

    /** @param array<string,mixed> $person */
    private function assertRestoredStatutoryPerson(array $person): void
    {
        self::assertSame('employee:117', $person['person_reference']);
        self::assertSame(
            'employee:117',
            $person['income_tax']['employee_reference'],
        );
        self::assertSame('supplier:1007', $person['income_tax']['payer_reference']);
        self::assertSame(
            'employment:219',
            $person['income_tax']['relationships'][0]['relationship_reference'],
        );
        self::assertSame(
            'agreement:429',
            $person['net_pay']['deductions'][0]['deduction_reference'],
        );
        self::assertSame(
            'input.323.base',
            $person['social_insurance']['relationships'][0]
                ['included_assessment_base_components'][0],
        );
        self::assertSame(
            'employment:219',
            $person['health_insurance']['relationships'][0]
                ['participation']['relationship_id'],
        );
    }
}
