<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\PayrollSheetDocumentData;
use MyInvoice\Service\Payroll\Document\PayrollSheetMonth;
use MyInvoice\Service\Payroll\Document\PayrollSheetSnapshotBuilder;
use PHPUnit\Framework\TestCase;

/**
 * § 38j odst. 2 písm. f) bod 6 ZDP — mzdový list musí za každý měsíc uvést
 * „měsíční daňové zvýhodnění, měsíční slevu na dani podle § 35c, měsíční daňový
 * bonus a zálohu sníženou o měsíční slevu na dani podle § 35ba a 35c".
 *
 * Jsou to čtyři údaje. Daňové zvýhodnění je NÁROK podle § 35c odst. 1, sleva
 * jen jeho část, která se vešla do daně (§ 35c odst. 2), a bonus část náležející
 * podle § 35c odst. 3. Doklad, který uvádí jen uplatněné částky, zamlčí nárok,
 * který se nevešel do daně a zároveň na něj nevznikl bonus.
 */
final class PayrollSheetChildBenefitTest extends TestCase
{
    /** Nárok se z uplatněné slevy dopočítat nedá — musí se číst ze zmrazeného výsledku. */
    public function testClaimedEntitlementIsReadFromTheFrozenTaxResult(): void
    {
        $amounts = $this->personAmounts(
            claimedChildCredit: 1_820_00,
            appliedChildCredit: 400_00,
            taxBonus: 0,
        );

        self::assertSame(1_820_00, $amounts['child_entitlement_minor_units']);
        self::assertSame(400_00, $amounts['child_credit_minor_units']);
    }

    /**
     * Nárokované, ale neuplatněné zvýhodnění: záloha nestačila a na bonus
     * poplatník nárok nemá. Doklad musí ukázat obě čísla, jinak z něj rozdíl
     * není poznat.
     */
    public function testClaimedButUnappliedBenefitStaysVisibleOnTheMonth(): void
    {
        $month = $this->month(
            childEntitlement: 1_820_00,
            childCredit: 400_00,
            taxBonus: 0,
        );

        self::assertSame(1_820_00, $month->childEntitlementMinorUnits);
        self::assertSame(400_00, $month->childCreditMinorUnits);
        self::assertSame(0, $month->taxBonusMinorUnits);
        self::assertTrue($month->childDetailRecorded());
        self::assertSame(
            1_820_00,
            $month->toTemplateData()['child_entitlement_minor_units'],
        );
    }

    /** Sleva a bonus jsou části nároku, nikdy víc než on. */
    public function testAppliedPartsCannotExceedTheEntitlement(): void
    {
        $this->expectExceptionMessage(
            'Uplatněná sleva a bonus převyšují měsíční daňové zvýhodnění.',
        );
        $this->month(childEntitlement: 400_00, childCredit: 400_00, taxBonus: 1_00);
    }

    public function testNotRecordedMonthMustNotCarryAnEntitlement(): void
    {
        $this->expectExceptionMessage(
            'Neevidované daňové zvýhodnění měsíce nesmí nést částku.',
        );
        $this->month(
            childEntitlement: 100_00,
            childCredit: 0,
            taxBonus: 0,
            status: PayrollSheetMonth::CHILD_DETAIL_NOT_RECORDED,
        );
    }

    /**
     * Revize vydaná pod mapováním v2 nárok neevidovala. Nedopočítává se —
     * uplatněná sleva mu je rovna jen tehdy, když se celý nárok vešel do daně.
     */
    public function testOlderMappingHydratesTheEntitlementAsNotRecorded(): void
    {
        $document = $this->hydrate('payroll-sheet-document.v2');

        self::assertFalse($document->months[0]->childDetailRecorded());
        self::assertSame(0, $document->months[0]->childEntitlementMinorUnits);
        self::assertFalse($document->childDetailComplete());
        self::assertFalse($document->toTemplateData()['child_detail_complete']);
        // Bod 2 a 3 v2 evidovala, takže se zpět na „neevidováno" nesmí propadnout.
        self::assertTrue($document->months[0]->taxDetailRecorded());
    }

    public function testCurrentMappingHydratesTheEntitlement(): void
    {
        $document = $this->hydrate(PayrollSheetSnapshotBuilder::SCHEMA_VERSION);

        self::assertTrue($document->childDetailComplete());
        self::assertSame(1_820_00, $document->months[0]->childEntitlementMinorUnits);
        self::assertSame(1_820_00, $document->totals()['child_entitlement_minor_units']);
    }

