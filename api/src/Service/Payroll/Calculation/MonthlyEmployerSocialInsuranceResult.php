<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use JsonSerializable;

final readonly class MonthlyEmployerSocialInsuranceResult implements JsonSerializable
{
    public function __construct(
        public int $inputAssessmentBaseMinorUnits,
        public int $cappedAssessmentBaseMinorUnits,
        public int $partTimeDiscountAssessmentBaseMinorUnits,
        public int $contributionBeforeDiscountMinorUnits,
        public int $partTimeDiscountMinorUnits,
        public int $contributionMinorUnits,
        public CalculationStep $contributionStep,
        public ?CalculationStep $discountStep,
        public string $rulesetId,
        public string $rulesetHash,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'input_assessment_base_minor_units' => $this->inputAssessmentBaseMinorUnits,
            'capped_assessment_base_minor_units' => $this->cappedAssessmentBaseMinorUnits,
            'part_time_discount_assessment_base_minor_units' =>
                $this->partTimeDiscountAssessmentBaseMinorUnits,
            'contribution_before_discount_minor_units' => $this->contributionBeforeDiscountMinorUnits,
            'part_time_discount_minor_units' => $this->partTimeDiscountMinorUnits,
            'contribution_minor_units' => $this->contributionMinorUnits,
            'contribution_step' => $this->contributionStep->jsonSerialize(),
            'discount_step' => $this->discountStep?->jsonSerialize(),
            'ruleset_id' => $this->rulesetId,
            'ruleset_hash' => $this->rulesetHash,
        ];
    }
}
