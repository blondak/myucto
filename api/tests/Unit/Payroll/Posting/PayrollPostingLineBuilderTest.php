<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Posting;

use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use MyInvoice\Service\Payroll\Posting\PayrollPostingLineBuilder;
use PHPUnit\Framework\TestCase;

final class PayrollPostingLineBuilderTest extends TestCase
{
    private PayrollPostingLineBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PayrollPostingLineBuilder();
    }

    public function testKeepsDirectorEmploymentPartnerIncomeAndOfficeRewardSeparate(): void
    {
        $preview = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame([
            '331|credit' => 100_000,
            '331|debit' => 22_333,
            '336|credit' => 271_800,
            '342|credit' => 50_000,
            '366|credit' => 500_000,
            '366|debit' => 111_667,
            '379|credit' => 15_000,
            '521|debit' => 100_000,
            '522|debit' => 200_000,
            '523|debit' => 300_000,
            '524|debit' => 202_800,
        ], $this->lineMap($preview->lines));
        self::assertSame(936_800, $preview->debitTotalMinor);
        self::assertSame(936_800, $preview->creditTotalMinor);
        $deductionLines = array_values(array_filter(
            $preview->lines,
            static fn (array $line): bool =>
                $line['account_code'] === '379'
                && $line['side'] === 'credit',
        ));
        self::assertCount(2, $deductionLines);
        self::assertCount(2, array_unique(array_column(
            $deductionLines,
            'cost_center',
        )));

        $gross = array_values(array_filter(
            $preview->targetAllocations,
            static fn (array $allocation): bool =>
                str_starts_with($allocation['allocation_key'], 'gross:'),
        ));
        self::assertSame([
            ['521', 100_000],
            ['331', -100_000],
            ['522', 200_000],
            ['366', -200_000],
            ['523', 300_000],
            ['366', -300_000],
        ], array_map(
            static fn (array $allocation): array => [
                $allocation['account_code'],
                $allocation['signed_minor'],
            ],
            $gross,
        ));
    }

    public function testUsesTenantRelationAccountsAsSingleSourceOfDefaults(): void
    {
        $accounts = PayrollAccountingDefaults::codes();
        $accounts['employment_gross_debit'] = '521.100';
        $accounts['employment_gross_credit'] = '331.100';
        $accounts['partner_gross_debit'] = '522.100';
        $accounts['partner_gross_credit'] = '366.100';
        $accounts['statutory_gross_debit'] = '523.100';
        $accounts['statutory_gross_credit'] = '366.200';

        $preview = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySets(),
            $accounts,
        );
        $grossAccounts = [];
        foreach ($preview->targetAllocations as $allocation) {
            if (str_starts_with($allocation['allocation_key'], 'gross:')) {
                $grossAccounts[] = $allocation['account_code'];
            }
        }

        self::assertSame([
            '521.100',
            '331.100',
            '522.100',
            '366.100',
            '523.100',
            '366.200',
        ], $grossAccounts);
    }

    public function testExplicitComponentPairOverridesRelationDefaultAndFeedsSettlement(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][2]['inputs'][0]['component'][
            'accounting_debit_code'
        ] = '528';
        $snapshot['people'][0]['employments'][2]['inputs'][0]['component'][
            'accounting_credit_code'
        ] = '366.523';
        $result = $this->calculatedResult();
        $result['people'][0]['employments'][2]['inputs'][0]['accounting'] = [
            'debit_code' => '528',
            'credit_code' => '366.523',
            'amount_minor' => 300_000,
        ];
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $lineMap = $this->lineMap($preview->lines);

        self::assertSame(300_000, $lineMap['528|debit']);
        self::assertSame(300_000, $lineMap['366.523|credit']);
        self::assertArrayHasKey('366.523|debit', $lineMap);
        self::assertArrayNotHasKey('523|debit', $lineMap);
    }

    public function testNonCashComponentWithoutExplicitPairFailsClosed(): void
    {
        $result = $this->calculatedResult();
        $result['people'][0]['employments'][0]['inputs'][0]['totals'] = [
            'source_amount_minor' => 100_000,
            'cash_payable_minor' => 80_000,
        ];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nepeněžní část');
        $this->builder->build(
            $this->snapshot(),
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
    }

    public function testCorrectionProducesOnlyBalancedDeltaAgainstPreviousTarget(): void
    {
        $first = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $sets = $this->statutorySets();
        $sets['social_insurance']['result_snapshot'][
            'employer_contribution_minor_units'
        ] = 158_800;

        $correction = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $sets,
            PayrollAccountingDefaults::codes(),
            $first->targetAllocations,
        );

        self::assertSame([
            '336|credit' => 10_000,
            '524|debit' => 10_000,
        ], $this->lineMap($correction->lines));
        self::assertSame(10_000, $correction->debitTotalMinor);
        self::assertSame(10_000, $correction->creditTotalMinor);
        self::assertNotSame($first->targetHash, $correction->targetHash);
    }

    public function testRejectsResultThatDoesNotReconcileToNetAndEnforcement(): void
    {
        $result = $this->calculatedResult();
        $result['people'][0]['payable_after_enforcement_minor']++;

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('čistou výplatou');
        $this->builder->build(
            $this->snapshot(),
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
    }

    public function testPartnerSettlementClearsWagePayableAgainstShareholderAccount(): void
    {
        $snapshot = $this->snapshotWithSettlementRule();
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );

        // Celá čistá výplata po exekucích (466 000) se přeúčtuje z mzdových
        // závazků na účet společníka. 331 i 366 tak jdou na nulu — přesně to,
        // proč se to dělá měsíčně místo ročního ručního zápočtu.
        self::assertSame([
            '331|credit' => 100_000,
            '331|debit' => 100_000,
            '336|credit' => 271_800,
            '342|credit' => 50_000,
            '365.100|credit' => 466_000,
            '366|credit' => 500_000,
            '366|debit' => 500_000,
            '379|credit' => 15_000,
            '521|debit' => 100_000,
            '522|debit' => 200_000,
            '523|debit' => 300_000,
            '524|debit' => 202_800,
        ], $this->lineMap($preview->lines));
        self::assertSame(1_402_800, $preview->debitTotalMinor);
        self::assertSame(1_402_800, $preview->creditTotalMinor);

        $settlement = array_values(array_filter(
            $preview->targetAllocations,
            static fn (array $allocation): bool => str_contains(
                $allocation['allocation_key'],
                ':partner-settlement:',
            ),
        ));
        self::assertSame(
            [['331', 77_667], ['366', 155_333], ['366', 233_000], ['365.100', -466_000]],
            array_map(
                static fn (array $allocation): array => [
                    $allocation['account_code'],
                    $allocation['signed_minor'],
                ],
                $settlement,
            ),
        );
        self::assertSame(0, array_sum(array_column($settlement, 'signed_minor')));
    }

    public function testPartnerSettlementRefusesOrdinaryEmployee(): void
    {
        $snapshot = $this->snapshotWithSettlementRule();
        foreach (array_keys($snapshot['people'][0]['employments']) as $index) {
            $snapshot['people'][0]['employments'][$index]['employment'][
                'relation_type'
            ] = 'employment';
        }
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Zápočtem na účet společníka');
        $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
    }

    public function testPayoutRulesWithoutSettlementLeavePostingUnchanged(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['payout_rules'] = [[
            'id' => 21,
            'allocation_reference' => 'cash',
            'destination_kind' => 'cash',
            'destination_reference' => null,
            'allocation_kind' => 'remainder',
            'amount_minor' => null,
            'basis_points' => null,
            'priority_no' => 10,
            'row_version' => 1,
        ]];
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $preview = $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
        $baseline = $this->builder->build(
            $this->snapshot(),
            $this->calculatedResult(),
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );

        self::assertSame(
            $this->lineMap($baseline->lines),
            $this->lineMap($preview->lines),
        );
        self::assertSame($baseline->targetHash, $preview->targetHash);
    }

    /** @return array<string,mixed> */
    private function snapshotWithSettlementRule(): array
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['payout_rules'] = [[
            'id' => 31,
            'allocation_reference' => 'partner-settlement',
            'destination_kind' => 'partner_settlement',
            'destination_reference' => '365.100',
            'allocation_kind' => 'remainder',
            'amount_minor' => null,
            'basis_points' => null,
            'priority_no' => 10,
            'row_version' => 1,
        ]];

        return $snapshot;
    }

    /**
     * @param list<array{
     *   account_code:string,
     *   side:'debit'|'credit',
     *   amount_minor:int,
     *   description:string
     * }> $lines
     * @return array<string,int>
     */
    private function lineMap(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            $key = $line['account_code'] . '|' . $line['side'];
            $result[$key] = ($result[$key] ?? 0) + $line['amount_minor'];
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * `payroll_dimensions.default_account_code` se dal nastavit od migrace 1307,
     * validoval se ve službě i v DB — a zaúčtování ho nečetlo nikde. Uživatel
     * nastavil středisku nákladový účet a mzda se zaúčtovala na výchozí
     * předkontaci zaměstnavatele, bez jediného hlášení.
     */
    public function testDimensionDefaultAccountReplacesTheEmployerDefaultCostAccount(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['dimensions'] = [
            $this->dimension('cost_center', 'VYROBA', '521.100'),
        ];
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(100_000, $lineMap['521.100|debit'], 'Náklad jde na účet střediska.');
        self::assertArrayNotHasKey('521|debit', $lineMap, 'Výchozí účet zaměstnavatele se už nepoužije.');
        self::assertSame(
            100_000,
            $lineMap['331|credit'],
            'Dimenze mění NÁKLAD, ne závazek vůči zaměstnanci.',
        );
        self::assertSame(
            202_800,
            $lineMap['524|debit'],
            'Zákonné odvody zaměstnavatele dimenze nepřebíjí — jeden kód na dvě nákladové skupiny nesedí.',
        );
    }

    /**
     * `default_account_code` je podle svého jména i podle komentáře migrace 1307
     * „analytika k VÝCHOZÍM kontacím". Explicitní volba u složky je konkrétnější
     * a musí vyhrát — jinak by dimenze tiše rozbila ručně nastavenou předkontaci.
     */
    public function testExplicitComponentAccountBeatsTheDimensionDefault(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['dimensions'] = [
            $this->dimension('cost_center', 'VYROBA', '521.100'),
        ];
        $snapshot['people'][0]['employments'][0]['inputs'][0]['component'][
            'accounting_debit_code'
        ] = '518.900';
        $snapshot['people'][0]['employments'][0]['inputs'][0]['component'][
            'accounting_credit_code'
        ] = '331.900';
        $result = $this->calculatedResult();
        $result['people'][0]['employments'][0]['inputs'][0]['accounting'] = [
            'debit_code' => '518.900',
            'credit_code' => '331.900',
            'amount_minor' => 100_000,
        ];
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(100_000, $lineMap['518.900|debit']);
        self::assertArrayNotHasKey('521.100|debit', $lineMap);
    }

    /**
     * Vztah může mít středisko, zakázku i činnost současně a účet smí nést každá.
     * Pořadí musí být pevné, jinak by tytéž vstupy zaúčtovaly různě podle toho,
     * v jakém pořadí je někdo zadal.
     */
    public function testDimensionPriorityIsFixedCostCenterThenProjectThenActivity(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['dimensions'] = [
            $this->dimension('activity', 'MONTAZ', '521.300'),
            $this->dimension('project', 'ZAKAZKA1', '521.200'),
            $this->dimension('cost_center', 'VYROBA', '521.100'),
        ];
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(100_000, $lineMap['521.100|debit'], 'Středisko je primární nositel nákladové analytiky.');
        self::assertArrayNotHasKey('521.200|debit', $lineMap);
        self::assertArrayNotHasKey('521.300|debit', $lineMap);
    }

    /** Dimenze bez účtu je jen sledovací značka — na zaúčtování nesmí mít vliv. */
    public function testDimensionWithoutDefaultAccountFallsBackToTheEmployerDefault(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['dimensions'] = [
            $this->dimension('cost_center', 'VYROBA', null),
            $this->dimension('project', 'ZAKAZKA1', '521.200'),
        ];
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(
            100_000,
            $lineMap['521.200|debit'],
            'Středisko účet nemá, takže rozhoduje zakázka.',
        );
    }

    /**
     * Revize zmrazené dřív, než dimenze začaly do snapshotu vstupovat, klíč
     * `dimensions` vůbec nemají. Nesmí se přeúčtovat jinak než původně — proto
     * absence znamená „žádná dimenze", ne dohledání dnešního přiřazení.
     */
    public function testHistoricalSnapshotWithoutDimensionsPostsExactlyAsBefore(): void
    {
        $snapshot = $this->snapshot();
        self::assertArrayNotHasKey('dimensions', $snapshot['people'][0]['employments'][0]);

        $lineMap = $this->lineMap($this->builder->build(
            $snapshot,
            $this->calculatedResult(),
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        )->lines);

        self::assertSame(100_000, $lineMap['521|debit']);
    }

    /** Nesmyslný účet v dimenzi zaúčtování zastaví, místo aby ho zapsal do deníku. */
    public function testInvalidDimensionAccountIsRejected(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['people'][0]['employments'][0]['dimensions'] = [
            $this->dimension('cost_center', 'VYROBA', 'nesmysl'),
        ];
        $result = $this->calculatedResult();
        $result['source_snapshot_hash'] = $this->snapshotHash($snapshot);

        $this->expectException(\DomainException::class);
        $this->builder->build(
            $snapshot,
            $result,
            $this->statutorySets(),
            PayrollAccountingDefaults::codes(),
        );
    }

    /** @return array<string,mixed> */
    private function dimension(string $type, string $code, ?string $account): array
    {
        return [
            'type' => $type,
            'code' => $code,
            'name' => $code,
            'default_account_code' => $account,
        ];
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        return [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 1,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'people' => [[
                'employee' => ['id' => 11],
                'employments' => [
                    $this->snapshotEmployment(101, 'employment', 1, 100_000),
                    $this->snapshotEmployment(102, 'partner_dependent', 2, 200_000),
                    $this->snapshotEmployment(103, 'statutory_body', 3, 300_000),
                ],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function snapshotEmployment(
        int $employmentId,
        string $relationType,
        int $inputId,
        int $amountMinor,
    ): array {
        return [
            'employment' => [
                'id' => $employmentId,
                'employee_id' => 11,
                'relation_type' => $relationType,
            ],
            'inputs' => [[
                'id' => $inputId,
                'amount_minor' => $amountMinor,
                'component' => [
                    'code' => "SLOZKA_{$inputId}",
                    'accounting_debit_code' => null,
                    'accounting_credit_code' => null,
                ],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function calculatedResult(): array
    {
        return [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => $this->snapshotHash($this->snapshot()),
            'statutory' => ['status' => 'calculated'],
            'people' => [[
                'employee_id' => 11,
                'totals' => ['cash_payable_minor' => 600_000],
                'employments' => [
                    $this->resultEmployment(101, 1, 100_000),
                    $this->resultEmployment(102, 2, 200_000),
                    $this->resultEmployment(103, 3, 300_000),
                ],
                'enforcement' => [
                    'result' => [
                        'status' => 'supported',
                        'total_withheld_minor_units' => 5_000,
                    ],
                ],
                'payable_after_enforcement_minor' => 466_000,
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function resultEmployment(
        int $employmentId,
        int $inputId,
        int $amountMinor,
    ): array {
        return [
            'employment_id' => $employmentId,
            'totals' => ['cash_payable_minor' => $amountMinor],
            'inputs' => [[
                'input_id' => $inputId,
                'totals' => [
                    'source_amount_minor' => $amountMinor,
                    'cash_payable_minor' => $amountMinor,
                ],
                'accounting' => [
                    'debit_code' => null,
                    'credit_code' => null,
                    'amount_minor' => $amountMinor,
                ],
            ]],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function statutorySets(): array
    {
        return [
            'social_insurance' => [
                'result_status' => 'calculated',
                'result_snapshot' => [
                    'employer_contribution_minor_units' => 148_800,
                ],
                'people' => [[
                    'employee_id' => 11,
                    'result_status' => 'calculated',
                    'result_snapshot' => [
                        'employee_contribution_minor_units' => 42_000,
                    ],
                ]],
            ],
            'health_insurance' => [
                'result_status' => 'calculated',
                'result_snapshot' => [
                    'employer_contribution_minor_units' => 54_000,
                ],
                'people' => [[
                    'employee_id' => 11,
                    'result_status' => 'calculated',
                    'result_snapshot' => [
                        'employee_contribution_minor_units' => 27_000,
                    ],
                ]],
            ],
            'income_tax' => [
                'result_status' => 'calculated',
                'result_snapshot' => ['status' => 'calculated'],
                'people' => [[
                    'employee_id' => 11,
                    'result_status' => 'calculated',
                    'result_snapshot' => [
                        'advance_tax' => [
                            'tax_after_credits_minor_units' => 50_000,
                            'tax_bonus_minor_units' => 0,
                        ],
                        'withholding_tax_minor_units' => 0,
                    ],
                ]],
            ],
            'net_pay' => [
                'result_status' => 'calculated',
                'result_snapshot' => ['status' => 'calculated'],
                'people' => [[
                    'employee_id' => 11,
                    'result_status' => 'calculated',
                    'result_snapshot' => [
                        'deducted_minor_units' => 10_000,
                        'net_payable_minor_units' => 471_000,
                        'deductions' => [[
                            'deduction_reference' => 'agreement:7',
                            'applied_minor_units' => 10_000,
                        ]],
                    ],
                ]],
            ],
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private function snapshotHash(array $snapshot): string
    {
        return hash(
            'sha256',
            \MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode($snapshot),
        );
    }
}
