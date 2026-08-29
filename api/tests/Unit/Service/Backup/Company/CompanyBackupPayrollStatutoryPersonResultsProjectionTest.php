<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedHashReference;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollStatutoryPersonResultsProjectionTest extends TestCase
{
    public function testDeclaresExactColumnsCompositeReferencesAndHashTarget(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_statutory_person_results',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $columns = [
            'id',
            'supplier_id',
            'statutory_result_id',
            'revision_id',
            'calculation_kind',
            'employee_id',
            'result_status',
            'input_snapshot_json',
            'input_snapshot_hash',
            'result_snapshot_json',
            'result_snapshot_hash',
            'created_at',
        ];

        $projection->assertRuntimeSchema($columns, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->embeddedReferences->assertRegistryTargets($registry);
        $projection->embeddedHashReferences->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'statutory_result_id', 'revision_id', 'calculation_kind'],
                        'payroll_statutory_results',
                        ['supplier_id', 'id', 'revision_id', 'calculation_kind'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'revision_id', 'employee_id'],
                        'payroll_run_persons',
                        ['supplier_id', 'revision_id', 'employee_id'],
                    ),
                ],
            ),
        );

        self::assertSame($columns, $projection->dataColumns);
        self::assertSame(
            [['supplier_id', 'id', 'statutory_result_id', 'revision_id', 'calculation_kind', 'employee_id']],
            $definition->details['reference_keys'] ?? null,
        );
        self::assertSame(
            [
                'supplier_id,revision_id,employee_id'
                    . '->payroll_run_persons:supplier_id,revision_id,employee_id',
                'supplier_id,statutory_result_id,revision_id,calculation_kind'
                    . '->payroll_statutory_results:'
                    . 'supplier_id,id,revision_id,calculation_kind',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        foreach ($projection->references->references as $reference) {
            self::assertSame(
                CompanyBackupReferenceMapping::TenantReferenceKey,
                $reference->mapping,
            );
        }
        self::assertSame(
            [
                'input_snapshot_hash<-sha256_canonical_json:input_snapshot_json!',
                'result_snapshot_hash<-sha256_canonical_json:result_snapshot_json!',
            ],
            array_map(
                static fn ($hash): string => $hash->signature(),
                $projection->derivedHashes->hashes,
            ),
        );
        self::assertSame(
            [
                'input_snapshot_json:statutory_accumulators.income_tax.state.'
                    . 'approved_results.*.source_result_hash'
                    . '->payroll_statutory_person_results:result_snapshot_hash?',
                'input_snapshot_json:statutory_accumulators.social_insurance.state.'
                    . 'approved_results.*.source_result_hash'
                    . '->payroll_statutory_person_results:result_snapshot_hash?',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->embeddedHashReferences->references,
            ),
        );
        $embeddedSignatures = array_map(
            static fn ($reference): string => $reference->signature(),
            $projection->embeddedReferences->references,
        );
        self::assertCount(60, $embeddedSignatures);
        foreach (
            [
                'input_snapshot_json:employments.*.time_month.'
                    . 'jmhz_work_summary.source_snapshot_json::employment.id'
                    . '->payroll_employments:id',
                'input_snapshot_json:enforcement_evidence.insolvency.'
                    . 'payment_instruction_id'
                    . '->payroll_insolvency_payment_instructions:id',
                'input_snapshot_json:payout_rules.*.destination_reference'
                    . '->payroll_person_accounts:id@account:'
                    . '?payout_rules.*.destination_kind=bank',
                'input_snapshot_json:statutory_evidence.income_tax.'
                    . 'child_claims.*.id->payroll_person_tax_child_claims:id',
                'result_snapshot_json:employee_reference'
                    . '->payroll_employees:id@employee:',
                'result_snapshot_json:payer_reference'
                    . '->supplier:id@supplier:',
            ] as $signature
        ) {
            self::assertContains($signature, $embeddedSignatures);
        }
    }

    public function testRemapsCapturedPersonIdentitiesBeforeRefreshingBothSeals(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_statutory_person_results',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $input = CanonicalJson::encode([
            'employee' => [
                'full_name' => 'Synteticka osoba',
                'id' => 12,
                'is_active' => true,
                'profile_status' => 'complete',
            ],
            'employments' => [[
                'absences' => [],
                'employment' => [
                    'code' => 'synthetic',
                    'employee_id' => 12,
                    'id' => 19,
                    'office_id' => null,
                    'relation_type' => 'employment',
                ],
                'inputs' => [],
                'ordinary_evidence_profile' => ['source_term_id' => 23],
                'term' => ['effective_from' => '2026-01-01', 'id' => 23],
                'time_month' => null,
            ]],
            'statutory_accumulators' => [
                'income_tax' => ['state' => null],
                'social_insurance' => ['state' => null],
            ],
        ]);
        $result = CanonicalJson::encode([
            'employee_reference' => 'employee:12',
            'payer_reference' => 'supplier:864',
            'status' => 'calculated',
        ]);
        $row = [
            'input_snapshot_json' => $input,
            'input_snapshot_hash' => hash('sha256', $input),
            'result_snapshot_json' => $result,
            'result_snapshot_hash' => hash('sha256', $result),
        ];

        $restored = $projection->remapEmbeddedReferences(
            $row,
            static function (
                CompanyBackupEmbeddedReference $reference,
                int|string $sourceValue,
            ): int {
                return (int) $sourceValue + match ($reference->target) {
                    'table:payroll_employees' => 100,
                    'table:payroll_employments' => 200,
                    'table:payroll_employment_terms' => 300,
                    'table:payroll_offices' => 400,
                    'table:supplier' => 1_000,
                    default => throw new \LogicException(
                        'Test zachytil neocekavanou referenci.',
                    ),
                };
            },
            static fn (
                CompanyBackupEmbeddedHashReference $reference,
                string $sourceHash,
            ): string => throw new \LogicException(
                'Nullable prazdny akumulator nesmi mapovat hash.',
            ),
        );

        $restoredInput = json_decode(
            (string) $restored['input_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $employment = $restoredInput['employments'][0];
        self::assertSame(112, $restoredInput['employee']['id']);
        self::assertSame(112, $employment['employment']['employee_id']);
        self::assertSame(219, $employment['employment']['id']);
        self::assertNull($employment['employment']['office_id']);
        self::assertSame(323, $employment['term']['id']);
        self::assertSame(
            323,
            $employment['ordinary_evidence_profile']['source_term_id'],
        );
        self::assertNull(
            $restoredInput['statutory_accumulators']['income_tax']['state'],
        );
        $restoredResult = json_decode(
            (string) $restored['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('employee:112', $restoredResult['employee_reference']);
        self::assertSame('supplier:1864', $restoredResult['payer_reference']);
        self::assertSame(
            hash('sha256', (string) $restored['input_snapshot_json']),
            $restored['input_snapshot_hash'],
        );
        self::assertSame(
            hash('sha256', (string) $restored['result_snapshot_json']),
            $restored['result_snapshot_hash'],
        );
    }
}
