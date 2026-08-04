<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use JsonSerializable;

final readonly class MonthlyAdvanceTaxResult implements JsonSerializable
{
    /**
     * @param list<CalculationStep> $rateSteps
     */
    public function __construct(
        public int $taxableIncomeMinorUnits,
        public int $roundedTaxBaseMinorUnits,
        public int $lowRateBaseMinorUnits,
        public int $highRateBaseMinorUnits,
        public array $rateSteps,
        public int $taxBeforeCreditsMinorUnits,
        public int $nonRefundableCreditsMinorUnits,
        public int $childCreditMinorUnits,
        public bool $taxBonusEligible,
        public int $taxAfterCreditsMinorUnits,
        public int $taxBonusMinorUnits,
        public string $rulesetId,
        public string $rulesetHash,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'taxable_income_minor_units' => $this->taxableIncomeMinorUnits,
            'rounded_tax_base_minor_units' => $this->roundedTaxBaseMinorUnits,
            'low_rate_base_minor_units' => $this->lowRateBaseMinorUnits,
            'high_rate_base_minor_units' => $this->highRateBaseMinorUnits,
            'rate_steps' => array_map(
                static fn (CalculationStep $step): array => $step->jsonSerialize(),
                $this->rateSteps,
            ),
            'tax_before_credits_minor_units' => $this->taxBeforeCreditsMinorUnits,
            'non_refundable_credits_minor_units' => $this->nonRefundableCreditsMinorUnits,
            'child_credit_minor_units' => $this->childCreditMinorUnits,
            'tax_bonus_eligible' => $this->taxBonusEligible,
            'tax_after_credits_minor_units' => $this->taxAfterCreditsMinorUnits,
            'tax_bonus_minor_units' => $this->taxBonusMinorUnits,
            'ruleset_id' => $this->rulesetId,
            'ruleset_hash' => $this->rulesetHash,
        ];
    }
}
