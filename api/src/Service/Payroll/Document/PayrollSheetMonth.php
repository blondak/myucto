<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class PayrollSheetMonth
{
    public function __construct(
        public int $month,
        public int $sourceRevisionCount,
        public int $grossMinorUnits,
        public int $cashIncomeMinorUnits,
        public int $nonCashIncomeMinorUnits,
        public int $socialAssessmentBaseMinorUnits,
        public int $employeeSocialMinorUnits,
        public int $employerSocialMinorUnits,
        public int $healthAssessmentBaseMinorUnits,
        public int $employeeHealthMinorUnits,
        public int $employerHealthMinorUnits,
        public int $healthMinimumTopUpMinorUnits,
        public int $advanceTaxBaseMinorUnits,
        public int $advanceTaxBeforeCreditsMinorUnits,
        public int $nonRefundableCreditsMinorUnits,
        public int $childCreditMinorUnits,
        public int $advanceTaxMinorUnits,
        public int $taxBonusMinorUnits,
        public int $withholdingTaxMinorUnits,
        public int $otherDeductionsMinorUnits,
        public int $netPayableMinorUnits,
    ) {
        if ($month < 1 || $month > 12 || $sourceRevisionCount <= 0) {
            throw new \InvalidArgumentException('Měsíční řádek mzdového listu nemá platné období.');
        }
        foreach ($this->amounts() as $amount) {
            if ($amount < 0 || $amount > 1_000_000_000_000) {
                throw new \InvalidArgumentException('Měsíční částka mzdového listu není platná.');
            }
        }
        if ($cashIncomeMinorUnits + $nonCashIncomeMinorUnits !== $grossMinorUnits) {
            throw new \InvalidArgumentException('Peněžní a nepeněžní příjem nesouhlasí s hrubým příjmem.');
        }
        $expectedNet = $cashIncomeMinorUnits
            - $employeeSocialMinorUnits
            - $employeeHealthMinorUnits
            - $healthMinimumTopUpMinorUnits
            - $advanceTaxMinorUnits
            - $withholdingTaxMinorUnits
            - $otherDeductionsMinorUnits
            + $taxBonusMinorUnits;
        if ($expectedNet !== $netPayableMinorUnits) {
            throw new \InvalidArgumentException(
                'Čistá výplata nesouhlasí s příjmem, odvody, daní, bonusem a srážkami.',
            );
        }
    }

    /** @return array<string,int> */
    public function amounts(): array
    {
        return [
            'gross_minor_units' => $this->grossMinorUnits,
            'cash_income_minor_units' => $this->cashIncomeMinorUnits,
            'non_cash_income_minor_units' => $this->nonCashIncomeMinorUnits,
            'social_assessment_base_minor_units' => $this->socialAssessmentBaseMinorUnits,
            'employee_social_minor_units' => $this->employeeSocialMinorUnits,
            'employer_social_minor_units' => $this->employerSocialMinorUnits,
            'health_assessment_base_minor_units' => $this->healthAssessmentBaseMinorUnits,
            'employee_health_minor_units' => $this->employeeHealthMinorUnits,
            'employer_health_minor_units' => $this->employerHealthMinorUnits,
            'health_minimum_top_up_minor_units' => $this->healthMinimumTopUpMinorUnits,
            'advance_tax_base_minor_units' => $this->advanceTaxBaseMinorUnits,
            'advance_tax_before_credits_minor_units' => $this->advanceTaxBeforeCreditsMinorUnits,
            'non_refundable_credits_minor_units' => $this->nonRefundableCreditsMinorUnits,
            'child_credit_minor_units' => $this->childCreditMinorUnits,
            'advance_tax_minor_units' => $this->advanceTaxMinorUnits,
            'tax_bonus_minor_units' => $this->taxBonusMinorUnits,
            'withholding_tax_minor_units' => $this->withholdingTaxMinorUnits,
            'other_deductions_minor_units' => $this->otherDeductionsMinorUnits,
            'net_payable_minor_units' => $this->netPayableMinorUnits,
        ];
    }

    /** @return array<string,int> */
    public function toTemplateData(): array
    {
        return [
            'month' => $this->month,
            'source_revision_count' => $this->sourceRevisionCount,
            ...$this->amounts(),
        ];
    }
}
