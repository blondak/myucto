<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Garnishment\ClaimCategory;
use MyInvoice\Service\Payroll\Garnishment\DeductionClaim;
use MyInvoice\Service\Payroll\Garnishment\DeductionLegalBasis;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthEvidence;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthRequest;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeItem;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeKind;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeResolver;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentCalculator;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentInput;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentResult;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyInstruction;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentCalculation;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentPort;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentRunIntegration;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentSnapshotWriter;
use MyInvoice\Service\Payroll\Garnishment\PensionEvidence;
use MyInvoice\Service\Payroll\Garnishment\SeveranceMultiple;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Run\PayrollRunGarnishmentProcessor;
use PHPUnit\Framework\TestCase;

/**
 * CYK-B16a — srážky z odstupného podle § 299 odst. 4 o. s. ř.
 *
 * „Z odstupného se srážky vypočítávají zvlášť z každého násobku průměrného
 * výdělku." Odstupné 3× průměr jsou tři samostatné měsíční příjmy, každý se
 * svou nezabavitelnou částkou; plátce odstupného na nich nemá paušál (§ 301
 * odst. 2 o. s. ř.). Dřív se odstupné přičetlo ke mzdě posledního měsíce
 * a sráželo se z celku s jedinou nezabavitelnou částkou.
 */
final class PayrollRunSeveranceGarnishmentTest extends TestCase
{
    private const EMPLOYEE_ID = 21;
    private const EMPLOYMENT_ID = 301;
    private const WAGE_INPUT = 9001;
    private const SEVERANCE_INPUT = 9002;
    private const WAGE = 4_000_000;
    private const SEVERANCE = 12_000_000;

    public function testSeveranceIsGarnishedPerMultipleWithoutEmployerFee(): void
    {
        $person = $this->processor()->calculate(
            $this->snapshot(3),
            $this->grossResult(),
        )['people'][0];
        $input = $person['enforcement']['input'];
        $result = $person['enforcement']['result'];

        self::assertSame(self::WAGE, $input['income']['garnishable_minor_units']);
        self::assertSame(
            [4_000_000, 4_000_000, 4_000_000],
            array_column($input['severance_multiples'], 'amount_minor_units'),
        );

        $wage = $this->single(self::WAGE, self::claimBalance());
        $expectedWithheld = $wage->totalWithheldMinorUnits;
        $balance = self::claimBalance() - $wage->allocations[0]->totalMinorUnits;
        for ($multiple = 0; $multiple < 3; $multiple++) {
            $segment = $this->single(4_000_000, $balance);
            // Plná srážka z násobku — paušál z něj plátci nepatří.
            $expectedWithheld += $segment->totalWithheldMinorUnits;
            $balance -= $segment->totalWithheldMinorUnits;
        }

        self::assertSame('supported', $result['status']);
        self::assertSame($expectedWithheld, $result['total_withheld_minor_units']);
        self::assertSame(
            $wage->employerFlatFeeMinorUnits,
            $result['employer_flat_fee_minor_units'],
        );
        self::assertSame(
            $expectedWithheld - $wage->employerFlatFeeMinorUnits,
            $result['allocations'][0]['total_minor_units'],
        );
        // Čtyři nezabavitelné částky, ne jedna.
        self::assertSame(
            4 * $wage->protectedAmountMinorUnits,
            $result['protected_amount_minor_units'],
        );
        // Kontrola proti staré chybě: z celku 160 000 Kč s jedinou
        // nezabavitelnou částkou by se srazilo víc.
        $merged = $this->single(self::WAGE + self::SEVERANCE, self::claimBalance());
        self::assertLessThan(
            $merged->totalWithheldMinorUnits,
            $result['total_withheld_minor_units'],
        );
        self::assertSame(
            self::WAGE + self::SEVERANCE - $expectedWithheld,
            $person['payable_after_enforcement_minor'],
        );
    }

