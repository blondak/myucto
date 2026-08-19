<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use JsonSerializable;
use MyInvoice\Service\Payroll\Calculation\CalculationStep;

final readonly class SocialInsuranceMonthResult implements JsonSerializable
{
    /**
     * @param list<SocialEmployerCategoryResult> $employerCategories
     * @param list<SocialPersonMonthResult> $people
     * @param list<string> $issues
     */
    public function __construct(
        public string $calculationDate,
        public SocialCalculationStatus $status,
        public ?int $participatingAssessmentBaseMinorUnits,
        public ?int $cappedAssessmentBaseMinorUnits,
        public ?int $employeeContributionMinorUnits,
        public ?int $employerContributionBeforeDiscountMinorUnits,
        public ?int $partTimeDiscountAssessmentBaseMinorUnits,
        public ?int $partTimeDiscountMinorUnits,
        public ?int $employerContributionMinorUnits,
        public ?CalculationStep $employerContributionStep,
        public ?CalculationStep $partTimeDiscountStep,
        public array $employerCategories,
        public array $people,
        public array $issues,
        public string $rulesetId,
        public string $rulesetHash,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'calculation_date' => $this->calculationDate,
            'status' => $this->status->value,
            'participating_assessment_base_minor_units' =>
                $this->participatingAssessmentBaseMinorUnits,
            'capped_assessment_base_minor_units' => $this->cappedAssessmentBaseMinorUnits,
            'employee_contribution_minor_units' => $this->employeeContributionMinorUnits,
            'employer_contribution_before_discount_minor_units' =>
                $this->employerContributionBeforeDiscountMinorUnits,
            'part_time_discount_assessment_base_minor_units' =>
                $this->partTimeDiscountAssessmentBaseMinorUnits,
            'part_time_discount_minor_units' => $this->partTimeDiscountMinorUnits,
            'employer_contribution_minor_units' => $this->employerContributionMinorUnits,
            /*
             * `employer_contribution_step` popisuje JEDNU sazbu z jednoho
             * základu. Jakmile má firma v měsíci víc kategorií § 5a odst. 1,
             * žádný takový jeden krok neexistuje a pole zůstává prázdné —
             * dosadit do něj kteroukoli z kategorií by čtenáři podsunulo, že
             * firemní částka vznikla tou sazbou. Úplný rozpad je vždy
             * v `employer_categories`.
             */
            'employer_contribution_step' => $this->employerContributionStep?->jsonSerialize(),
            'part_time_discount_step' => $this->partTimeDiscountStep?->jsonSerialize(),
            'employer_categories' => array_map(
                static fn (SocialEmployerCategoryResult $category): array =>
                    $category->jsonSerialize(),
                $this->employerCategories,
            ),
            'people' => array_map(
                static fn (SocialPersonMonthResult $person): array => $person->jsonSerialize(),
                $this->people,
            ),
            'issues' => $this->issues,
            'ruleset_id' => $this->rulesetId,
            'ruleset_hash' => $this->rulesetHash,
        ];
    }

    public function toCanonicalJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
