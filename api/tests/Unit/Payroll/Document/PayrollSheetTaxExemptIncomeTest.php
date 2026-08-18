<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\PayrollSheetDocumentData;
use MyInvoice\Service\Payroll\Document\PayrollSheetMonth;
use MyInvoice\Service\Payroll\Document\PayrollSheetSnapshotBuilder;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\TestCase;

/**
 * § 38j odst. 2 písm. f) bod 2 ZDP — mzdový list musí za každý kalendářní měsíc
 * uvést částky osvobozené od daně z úhrnu zúčtovaných mezd.
 */
final class PayrollSheetTaxExemptIncomeTest extends TestCase
{
    private const EMPLOYEE_ID = 7;

    public function testExemptComponentIsReportedForTheMonth(): void
    {
        $months = $this->months([
            $this->source('2026-03-01', [
                $this->input(1, 900_00, 'included'),
                $this->input(2, 100_00, 'exempt'),
            ]),
        ]);

        self::assertCount(1, $months);
        self::assertSame(100_00, $months[0]->taxExemptIncomeMinorUnits);
        self::assertSame(1_000_00, $months[0]->grossMinorUnits);
        self::assertTrue($months[0]->taxDetailRecorded());
    }

    /**
     * Nadlimitní část benefitu se zdaňuje. Doklad musí ukázat právě tu část,
     * kterou mzdový běh zmrazil jako osvobozenou — ne celé plnění a ne dopočet.
     */
    public function testOverLimitBenefitReportsOnlyTheFrozenExemptPart(): void
    {
        $months = $this->months([
            $this->source('2026-04-01', [
                $this->input(1, 900_00, 'included'),
                $this->input(2, 100_00, 'exempt', [
                    'benefit_basket' => 'non_cash_leisure',
                    'benefit_exempt_minor' => 60_00,
                    'benefit_taxable_minor' => 40_00,
                ]),
            ]),
        ]);

        self::assertSame(60_00, $months[0]->taxExemptIncomeMinorUnits);
        self::assertSame(1_000_00, $months[0]->grossMinorUnits);
    }

    public function testFrozenSplitThatDoesNotAddUpFailsClosed(): void
    {
        $this->expectExceptionMessage('nedává částku vstupu');
        $this->months([
            $this->source('2026-04-01', [
                $this->input(1, 1_000_00, 'exempt', [
                    'benefit_basket' => 'non_cash_leisure',
                    'benefit_exempt_minor' => 60_00,
                    'benefit_taxable_minor' => 30_00,
                ]),
            ]),
        ]);
    }

    /**
     * Cestovní náhrada do zákonného limitu PŘEDMĚTEM DANĚ NENÍ (§ 6 odst. 7
     * písm. a) ZDP), takže mezi „částky osvobozené od daně" podle § 38j odst. 2
     * písm. f) bodu 2 nepatří — přestože se v úhrnu zúčtovaných mezd objeví.
     */
    public function testAmountOutsideTheScopeOfTaxIsNotReportedAsExempt(): void
    {
        $months = $this->months([
            $this->source('2026-04-01', [
                $this->input(1, 900_00, 'included'),
                $this->input(2, 100_00, 'exempt', [
                    'exemption_basis' => 'not_subject_to_tax',
                ]),
            ]),
        ]);

        self::assertSame(0, $months[0]->taxExemptIncomeMinorUnits);
        self::assertSame(1_000_00, $months[0]->grossMinorUnits);
    }

    /** Osvobození bez podkladu doklad netvrdí — brána běhu ho nepustila. */
    public function testExemptComponentWithoutBasisFailsClosed(): void
    {
        $this->expectExceptionMessage('Podklad osvobození složky vstupu 1 není uveden.');
        $this->months([
            $this->source('2026-04-01', [
                $this->input(1, 1_000_00, 'exempt', ['exemption_basis' => null]),
            ]),
        ]);
    }

    public function testMonthWithoutExemptIncomeReportsZeroNotBlank(): void
    {
        $months = $this->months([
            $this->source('2026-05-01', [$this->input(1, 1_000_00, 'included')]),
        ]);

        self::assertSame(0, $months[0]->taxExemptIncomeMinorUnits);
        self::assertSame(
            PayrollSheetMonth::TAX_DETAIL_RECORDED,
            $months[0]->taxDetailStatus,
        );
        self::assertSame(0, $months[0]->toTemplateData()['tax_exempt_income_minor_units']);
    }