    /** @return array<string,int> */
    private function personAmounts(
        int $claimedChildCredit,
        int $appliedChildCredit,
        int $taxBonus,
    ): array {
        $builder = (new \ReflectionClass(PayrollSheetSnapshotBuilder::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PayrollSheetSnapshotBuilder::class, 'personAmounts');
        $amounts = $method->invoke($builder, [
            'payslip_document' => [
                'gross_minor_units' => 30_000_00,
                'employer_social_minor_units' => 0,
            ],
            'statutory' => [
                'status' => 'calculated',
                'social_insurance' => [
                    'capped_assessment_base_minor_units' => 30_000_00,
                    'employee_contribution_minor_units' => 0,
                ],
                'health_insurance' => [
                    'assessment_base_minor_units' => 30_000_00,
                    'employee_contribution_minor_units' => 0,
                    'employer_contribution_minor_units' => 0,
                    'employee_minimum_top_up_minor_units' => 0,
                ],
                'income_tax' => [
                    'advance_tax' => [
                        'rounded_tax_base_minor_units' => 30_000_00,
                        'tax_before_credits_minor_units' => 4_500_00,
                        'non_refundable_credits_minor_units' => 2_570_00,
                        'child_credit_minor_units' => $appliedChildCredit,
                    ],
                    'withholding_base_minor_units' => 0,
                    'claimed_child_credit_minor_units' => $claimedChildCredit,
                ],
                'net_pay' => [
                    'cash_income_minor_units' => 30_000_00,
                    'non_cash_income_minor_units' => 0,
                    'advance_tax_minor_units' => 0,
                    'tax_bonus_minor_units' => $taxBonus,
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
            'payable_after_enforcement_minor' => 30_000_00 + $taxBonus,
        ]);
        self::assertIsArray($amounts);

        return $amounts;
    }

    private function month(
        int $childEntitlement,
        int $childCredit,
        int $taxBonus,
        string $status = PayrollSheetMonth::CHILD_DETAIL_RECORDED,
    ): PayrollSheetMonth {
        return new PayrollSheetMonth(
            month: 3,
            sourceRevisionCount: 1,
            grossMinorUnits: 30_000_00,
            cashIncomeMinorUnits: 30_000_00,
            nonCashIncomeMinorUnits: 0,
            socialAssessmentBaseMinorUnits: 30_000_00,
            employeeSocialMinorUnits: 0,
            employerSocialMinorUnits: 0,
            healthAssessmentBaseMinorUnits: 30_000_00,
            employeeHealthMinorUnits: 0,
            employerHealthMinorUnits: 0,
            healthMinimumTopUpMinorUnits: 0,
            advanceTaxBaseMinorUnits: 30_000_00,
            advanceTaxBeforeCreditsMinorUnits: 4_500_00,
            nonRefundableCreditsMinorUnits: 2_570_00,
            childCreditMinorUnits: $childCredit,
            advanceTaxMinorUnits: 0,
            taxBonusMinorUnits: $taxBonus,
            withholdingTaxMinorUnits: 0,
            otherDeductionsMinorUnits: 0,
            netPayableMinorUnits: 30_000_00 + $taxBonus,
            taxDetailStatus: PayrollSheetMonth::TAX_DETAIL_RECORDED,
            childEntitlementMinorUnits: $childEntitlement,
            childDetailStatus: $status,
        );
    }

    private function hydrate(string $schemaVersion): PayrollSheetDocumentData
    {
        $month = [
            'month' => 3,
            'source_revision_count' => 1,
            'gross_minor_units' => 30_000_00,
            'cash_income_minor_units' => 30_000_00,
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
            'child_credit_minor_units' => 400_00,
            'advance_tax_minor_units' => 0,
            'tax_bonus_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'other_deductions_minor_units' => 0,
            'annual_settlement_minor_units' => 0,
            'net_payable_minor_units' => 30_000_00,
            'tax_exempt_income_minor_units' => 0,
            'withholding_tax_base_minor_units' => 0,
        ];
        if ($schemaVersion === PayrollSheetSnapshotBuilder::SCHEMA_VERSION) {
            $month['child_entitlement_minor_units'] = 1_820_00;
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
