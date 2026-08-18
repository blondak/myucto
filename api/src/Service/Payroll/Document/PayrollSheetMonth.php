<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class PayrollSheetMonth
{
    /** Měsíc nese údaje § 38j odst. 2 písm. f) bodů 2 a 3 zmrazené v revizi. */
    public const TAX_DETAIL_RECORDED = 'recorded';

    /**
     * Revize vznikla nad mapováním, které osvobozené částky ani základ srážkové
     * daně neevidovalo. Není to nula — je to neevidovaný údaj a doklad ho tak
     * musí i pojmenovat.
     */
    public const TAX_DETAIL_NOT_RECORDED = 'not_recorded';

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
        /**
         * Doplatek ze zúčtování vyplacený v tomhle měsíci (§ 35d odst. 8).
         * Mzdový list ho vede odděleně — § 38j odst. 2 písm. h) žádá údaje
         * o provedeném ročním zúčtování, a do úhrnu mezd doplatek nepatří.
         */
        public int $annualSettlementMinorUnits = 0,
        /**
         * § 38j odst. 2 písm. f) bod 3 — základ pro výpočet DANĚ PODLE ZVLÁŠTNÍ
         * SAZBY. Bod 3 žádá základ obou způsobů výpočtu; zálohový základ sám
         * o sobě u srážkové daně nevykazuje nic.
         */
        public int $withholdingTaxBaseMinorUnits = 0,
        /**
         * § 38j odst. 2 písm. f) bod 2 — „částky osvobozené od daně z úhrnu
         * zúčtovaných mezd uvedeného v bodě 1". Je to PODMNOŽINA hrubého příjmu,
         * ne přičítaná položka: do čisté mzdy nevstupuje samostatně.
         */
        public int $taxExemptIncomeMinorUnits = 0,
        public string $taxDetailStatus = self::TAX_DETAIL_NOT_RECORDED,
    ) {
        if ($month < 1 || $month > 12 || $sourceRevisionCount <= 0) {
            throw new \InvalidArgumentException('Měsíční řádek mzdového listu nemá platné období.');
        }
        foreach ($this->amounts() as $amount) {
            if ($amount < 0 || $amount > 1_000_000_000_000) {
                throw new \InvalidArgumentException('Měsíční částka mzdového listu není platná.');
            }
        }
        if (!in_array($taxDetailStatus, [
            self::TAX_DETAIL_RECORDED,
            self::TAX_DETAIL_NOT_RECORDED,
        ], true)) {
            throw new \InvalidArgumentException('Stav daňových údajů měsíce není platný.');
        }
        if ($taxDetailStatus === self::TAX_DETAIL_NOT_RECORDED
            && ($taxExemptIncomeMinorUnits !== 0 || $withholdingTaxBaseMinorUnits !== 0)
        ) {
            throw new \InvalidArgumentException(
                'Neevidované daňové údaje měsíce nesmí nést částku.',
            );
        }
        if ($taxExemptIncomeMinorUnits > $grossMinorUnits) {
            throw new \InvalidArgumentException(
                'Osvobozená částka převyšuje úhrn zúčtovaných mezd.',
            );
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
            + $taxBonusMinorUnits
            + $annualSettlementMinorUnits;
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
            'tax_exempt_income_minor_units' => $this->taxExemptIncomeMinorUnits,
            'advance_tax_base_minor_units' => $this->advanceTaxBaseMinorUnits,
            'withholding_tax_base_minor_units' => $this->withholdingTaxBaseMinorUnits,
            'advance_tax_before_credits_minor_units' => $this->advanceTaxBeforeCreditsMinorUnits,
            'non_refundable_credits_minor_units' => $this->nonRefundableCreditsMinorUnits,
            'child_credit_minor_units' => $this->childCreditMinorUnits,
            'advance_tax_minor_units' => $this->advanceTaxMinorUnits,
            'tax_bonus_minor_units' => $this->taxBonusMinorUnits,
            'withholding_tax_minor_units' => $this->withholdingTaxMinorUnits,
            'other_deductions_minor_units' => $this->otherDeductionsMinorUnits,
            'annual_settlement_minor_units' => $this->annualSettlementMinorUnits,
            'net_payable_minor_units' => $this->netPayableMinorUnits,
        ];
    }

    public function taxDetailRecorded(): bool
    {
        return $this->taxDetailStatus === self::TAX_DETAIL_RECORDED;
    }

    /** @return array<string,int|string> */
    public function toTemplateData(): array
    {
        return [
            'month' => $this->month,
            'source_revision_count' => $this->sourceRevisionCount,
            ...$this->amounts(),
            'tax_detail_status' => $this->taxDetailStatus,
        ];
    }
}
