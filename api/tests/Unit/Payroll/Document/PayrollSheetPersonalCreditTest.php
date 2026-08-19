<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\PayrollSheetDocumentData;
use MyInvoice\Service\Payroll\Document\PayrollSheetMonth;
use MyInvoice\Service\Payroll\Document\PayrollSheetSnapshotBuilder;
use PHPUnit\Framework\TestCase;

/**
 * § 38j odst. 2 písm. f) bod 5 ZDP — mzdový list musí za každý měsíc uvést
 * „měsíční slevu na dani podle § 35ba a zálohu sníženou o měsíční slevu na dani
 * podle § 35ba".
 *
 * Jsou to dva údaje, ne tři. „Měsíční sleva na dani podle § 35ba" je legální
 * zkratka zavedená v § 35d odst. 2 a hned v odst. 3 větě první omezená: plátce
 * ji poskytne „maximálně do výše zálohy na daň vypočtené podle § 38h odst. 2
 * a 3". Nárok nad tuhle hranici zákon nepojmenovává a nikam ho nepřevádí —
 * na rozdíl od § 35c, kde přebytek končí měsíčním daňovým bonusem, a právě
 * proto bod 6 vypisuje nárok i uplatnění odděleně.
 */
final class PayrollSheetPersonalCreditTest extends TestCase
{
    /**
     * Daň 1 800 Kč nepokryje měsíční slevu 2 570 Kč. Doklad smí vykázat jen
     * poskytnutou část; s nárokem by druhá polovina bodu 5 („záloha snížená
     * o měsíční slevu") vyšla záporná.
     */
    public function testMonthlyCreditIsTheAppliedAmountNotTheClaim(): void
    {
        $amounts = $this->personAmounts(
            taxBeforeCredits: 1_800_00,
            claimedCredit: 2_570_00,
            appliedCredit: 1_800_00,
        );

        self::assertSame(1_800_00, $amounts['non_refundable_credits_minor_units']);
        self::assertSame(
            $amounts['advance_tax_before_credits_minor_units'],
            $amounts['non_refundable_credits_minor_units']
                + $amounts['advance_tax_minor_units'],
        );
    }

    /** Když daň slevu unese, obě čísla splynou a nic se nemění. */
    public function testFullyCoveredCreditStaysUnchanged(): void
    {
        $amounts = $this->personAmounts(
            taxBeforeCredits: 4_500_00,
            claimedCredit: 2_570_00,
            appliedCredit: 2_570_00,
        );

        self::assertSame(2_570_00, $amounts['non_refundable_credits_minor_units']);
    }

    /** Uplatněné slevy jsou části zálohy, nikdy víc než ona (§ 35d odst. 3). */
    public function testAppliedCreditsCannotExceedTheAdvanceBeforeCredits(): void
    {
        $this->expectExceptionMessage(
            'Uplatněné slevy převyšují zálohu na daň před slevami.',
        );
        $this->month(
            advanceTaxBeforeCredits: 1_800_00,
            nonRefundableCredits: 2_570_00,
            advanceTax: 0,
        );
    }

    /**
     * Revize vydaná pod mapováním v3 do kolonky zapisovala nárok. Zpětně se
     * nepřepočítává — zmrazený snapshot je závazný obsah vydané revize.
     */
    public function testOlderMappingHydratesTheCreditAsClaimed(): void
    {
        $document = $this->hydrate('payroll-sheet-document.v3');

        self::assertFalse($document->months[0]->creditDetailApplied());
        self::assertFalse($document->creditDetailComplete());
        self::assertFalse($document->toTemplateData()['credit_detail_complete']);
        // v3 už nárok na zvýhodnění i stav ročního zúčtování evidovala.
        self::assertTrue($document->months[0]->childDetailRecorded());
        self::assertSame('not_performed', $document->annualSettlementStatus);
    }

    public function testCurrentMappingHydratesTheCreditAsApplied(): void
    {
        $document = $this->hydrate(PayrollSheetSnapshotBuilder::SCHEMA_VERSION);

        self::assertTrue($document->months[0]->creditDetailApplied());
        self::assertTrue($document->creditDetailComplete());
        self::assertSame(
            'applied',
            $document->months[0]->toTemplateData()['credit_detail_status'],
        );
    }

