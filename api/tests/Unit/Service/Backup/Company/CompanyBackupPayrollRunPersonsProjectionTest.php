<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollRunPersonsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'revision_id',
        'employee_id',
        'result_json',
        'result_hash',
        'status',
    ];

    public function testDeclaresExactSealedRunPersonProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_run_persons');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->embeddedReferences->assertRegistryTargets($registry);
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
                        ['supplier_id', 'revision_id'],
                        'payroll_run_revisions',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'revision_id', 'employee_id'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [['supplier_id', 'revision_id', 'employee_id']],
            $definition->details['reference_keys'] ?? null,
        );
        self::assertSame(
            [
                'supplier_id,employee_id->payroll_employees:supplier_id,id',
                'supplier_id,revision_id->payroll_run_revisions:supplier_id,id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            ['result_hash<-sha256_canonical_json:result_json?'],
            array_map(
                static fn ($hash): string => $hash->signature(),
                $projection->derivedHashes->hashes,
            ),
        );

        $signatures = array_map(
            static fn ($reference): string => $reference->signature(),
            $projection->embeddedReferences->references,
        );
        self::assertCount(25, $signatures);
        foreach ([
            'result_json:employee_id->payroll_employees:id',
            'result_json:employments.*.inputs.*.input_id->payroll_inputs:id',
            'result_json:enforcement.input.income.trace.*.id'
                . '->payroll_employees:id@revision-person-~-',
            'result_json:statutory.net_pay.deductions.*.deduction_reference'
                . '->payroll_deduction_agreements:id@agreement:',
            'result_json:statutory.social_insurance.relationships.*.'
                . 'included_assessment_base_components.*'
                . '->payroll_inputs:id@input.~.',
        ] as $signature) {
            self::assertContains($signature, $signatures);
        }
    }

    public function testRemapsCompletePersonResultAndRefreshesHash(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()
            ->definition('table:payroll_run_persons');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $result = CanonicalJson::encode([
            'employee_id' => 17,
            'employments' => [[
                'employment_id' => 19,
                'inputs' => [['input_id' => 23]],
            ]],
            'enforcement' => ['input' => ['income' => ['trace' => [[
                'id' => 'revision-person-17-garnishable',
                'payer_id' => 'supplier-7',
            ]]]]],
            'statutory' => $this->statutoryPerson(),
        ]);
        $restored = $projection->remapEmbeddedReferences(
            [
                'result_json' => $result,
                'result_hash' => hash('sha256', $result),
            ],
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $sourceValue,
            ): int => (int) $sourceValue + match ($reference->target) {
                'table:supplier' => 1_000,
                'table:payroll_employees' => 100,
                'table:payroll_employments' => 200,
                'table:payroll_inputs' => 300,
                'table:payroll_deduction_agreements' => 400,
                default => throw new \LogicException(
                    'Test zachytil neočekávanou referenci.',
                ),
            },
        );
        $person = json_decode(
            (string) $restored['result_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(117, $person['employee_id']);
        self::assertSame(219, $person['employments'][0]['employment_id']);
        self::assertSame(323, $person['employments'][0]['inputs'][0]['input_id']);
        self::assertSame(
            'revision-person-117-garnishable',
            $person['enforcement']['input']['income']['trace'][0]['id'],
        );
        self::assertSame(
            'supplier-1007',
            $person['enforcement']['input']['income']['trace'][0]['payer_id'],
        );
        self::assertSame('employee:117', $person['statutory']['person_reference']);
        self::assertSame(
            'agreement:429',
            $person['statutory']['net_pay']['deductions'][0]
                ['deduction_reference'],
        );
        self::assertSame(
            'input.323.base',
            $person['statutory']['social_insurance']['relationships'][0]
                ['included_assessment_base_components'][0],
        );
        self::assertSame(
            hash('sha256', (string) $restored['result_json']),
            $restored['result_hash'],
        );
    }

    public function testKeepsPendingPersonWithoutResultAsNullablePair(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()
            ->definition('table:payroll_run_persons');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $row = ['result_json' => null, 'result_hash' => null];

        $projection->assertExportRow($row);
        self::assertSame(
            $row,
            $projection->remapEmbeddedReferences(
                $row,
                static fn (
                    CompanyBackupEmbeddedReference $reference,
                    int|string $sourceValue,
                ): never => throw new \LogicException(
                    'Prázdný pending výsledek nesmí mapovat reference '
                    . $reference->signature() . ':' . $sourceValue,
                ),
            ),
        );
    }

    /** @return array<string,mixed> */
    private function statutoryPerson(): array
    {
        $insurance = [
            'person_id' => 'employee:17',
            'person_reference' => 'employee:17',
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
                'person_reference' => 'employee:17',
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
}