    public function testUnclassifiedComponentFailsClosedInsteadOfReportingZero(): void
    {
        $this->expectExceptionMessage('Daňové zacházení složky vstupu 1 není uzavřené.');
        $this->months([
            $this->source('2026-06-01', [$this->input(1, 1_000_00, 'manual_review')]),
        ]);
    }

    public function testExemptAmountsSumAcrossRevisionsOfTheSameMonth(): void
    {
        $months = $this->months([
            $this->source('2026-07-01', [$this->input(1, 1_000_00, 'exempt')]),
            $this->source('2026-07-01', [$this->input(2, 500_00, 'exempt')]),
        ]);

        self::assertCount(1, $months);
        self::assertSame(2, $months[0]->sourceRevisionCount);
        self::assertSame(1_500_00, $months[0]->taxExemptIncomeMinorUnits);
    }

    /** Oprava osvobozeného plnění za minulé období je záporná složka. */
    public function testNegativeCorrectionLowersTheExemptAmount(): void
    {
        $months = $this->months([
            $this->source('2026-08-01', [
                $this->input(1, 1_000_00, 'included'),
                $this->input(2, 200_00, 'exempt'),
                $this->input(3, -50_00, 'exempt'),
            ]),
        ]);

        self::assertSame(1_150_00, $months[0]->grossMinorUnits);
        self::assertSame(150_00, $months[0]->taxExemptIncomeMinorUnits);
    }

    /** § 38j odst. 2 písm. e) — den nástupu do zaměstnání. */
    public function testFrozenEmploymentCarriesTheStartingDay(): void
    {
        $builder = $this->builder();
        $method = new \ReflectionMethod(PayrollSheetSnapshotBuilder::class, 'months');
        [, , $employments] = $method->invoke($builder, [
            $this->source('2026-03-01', [$this->input(1, 1_000_00, 'included')]),
        ], self::EMPLOYEE_ID);

        self::assertSame([[
            'code' => 'HPP-1',
            'relation_type' => 'employment',
            'start_date' => '2026-01-15',
            'actual_start_date' => '2026-01-16',
            'end_date' => null,
        ]], $employments);
    }

    /**
     * Revize vydaná pod starším mapováním se zpětně nemění a nedopočítává —
     * hydratuje se jako neevidovaný údaj s pojmenovaným důvodem.
     */
    public function testOlderRevisionHydratesAsNotRecorded(): void
    {
        $document = $this->hydrate('payroll-sheet-document.v1', false);

        self::assertSame(
            PayrollSheetMonth::TAX_DETAIL_NOT_RECORDED,
            $document->months[0]->taxDetailStatus,
        );
        self::assertSame(0, $document->months[0]->taxExemptIncomeMinorUnits);
        self::assertSame(0, $document->months[0]->withholdingTaxBaseMinorUnits);
        self::assertFalse($document->taxDetailComplete());
        self::assertFalse($document->toTemplateData()['tax_detail_complete']);
    }

    public function testCurrentRevisionHydratesAsRecorded(): void
    {
        $document = $this->hydrate(PayrollSheetSnapshotBuilder::SCHEMA_VERSION, true);

        self::assertTrue($document->taxDetailComplete());
        self::assertSame(30_00, $document->months[0]->taxExemptIncomeMinorUnits);
        self::assertSame(20_00, $document->months[0]->withholdingTaxBaseMinorUnits);
    }

    public function testNotRecordedMonthMustNotCarryAnAmount(): void
    {
        $this->expectExceptionMessage('Neevidované daňové údaje měsíce nesmí nést částku.');
        new PayrollSheetMonth(
            month: 1,
            sourceRevisionCount: 1,
            grossMinorUnits: 1_000_00,
            cashIncomeMinorUnits: 1_000_00,
            nonCashIncomeMinorUnits: 0,
            socialAssessmentBaseMinorUnits: 0,
            employeeSocialMinorUnits: 0,
            employerSocialMinorUnits: 0,
            healthAssessmentBaseMinorUnits: 0,
            employeeHealthMinorUnits: 0,
            employerHealthMinorUnits: 0,
            healthMinimumTopUpMinorUnits: 0,
            advanceTaxBaseMinorUnits: 0,
            advanceTaxBeforeCreditsMinorUnits: 0,
            nonRefundableCreditsMinorUnits: 0,
            childCreditMinorUnits: 0,
            advanceTaxMinorUnits: 0,
            taxBonusMinorUnits: 0,
            withholdingTaxMinorUnits: 0,
            otherDeductionsMinorUnits: 0,
            netPayableMinorUnits: 1_000_00,
            taxExemptIncomeMinorUnits: 10_00,
            taxDetailStatus: PayrollSheetMonth::TAX_DETAIL_NOT_RECORDED,
        );
    }