    public function testSeveranceNetCarriesItsShareOfTaxButNoInsurance(): void
    {
        $person = $this->processor()->calculate(
            $this->snapshot(3),
            $this->netResult(),
        )['people'][0];
        $input = $person['enforcement']['input'];

        // Záloha 24 000 Kč se dělí podle základu daně 40 000 : 120 000,
        // pojistné (2 840 + 1 800 Kč) nese jen mzda.
        self::assertSame(
            [3_400_000, 3_400_000, 3_400_000],
            array_column($input['severance_multiples'], 'amount_minor_units'),
        );
        self::assertSame(2_936_000, $input['income']['garnishable_minor_units']);
    }

    public function testMissingMultipleStopsForManualReviewWithTerminationLink(): void
    {
        $person = $this->processor()->calculate(
            $this->snapshot(null),
            $this->grossResult(),
        )['people'][0];

        self::assertSame('manual_review', $person['enforcement']['result']['status']);
        self::assertContains(
            'income:income:employment:' . self::EMPLOYMENT_ID . ':severance-input-'
                . self::SEVERANCE_INPUT . ':severance_period_split_required',
            $person['enforcement']['result']['issues'],
        );
    }

    public function testOtherIncomeDuringSeverancePeriodNeedsConfirmation(): void
    {
        $person = $this->processor()->calculate(
            $this->snapshot(3, ['other_income_from' => '2026-08-10', 'other_payer_applies_protected_amount' => false]),
            $this->grossResult(),
        )['people'][0];

        self::assertSame('manual_review', $person['enforcement']['result']['status']);
        self::assertContains(
            'severance_multiple_other_income_unresolved',
            $person['enforcement']['result']['issues'],
        );
    }

    public function testConfirmedOtherPayerRemovesProtectedAmountOnlyFromOverlappingMultiples(): void
    {
        // Skončení 30. 6., nástup jinam 10. 8. — první násobek (červenec)
        // je samostatný, druhý a třetí (srpen, září) se sčítají s novou mzdou.
        $person = $this->processor()->calculate(
            $this->snapshot(3, ['other_income_from' => '2026-08-10', 'other_payer_applies_protected_amount' => true]),
            $this->grossResult(),
        )['people'][0];
        $input = $person['enforcement']['input'];
        $result = $person['enforcement']['result'];

        self::assertSame(
            [false, true, true],
            array_column($input['severance_multiples'], 'other_income_overlap'),
        );
        self::assertSame('supported', $result['status']);
        $wage = $this->single(self::WAGE, self::claimBalance());
        self::assertSame(
            2 * $wage->protectedAmountMinorUnits,
            $result['protected_amount_minor_units'],
        );
    }

    public function testNoSplitWithoutAnyWithholding(): void
    {
        $person = $this->processor()->calculate(
            $this->snapshot(3, [], false),
            $this->grossResult(),
        )['people'][0];

        self::assertArrayNotHasKey('severance_multiples', $person['enforcement']['input']);
        self::assertSame(
            self::WAGE + self::SEVERANCE,
            $person['enforcement']['input']['income']['garnishable_minor_units'],
        );
    }

    public function testMultiplesSumToTheWholeAmount(): void
    {
        self::assertSame([3_333_334, 3_333_333, 3_333_333], SeveranceMultiple::split(10_000_000, 3));
    }

    private static function claimBalance(): int
    {
        return 50_000_000;
    }

    private function single(int $income, int $balance): GarnishmentResult
    {
        return (new GarnishmentCalculator(CzechPayrollRulesets2026::provider()))->calculate(
            new GarnishmentInput(
                '2026-06',
                '2026-07-15',
                (new GarnishableIncomeResolver())->resolve([
                    new GarnishableIncomeItem('net', GarnishableIncomeKind::Wage, $income, 'payer'),
                ], true),
                [self::claim($balance)],
                0,
                true,
                false,
                true,
                PensionEvidence::None,
                false,
                null,
                InsolvencyInstruction::none(),
                false,
                true,
            ),
        );
    }

    private static function claim(int $balance): DeductionClaim
    {
        return new DeductionClaim(
            id: 'claim-synthetic-severance',
            legalBasis: DeductionLegalBasis::Statutory,
            category: ClaimCategory::NonPriority,
            outstandingMinorUnits: $balance,
            priorityDate: '2026-02-01',
            legalTitleVerified: true,
            orderOrNoticeDelivered: true,
            orderIssuedOn: '2026-01-20',
            priorityClassificationVerified: true,
            dueMonetaryClaimVerified: true,
            enforcementOrderId: 'order-synthetic-severance',
        );
    }

