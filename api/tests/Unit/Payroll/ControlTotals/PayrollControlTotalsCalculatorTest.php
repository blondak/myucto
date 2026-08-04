<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\ControlTotals;

use MyInvoice\Service\Payroll\ControlTotals\PayrollControlTotalsCalculator;
use MyInvoice\Service\Payroll\ControlTotals\PayrollControlTotals;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class PayrollControlTotalsCalculatorTest extends TestCase
{
    public function testBuildsExactHierarchyLiabilityAndAccountingTotals(): void
    {
        $totals = $this->calculate(
            $this->resultSnapshot(),
        );

        self::assertSame(9, $totals->supplierId);
        self::assertSame(17, $totals->revisionId);
        self::assertCount(3, $totals->relationships);
        self::assertCount(2, $totals->people);
        self::assertSame([
            [
                'office_id' => 71,
                'totals' => $this->metrics(10_000),
            ],
            [
                'office_id' => 72,
                'totals' => $this->metrics(20_000),
            ],
        ], $totals->offices);
        self::assertSame($this->metrics(30_000), $totals->company);
        self::assertSame([
            [
                'liability_kind' => 'advance_tax',
                'direction' => 'outgoing',
                'amount_minor' => 3_000,
            ],
            [
                'liability_kind' => 'health_insurance',
                'direction' => 'outgoing',
                'amount_minor' => 4_050,
            ],
            [
                'liability_kind' => 'net_wage',
                'direction' => 'outgoing',
                'amount_minor' => 23_100,
            ],
            [
                'liability_kind' => 'social_insurance',
                'direction' => 'outgoing',
                'amount_minor' => 6_100,
            ],
            [
                'liability_kind' => 'standard_deduction',
                'direction' => 'outgoing',
                'amount_minor' => 450,
            ],
            [
                'liability_kind' => 'withholding_tax',
                'direction' => 'outgoing',
                'amount_minor' => 0,
            ],
        ], $totals->liabilities);
        self::assertSame([
            [
                'debit_code' => '521',
                'credit_code' => '331',
                'amount_minor' => 30_000,
            ],
        ], $totals->accountingDimensions);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $totals->controlHash,
        );
    }

    public function testFailsClosedWhenPersonDoesNotEqualRelationships(): void
    {
        $result = $this->resultSnapshot(firstPersonSourceAmount: 10_001);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('osoby');

        $this->calculate($result);
    }

    public function testFailsClosedWhenAccountingDimensionDoesNotEqualInputs(): void
    {
        $result = $this->resultSnapshot(accountingAmount: 29_999);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('účetní');

        $this->calculate($result);
    }

    public function testFailsClosedForUnapprovedStatutoryCalculation(): void
    {
        $result = $this->resultSnapshot(statutoryStatus: 'manual_review');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('zákonn');

        $this->calculate($result);
    }

    public function testRejectsDecimalOrNumericStringInsteadOfExactMinorUnits(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('celých haléřích');

        $this->calculate(
            $this->resultSnapshot(stringPersonSourceAmount: true),
        );
    }

    /** @return array<string,mixed> */
    private function inputSnapshot(): array
    {
        return [
            'schema_version' => 'payroll-run-input.v2',
            'people' => [
                [
                    'employee' => ['id' => 501],
                    'employments' => [[
                        'employment' => ['id' => 601, 'office_id' => 71],
                        'inputs' => [],
                    ]],
                ],
                [
                    'employee' => ['id' => 502],
                    'employments' => [
                        [
                            'employment' => ['id' => 602, 'office_id' => 72],
                            'inputs' => [],
                        ],
                        [
                            'employment' => ['id' => 603, 'office_id' => 72],
                            'inputs' => [],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function resultSnapshot(
        int $firstPersonSourceAmount = 10_000,
        int $accountingAmount = 30_000,
        string $statutoryStatus = 'calculated',
        bool $stringPersonSourceAmount = false,
    ): array {
        $firstPerson = $this->person(
            501,
            [
                $this->relationship(601, 10_000, 1),
            ],
            700,
            450,
            900,
            1_000,
            0,
            100,
            7_750,
        );
        if ($firstPersonSourceAmount !== 10_000
            || $stringPersonSourceAmount
        ) {
            $firstPerson['totals'] = [
                ...$this->metrics(10_000),
                'source_amount_minor' => $stringPersonSourceAmount
                    ? (string) $firstPersonSourceAmount
                    : $firstPersonSourceAmount,
            ];
        }
        return [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash(
                'sha256',
                CanonicalJson::encode($this->inputSnapshot()),
            ),
            'people' => [
                $firstPerson,
                $this->person(
                    502,
                    [
                        $this->relationship(602, 12_000, 2),
                        $this->relationship(603, 8_000, 3),
                    ],
                    1_400,
                    900,
                    1_800,
                    2_000,
                    0,
                    350,
                    15_350,
                ),
            ],
            'totals' => $this->metrics(30_000),
            'accounting_totals' => [[
                'debit_code' => '521',
                'credit_code' => '331',
                'amount_minor' => $accountingAmount,
            ]],
            'statutory' => [
                'status' => $statutoryStatus,
                'employer_social_minor_units' => 4_000,
            ],
        ];
    }

    /**
     * @param list<array{
     *   employment_id:int,
     *   inputs:list<array{
     *     input_id:int,
     *     accounting:array{
     *       debit_code:string,
     *       credit_code:string,
     *       amount_minor:int
     *     }
     *   }>,
     *   totals:array<string,int>
     * }> $relationships
     * @return array<string,mixed>
     */
    private function person(
        int $employeeId,
        array $relationships,
        int $social,
        int $healthEmployee,
        int $healthEmployer,
        int $advanceTax,
        int $withholdingTax,
        int $deducted,
        int $netPayable,
    ): array {
        $source = array_sum(array_map(
            static fn(array $row): int
                => $row['totals']['source_amount_minor'],
            $relationships,
        ));
        return [
            'employee_id' => $employeeId,
            'employments' => $relationships,
            'totals' => $this->metrics($source),
            'statutory' => [
                'person_reference' => "employee:{$employeeId}",
                'status' => 'calculated',
                'social_insurance' => [
                    'employee_contribution_minor_units' => $social,
                ],
                'health_insurance' => [
                    'employee_contribution_minor_units' => $healthEmployee,
                    'employer_contribution_minor_units' => $healthEmployer,
                    'total_contribution_minor_units'
                        => $healthEmployee + $healthEmployer,
                ],
                'income_tax' => [
                    'advance_tax' => [
                        'tax_after_credits_minor_units' => $advanceTax,
                        'tax_bonus_minor_units' => 0,
                    ],
                    'withholding_tax_minor_units' => $withholdingTax,
                ],
                'net_pay' => [
                    'person_reference' => "employee:{$employeeId}",
                    'relationships' => array_map(
                        static fn(array $row): array => [
                            'relationship_reference'
                                => 'employment:' . $row['employment_id'],
                            'cash_income_minor_units'
                                => $row['totals']['cash_payable_minor'],
                            'non_cash_income_minor_units' => 0,
                        ],
                        $relationships,
                    ),
                    'cash_income_minor_units' => $source,
                    'non_cash_income_minor_units' => 0,
                    'employee_social_minor_units' => $social,
                    'employee_health_minor_units' => $healthEmployee,
                    'advance_tax_minor_units' => $advanceTax,
                    'withholding_tax_minor_units' => $withholdingTax,
                    'tax_bonus_minor_units' => 0,
                    'correction_minor_units' => 0,
                    'net_before_deductions_minor_units'
                        => $netPayable + $deducted,
                    'deducted_minor_units' => $deducted,
                    'net_payable_minor_units' => $netPayable,
                    'deductions' => $deducted === 0 ? [] : [[
                        'applied_minor_units' => $deducted,
                    ]],
                ],
                'net_payable_minor_units' => $netPayable,
            ],
        ];
    }

    /**
     * @return array{
     *   employment_id:int,
     *   inputs:list<array{
     *     input_id:int,
     *     accounting:array{
     *       debit_code:string,
     *       credit_code:string,
     *       amount_minor:int
     *     }
     *   }>,
     *   totals:array<string,int>
     * }
     */
    private function relationship(
        int $employmentId,
        int $amount,
        int $inputId,
    ): array {
        return [
            'employment_id' => $employmentId,
            'inputs' => [[
                'input_id' => $inputId,
                'accounting' => [
                    'debit_code' => '521',
                    'credit_code' => '331',
                    'amount_minor' => $amount,
                ],
            ]],
            'totals' => $this->metrics($amount),
        ];
    }

    /** @return array<string,int> */
    private function metrics(int $amount): array
    {
        return [
            'source_amount_minor' => $amount,
            'cash_payable_minor' => $amount,
            'tax_base_minor' => $amount,
            'social_base_minor' => $amount,
            'health_base_minor' => $amount,
            'average_earning_base_minor' => $amount,
            'enforcement_base_minor' => $amount,
            'jmhz_amount_minor' => $amount,
        ];
    }

    /** @param array<string,mixed> $result */
    private function calculate(array $result): PayrollControlTotals
    {
        return new PayrollControlTotalsCalculator()->calculate(
            9,
            17,
            $this->inputSnapshot(),
            $result,
            hash('sha256', CanonicalJson::encode($result)),
        );
    }
}
