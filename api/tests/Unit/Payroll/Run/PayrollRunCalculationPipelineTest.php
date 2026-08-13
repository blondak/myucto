<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Component\PayrollComponentDefinitionFactory;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthEvidence;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthRequest;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentCalculator;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyInstruction;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentCalculation;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentPort;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentRunIntegration;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentSnapshotWriter;
use MyInvoice\Service\Payroll\Garnishment\PensionEvidence;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculationPipeline;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculator;
use MyInvoice\Service\Payroll\Run\PayrollRunGarnishmentProcessor;
use PHPUnit\Framework\TestCase;

final class PayrollRunCalculationPipelineTest extends TestCase
{
    public function testOnlyPipelineAssignsFinalResultSchemaVersion(): void
    {
        $calculator = new PayrollRunCalculator(
            new PayrollComponentDefinitionFactory(),
        );
        $garnishments = $this->garnishments();
        $snapshot = $this->snapshot();
        $baseResult = $calculator->calculate($snapshot);

        self::assertSame('payroll-run-result.v1', $baseResult['schema_version']);
        self::assertSame(
            'payroll-run-result.v1',
            $garnishments->calculate($snapshot, $baseResult)['schema_version'],
        );

        $result = (new PayrollRunCalculationPipeline(
            $calculator,
            $garnishments,
        ))->calculate($snapshot);

        self::assertSame('payroll-run-result.v2', $result['schema_version']);
        $people = $result['people'] ?? null;
        self::assertIsArray($people);
        $person = $people[0] ?? null;
        self::assertIsArray($person);
        self::assertArrayHasKey('enforcement', $person);
    }

    private function garnishments(): PayrollRunGarnishmentProcessor
    {
        $port = new class implements PayrollGarnishmentPort {
            public function calculate(
                EnforcementPersonMonthRequest $request,
            ): PayrollGarnishmentCalculation {
                throw new \LogicException('Persistence port is not used during calculation.');
            }
        };
        $writer = new class implements PayrollGarnishmentSnapshotWriter {
            public function store(
                EnforcementPersonMonthRequest $request,
                PayrollGarnishmentCalculation $calculation,
                ?int $revisionId,
                string $idempotencyKey,
            ): int {
                throw new \LogicException('Snapshot writer is not used during calculation.');
            }
        };

        return new PayrollRunGarnishmentProcessor(
            new GarnishmentCalculator(CzechPayrollRulesets2026::provider()),
            new PayrollGarnishmentRunIntegration($port, $writer),
        );
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        $evidence = new EnforcementPersonMonthEvidence(
            claims: [],
            eligibleDependants: 0,
            dependantsEvidenceComplete: true,
            eligibleSpouse: false,
            spouseEvidenceComplete: true,
            pensionEvidence: PensionEvidence::None,
            hasMultiplePayers: false,
            protectedAmountOverrideMinorUnits: null,
            protectedAmountOverrideVerified: false,
            claimRegisterEvidenceComplete: true,
            insolvency: InsolvencyInstruction::none(),
        );

        return [
            'schema_version' => 'payroll-run-input.v1',
            'supplier_id' => 1,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'payment_date' => '2026-07-15',
            'office_id' => null,
            'ruleset_manifest' => [
                ['id' => 'synthetic', 'sha256' => str_repeat('a', 64)],
            ],
            'people' => [[
                'employee' => ['id' => 11, 'full_name' => 'Synthetic Employee'],
                'enforcement_evidence' => $evidence->toCanonicalArray(),
                'employments' => [[
                    'employment' => [
                        'id' => 101,
                        'employee_id' => 11,
                        'code' => 'SYN-101',
                        'relation_type' => 'employment',
                        'status' => 'active',
                    ],
                    'term' => ['effective_from' => '2026-01-01'],
                    'inputs' => [[
                        'id' => 1,
                        'amount_minor' => 200_000,
                        'quantity_milliunits' => null,
                        'source_kind' => 'manual',
                        'component' => [
                            'code' => 'MZDA',
                            'name' => 'Syntetická mzda',
                            'component_kind' => 'base_wage',
                            'value_kind' => 'monetary',
                            'frequency_kind' => 'regular',
                            'tax_treatment' => 'included',
                            'social_participation_treatment' => 'included',
                            'social_treatment' => 'included',
                            'health_participation_treatment' => 'included',
                            'health_treatment' => 'included',
                            'average_earning_treatment' => 'included',
                            'enforcement_treatment' => 'included',
                            'jmhz_treatment' => 'included',
                            'statistics_treatment' => 'included',
                            'accounting_debit_code' => '521',
                            'accounting_credit_code' => '331',
                            'annual_limit_minor' => null,
                        ],
                    ]],
                ]],
            ]],
        ];
    }
}