    private function processor(): PayrollRunGarnishmentProcessor
    {
        $port = new class implements PayrollGarnishmentPort {
            public function calculate(EnforcementPersonMonthRequest $request): PayrollGarnishmentCalculation
            {
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

    /**
     * @param array<string,mixed> $facts
     * @return array<string,mixed>
     */
    private function snapshot(?int $multiple, array $facts = [], bool $withClaim = true): array
    {
        $evidence = new EnforcementPersonMonthEvidence(
            claims: $withClaim ? [self::claim(self::claimBalance())] : [],
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
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 1,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'payment_date' => '2026-07-15',
            'people' => [[
                'employee' => ['id' => self::EMPLOYEE_ID],
                'enforcement_evidence' => $evidence->toCanonicalArray(),
                'employments' => [[
                    'employment' => ['id' => self::EMPLOYMENT_ID, 'end_date' => '2026-06-30'],
                    'inputs' => [
                        [
                            'id' => self::WAGE_INPUT,
                            'amount_minor' => self::WAGE,
                            'quantity_milliunits' => null,
                            'component' => ['code' => 'MZDA_MESICNI', 'component_kind' => 'base_wage'],
                        ],
                        [
                            'id' => self::SEVERANCE_INPUT,
                            'amount_minor' => self::SEVERANCE,
                            'quantity_milliunits' => $multiple === null ? null : $multiple * 1000,
                            'component' => ['code' => 'ODSTUPNE', 'component_kind' => 'severance'],
                        ],
                    ],
                    ...($facts === [] ? [] : ['severance_garnishment' => $facts]),
                ]],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function grossResult(): array
    {
        $person = [
            'employee_id' => self::EMPLOYEE_ID,
            'employments' => [[
                'employment_id' => self::EMPLOYMENT_ID,
                'inputs' => [
                    ['input_id' => self::WAGE_INPUT, 'component_code' => 'MZDA_MESICNI', 'totals' => [
                        'cash_payable_minor' => self::WAGE,
                        'enforcement_base_minor' => self::WAGE,
                        'tax_base_minor' => self::WAGE,
                        'social_base_minor' => self::WAGE,
                        'health_base_minor' => self::WAGE,
                    ]],
                    ['input_id' => self::SEVERANCE_INPUT, 'component_code' => 'ODSTUPNE', 'totals' => [
                        'cash_payable_minor' => self::SEVERANCE,
                        'enforcement_base_minor' => self::SEVERANCE,
                        'tax_base_minor' => self::SEVERANCE,
                        'social_base_minor' => 0,
                        'health_base_minor' => 0,
                    ]],
                ],
            ]],
            'totals' => [
                'cash_payable_minor' => self::WAGE + self::SEVERANCE,
                'enforcement_base_minor' => self::WAGE + self::SEVERANCE,
                'tax_base_minor' => self::WAGE + self::SEVERANCE,
                'social_base_minor' => self::WAGE,
                'health_base_minor' => self::WAGE,
            ],
        ];

        return [
            'schema_version' => 'payroll-run-result.v1',
            'people' => [$person],
            'totals' => [
                'cash_payable_minor' => self::WAGE + self::SEVERANCE,
                'enforcement_base_minor' => self::WAGE + self::SEVERANCE,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function netResult(): array
    {
        $result = $this->grossResult();
        $net = self::WAGE + self::SEVERANCE - 284_000 - 180_000 - 2_400_000;
        $result['statutory'] = ['status' => 'calculated'];
        $result['people'][0]['statutory'] = [
            'person_reference' => 'employee:' . self::EMPLOYEE_ID,
            'status' => 'calculated',
            'net_payable_minor_units' => $net,
            'net_pay' => [
                'employee_social_minor_units' => 284_000,
                'employee_health_minor_units' => 180_000,
                'advance_tax_minor_units' => 2_400_000,
                'withholding_tax_minor_units' => 0,
                'net_before_deductions_minor_units' => $net,
                'deducted_minor_units' => 0,
                'net_payable_minor_units' => $net,
                'deductions' => [],
            ],
        ];

        return $result;
    }
}