    /** @return array<string,int> */
    private function personAmounts(
        int $taxBeforeCredits,
        int $claimedCredit,
        int $appliedCredit,
    ): array {
        $builder = (new \ReflectionClass(PayrollSheetSnapshotBuilder::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PayrollSheetSnapshotBuilder::class, 'personAmounts');
        $amounts = $method->invoke($builder, [
            'payslip_document' => [
                'gross_minor_units' => 12_000_00,
                'employer_social_minor_units' => 0,
            ],
            'statutory' => [
                'status' => 'calculated',
                'social_insurance' => [
                    'capped_assessment_base_minor_units' => 12_000_00,
                    'employee_contribution_minor_units' => 0,
                ],
                'health_insurance' => [
                    'assessment_base_minor_units' => 12_000_00,
                    'employee_contribution_minor_units' => 0,
                    'employer_contribution_minor_units' => 0,
                    'employee_minimum_top_up_minor_units' => 0,
                ],
                'income_tax' => [
                    'advance_tax' => [
                        'rounded_tax_base_minor_units' => 12_000_00,
                        'tax_before_credits_minor_units' => $taxBeforeCredits,
                        // Záloha si sem odkládá NÁROK, se kterým počítala —
                        // viz `MonthlyAdvanceTaxCalculator`.
                        'non_refundable_credits_minor_units' => $claimedCredit,
                        'child_credit_minor_units' => 0,
                    ],
                    'withholding_base_minor_units' => 0,
                    'claimed_non_refundable_credits_minor_units' => $claimedCredit,
                    'applied_non_refundable_credits_minor_units' => $appliedCredit,
                    'claimed_child_credit_minor_units' => 0,
                    'applied_child_credit_minor_units' => 0,
                ],
                'net_pay' => [
                    'cash_income_minor_units' => 12_000_00,
                    'non_cash_income_minor_units' => 0,
                    'advance_tax_minor_units' => $taxBeforeCredits - $appliedCredit,
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
            'payable_after_enforcement_minor' => 12_000_00 - ($taxBeforeCredits - $appliedCredit),
        ]);
        self::assertIsArray($amounts);

        return $amounts;
    }

    private function month(
        int $advanceTaxBeforeCredits,
        int $nonRefundableCredits,
        int $advanceTax,
    ): PayrollSheetMonth {
        return new PayrollSheetMonth(
            month: 3,
            sourceRevisionCount: 1,
            grossMinorUnits: 12_000_00,
            cashIncomeMinorUnits: 12_000_00,
            nonCashIncomeMinorUnits: 0,
            socialAssessmentBaseMinorUnits: 12_000_00,
            employeeSocialMinorUnits: 0,
            employerSocialMinorUnits: 0,
            healthAssessmentBaseMinorUnits: 12_000_00,
            employeeHealthMinorUnits: 0,
            employerHealthMinorUnits: 0,
            healthMinimumTopUpMinorUnits: 0,
            advanceTaxBaseMinorUnits: 12_000_00,
            advanceTaxBeforeCreditsMinorUnits: $advanceTaxBeforeCredits,
            nonRefundableCreditsMinorUnits: $nonRefundableCredits,
            childCreditMinorUnits: 0,
            advanceTaxMinorUnits: $advanceTax,
            taxBonusMinorUnits: 0,
            withholdingTaxMinorUnits: 0,
            otherDeductionsMinorUnits: 0,
            netPayableMinorUnits: 12_000_00 - $advanceTax,
            taxDetailStatus: PayrollSheetMonth::TAX_DETAIL_RECORDED,
            childEntitlementMinorUnits: 0,
            childDetailStatus: PayrollSheetMonth::CHILD_DETAIL_RECORDED,
            creditDetailStatus: PayrollSheetMonth::CREDIT_DETAIL_APPLIED,
        );
    }

    private function hydrate(string $schemaVersion): PayrollSheetDocumentData
    {
        $month = [
            'month' => 3,
            'source_revision_count' => 1,
            'gross_minor_units' => 12_000_00,
            'cash_income_minor_units' => 12_000_00,
            'non_cash_income_minor_units' => 0,
            'social_assessment_base_minor_units' => 0,
            'employee_social_minor_units' => 0,
            'employer_social_minor_units' => 0,
            'health_assessment_base_minor_units' => 0,
            'employee_health_minor_units' => 0,
            'employer_health_minor_units' => 0,
            'health_minimum_top_up_minor_units' => 0,
            'advance_tax_base_minor_units' => 12_000_00,
            'advance_tax_before_credits_minor_units' => 1_800_00,
            'non_refundable_credits_minor_units' => 1_800_00,
            'child_entitlement_minor_units' => 0,
            'child_credit_minor_units' => 0,
            'advance_tax_minor_units' => 0,
            'tax_bonus_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'other_deductions_minor_units' => 0,
            'annual_settlement_minor_units' => 0,
            'net_payable_minor_units' => 12_000_00,
            'tax_exempt_income_minor_units' => 0,
            'withholding_tax_base_minor_units' => 0,
        ];
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
        $document = $method->invoke(
            (new \ReflectionClass(PayrollSheetSnapshotBuilder::class))
                ->newInstanceWithoutConstructor(),
            $snapshot,
            str_repeat('c', 64),
        );
        self::assertInstanceOf(PayrollSheetDocumentData::class, $document);

        return $document;
    }
}