    public function testExemptAmountCannotExceedTheReportedGross(): void
    {
        $this->expectExceptionMessage('Osvobozená částka převyšuje úhrn zúčtovaných mezd.');
        new PayrollSheetMonth(
            month: 1,
            sourceRevisionCount: 1,
            grossMinorUnits: 1_000_00,
            cashIncomeMinorUnits: 1_000_00,
            nonCashIncomeMinorUnits: 0,
            socialAssessmentBaseMinorUnits: 0,
            employeeSocialMinorUnits: 0,
            employerSocialMinorUnits: 0,
            healthAssessmentBaseMinorUnits: 0,
            employeeHealthMinorUnits: 0,
            employerHealthMinorUnits: 0,
            healthMinimumTopUpMinorUnits: 0,
            advanceTaxBaseMinorUnits: 0,
            advanceTaxBeforeCreditsMinorUnits: 0,
            nonRefundableCreditsMinorUnits: 0,
            childCreditMinorUnits: 0,
            advanceTaxMinorUnits: 0,
            taxBonusMinorUnits: 0,
            withholdingTaxMinorUnits: 0,
            otherDeductionsMinorUnits: 0,
            netPayableMinorUnits: 1_000_00,
            taxExemptIncomeMinorUnits: 1_000_01,
            taxDetailStatus: PayrollSheetMonth::TAX_DETAIL_RECORDED,
        );
    }

    /** @return list<PayrollSheetMonth> */
    private function months(array $sources): array
    {
        $method = new \ReflectionMethod(PayrollSheetSnapshotBuilder::class, 'months');
        [$months] = $method->invoke($this->builder(), $sources, self::EMPLOYEE_ID);

        return $months;
    }

