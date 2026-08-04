<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Component\PayrollComponentDefinitionFactory;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculator;
use PHPUnit\Framework\TestCase;

final class PayrollRunCalculatorTest extends TestCase
{
    private PayrollRunCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PayrollRunCalculator(
            new PayrollComponentDefinitionFactory(),
        );
    }

    public function testAggregationPreservesEmploymentPersonAndCompanyInvariant(): void
    {
        $snapshot = $this->snapshot([
            $this->employment(11, 101, [
                $this->input(1, 120_000, 'WAGE'),
                $this->input(2, 15_000, 'BONUS'),
            ]),
            $this->employment(11, 102, [
                $this->input(3, 25_000, 'AGREEMENT'),
            ]),
            $this->employment(12, 103, [
                $this->input(4, 40_000, 'OTHER'),
            ]),
        ]);

        $result = $this->calculator->calculate($snapshot);

        self::assertSame(200_000, $result['totals']['source_amount_minor']);
        self::assertSame(160_000, $result['people'][0]['totals']['source_amount_minor']);
        self::assertSame(40_000, $result['people'][1]['totals']['source_amount_minor']);
        self::assertSame(
            $result['totals']['source_amount_minor'],
            array_sum(array_column(
                array_column($result['people'], 'totals'),
                'source_amount_minor',
            )),
        );
        self::assertSame(
            $result['people'][0]['totals']['source_amount_minor'],
            array_sum(array_column(
                array_column($result['people'][0]['employments'], 'totals'),
                'source_amount_minor',
            )),
        );
        self::assertSame([
            [
                'debit_code' => '521',
                'credit_code' => '331',
                'amount_minor' => 200_000,
            ],
        ], $result['accounting_totals']);
    }

    public function testSameCanonicalSnapshotAlwaysProducesSameResult(): void
    {
        $snapshot = $this->snapshot([
            $this->employment(11, 101, [$this->input(1, 120_000, 'WAGE')]),
        ]);

        $first = $this->calculator->calculate($snapshot);
        $second = $this->calculator->calculate($snapshot);

        self::assertSame(CanonicalJson::encode($first), CanonicalJson::encode($second));
        self::assertSame(
            hash('sha256', CanonicalJson::encode($snapshot)),
            $first['source_snapshot_hash'],
        );
    }

    /**
     * @param list<array<string,mixed>> $employments
     * @return array<string,mixed>
     */
    private function snapshot(array $employments): array
    {
        $people = [];
        foreach ($employments as $employment) {
            $employeeId = $employment['employment']['employee_id'];
            $people[$employeeId] ??= [
                'employee' => ['id' => $employeeId, 'full_name' => "Synthetic {$employeeId}"],
                'employments' => [],
            ];
            $people[$employeeId]['employments'][] = $employment;
        }

        return [
            'schema_version' => 'payroll-run-input.v1',
            'supplier_id' => 1,
            'period_start' => '2026-06-01',
            'office_id' => null,
            'ruleset_manifest' => [['id' => 'synthetic', 'sha256' => str_repeat('a', 64)]],
            'people' => array_values($people),
        ];
    }

    /**
     * @param list<array<string,mixed>> $inputs
     * @return array<string,mixed>
     */
    private function employment(int $employeeId, int $employmentId, array $inputs): array
    {
        return [
            'employment' => [
                'id' => $employmentId,
                'employee_id' => $employeeId,
                'code' => "SYN-{$employmentId}",
                'relation_type' => 'employment',
                'status' => 'active',
            ],
            'term' => ['effective_from' => '2026-01-01'],
            'inputs' => $inputs,
        ];
    }

    /** @return array<string,mixed> */
    private function input(int $id, int $amountMinor, string $code): array
    {
        return [
            'id' => $id,
            'amount_minor' => $amountMinor,
            'quantity_milliunits' => null,
            'source_kind' => 'manual',
            'component' => [
                'code' => $code,
                'name' => "Synthetic {$code}",
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
        ];
    }
}
