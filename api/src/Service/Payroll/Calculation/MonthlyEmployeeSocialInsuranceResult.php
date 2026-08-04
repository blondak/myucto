<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use JsonSerializable;

final readonly class MonthlyEmployeeSocialInsuranceResult implements JsonSerializable
{
    public function __construct(
        public int $inputAssessmentBaseMinorUnits,
        public int $cappedAssessmentBaseMinorUnits,
        public int $employeeContributionBeforeDiscountMinorUnits,
        public int $workingPensionerDiscountMinorUnits,
        public int $employeeContributionMinorUnits,
        public ?CalculationStep $contributionStep,
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
            'employee_contribution_before_discount_minor_units' =>
                $this->employeeContributionBeforeDiscountMinorUnits,
            'working_pensioner_discount_minor_units' => $this->workingPensionerDiscountMinorUnits,
            'employee_contribution_minor_units' => $this->employeeContributionMinorUnits,
            'contribution_step' => $this->contributionStep?->jsonSerialize(),
            'discount_step' => $this->discountStep?->jsonSerialize(),
            'ruleset_id' => $this->rulesetId,
            'ruleset_hash' => $this->rulesetHash,
        ];
    }
}