    private function builder(): PayrollSheetSnapshotBuilder
    {
        return (new \ReflectionClass(PayrollSheetSnapshotBuilder::class))
            ->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function input(int $id, int $amountMinor, string $taxTreatment, array $extra = []): array
    {
        $component = [
            'code' => 'SLOZKA' . $id,
            'tax_treatment' => $taxTreatment,
        ];
        if ($taxTreatment === 'exempt') {
            // Osvobození bez uvedeného podkladu se do schválené revize dostat
            // nemělo; doklad ho proto odmítne vykázat.
            $component['exemption_basis'] = array_key_exists('exemption_basis', $extra)
                ? $extra['exemption_basis']
                : (isset($extra['benefit_basket']) ? 'benefit_basket' : 'statutory_exempt');
        }
        unset($extra['exemption_basis']);

        return [
            'id' => $id,
            'amount_minor' => $amountMinor,
            'component' => $component,
        ] + $extra;
    }

    /**
     * @param list<array<string,mixed>> $inputs
     * @return array<string,mixed>
     */
    private function source(string $periodStart, array $inputs): array
    {
        $gross = 0;
        $inputResults = [];
        foreach ($inputs as $input) {
            $gross += (int) $input['amount_minor'];
            $inputResults[] = [
                'input_id' => $input['id'],
                'totals' => ['source_amount_minor' => $input['amount_minor']],
            ];
        }
        $person = [
            'employee_id' => self::EMPLOYEE_ID,
            'payslip_document' => [
                'gross_minor_units' => $gross,
                'employer_social_minor_units' => 0,
            ],
            'statutory' => [
                'status' => 'calculated',
                'social_insurance' => [
                    'capped_assessment_base_minor_units' => 0,
                    'employee_contribution_minor_units' => 0,
                ],
                'health_insurance' => [
                    'assessment_base_minor_units' => 0,
                    'employee_contribution_minor_units' => 0,
                    'employer_contribution_minor_units' => 0,
                    'employee_minimum_top_up_minor_units' => 0,
                ],
                'income_tax' => [
                    'advance_tax' => null,
                    'withholding_base_minor_units' => 0,
                    'claimed_child_credit_minor_units' => 0,
                ],
                'net_pay' => [
                    'cash_income_minor_units' => $gross,
                    'non_cash_income_minor_units' => 0,
                    'advance_tax_minor_units' => 0,
                    'tax_bonus_minor_units' => 0,
                    'withholding_tax_minor_units' => 0,
                    'deducted_minor_units' => 0,
                ],
            ],
            'enforcement' => [
                'result' => [
                    'status' => 'supported',
                    'total_withheld_minor_units' => 0,
                ],
            ],
            'payable_after_enforcement_minor' => $gross,
            'employments' => [[
                'employment_id' => 11,
                'inputs' => $inputResults,
            ]],
        ];
        $inputSnapshot = [
            'schema_version' => 'payroll-run-input.v2',
            'people' => [[
                'employee' => ['id' => self::EMPLOYEE_ID],
                'employments' => [[
                    'employment' => [
                        'id' => 11,
                        'code' => 'HPP-1',
                        'relation_type' => 'employment',
                        'start_date' => '2026-01-15',
                        'actual_start_date' => '2026-01-16',
                        'end_date' => null,
                    ],
                    'inputs' => $inputs,
                ]],
            ]],
        ];
        $personJson = CanonicalJson::encode($person);
        $resultJson = CanonicalJson::encode(['people' => [$person]]);
        $inputJson = CanonicalJson::encode($inputSnapshot);

        return [
            'period_start' => $periodStart,
            'run_id' => 1,
            'revision_id' => 1,
            'input_snapshot_json' => $inputJson,
            'input_snapshot_hash' => hash('sha256', $inputJson),
            'result_snapshot_json' => $resultJson,
            'result_snapshot_hash' => hash('sha256', $resultJson),
            'person_result_json' => $personJson,
            'person_result_hash' => hash('sha256', $personJson),
        ];
    }

    private function hydrate(string $schemaVersion, bool $withTaxDetail): PayrollSheetDocumentData
    {
        $month = [
            'month' => 1,
            'source_revision_count' => 1,
            'gross_minor_units' => 1_000_00,
            'cash_income_minor_units' => 1_000_00,
            'non_cash_income_minor_units' => 0,
            'social_assessment_base_minor_units' => 0,
            'employee_social_minor_units' => 0,
            'employer_social_minor_units' => 0,
            'health_assessment_base_minor_units' => 0,
            'employee_health_minor_units' => 0,
            'employer_health_minor_units' => 0,
            'health_minimum_top_up_minor_units' => 0,
            'advance_tax_base_minor_units' => 0,
            'advance_tax_before_credits_minor_units' => 0,
            'non_refundable_credits_minor_units' => 0,
            'child_credit_minor_units' => 0,
            'advance_tax_minor_units' => 0,
            'tax_bonus_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'other_deductions_minor_units' => 0,
            'annual_settlement_minor_units' => 0,
            'net_payable_minor_units' => 1_000_00,
        ];
        if ($withTaxDetail) {
            $month['tax_exempt_income_minor_units'] = 30_00;
            $month['withholding_tax_base_minor_units'] = 20_00;
            $month['child_entitlement_minor_units'] = 0;
        }
        $snapshot = [
            'schema_version' => $schemaVersion,
            'tax_year' => 2026,
            'employer' => [
                'name' => 'Zaměstnavatel s.r.o.',
                'identification_number' => '12345678',
                'address' => 'Ulice 1, 110 00 Praha',
            ],
            'employee' => [
                'name' => 'Jan Novák',
                'previous_names' => [],
                'identifier_label' => 'Rodné číslo',
                'identifier_value' => '000000/0000',
                'address' => 'Ulice 2, 110 00 Praha, CZ',
            ],
            'months' => [$month],
            'annual_settlement_status' => 'not_performed',
        ];
        $method = new \ReflectionMethod(PayrollSheetSnapshotBuilder::class, 'hydrate');
        $document = $method->invoke($this->builder(), $snapshot, str_repeat('a', 64));
        self::assertInstanceOf(PayrollSheetDocumentData::class, $document);

        return $document;
    }
}
