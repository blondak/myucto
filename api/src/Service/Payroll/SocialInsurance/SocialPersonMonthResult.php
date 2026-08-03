<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use JsonSerializable;
use MyInvoice\Service\Payroll\Calculation\CalculationStep;

final readonly class SocialPersonMonthResult implements JsonSerializable
{
    /**
     * @param list<SocialRelationshipResult> $relationships
     * @param list<string> $issues
     */
    public function __construct(
        public string $personId,
        public SocialCalculationStatus $status,
        public SocialJurisdictionEvidence $jurisdiction,
        public ?string $jurisdictionEvidenceReference,
        public ?string $workingPensionerDiscountEvidenceReference,
        public int $yearToDateAssessmentBaseBeforeMonthMinorUnits,
        public int $participatingAssessmentBaseMinorUnits,
        public int $cappedAssessmentBaseMinorUnits,
        public ?int $employeeContributionBeforeDiscountMinorUnits,
        public ?int $workingPensionerDiscountMinorUnits,
        public ?int $employeeContributionMinorUnits,
        public ?CalculationStep $contributionStep,
        public ?CalculationStep $discountStep,
        public array $relationships,
        public array $issues,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'person_id' => $this->personId,
            'status' => $this->status->value,
            'jurisdiction' => $this->jurisdiction->value,
            'jurisdiction_evidence_reference' => $this->jurisdictionEvidenceReference,
            'working_pensioner_discount_evidence_reference' =>
                $this->workingPensionerDiscountEvidenceReference,
            'year_to_date_assessment_base_before_month_minor_units' =>
                $this->yearToDateAssessmentBaseBeforeMonthMinorUnits,
            'participating_assessment_base_minor_units' =>
                $this->participatingAssessmentBaseMinorUnits,
            'capped_assessment_base_minor_units' => $this->cappedAssessmentBaseMinorUnits,
            'employee_contribution_before_discount_minor_units' =>
                $this->employeeContributionBeforeDiscountMinorUnits,
            'working_pensioner_discount_minor_units' =>
                $this->workingPensionerDiscountMinorUnits,
            'employee_contribution_minor_units' => $this->employeeContributionMinorUnits,
            'contribution_step' => $this->contributionStep?->jsonSerialize(),
            'discount_step' => $this->discountStep?->jsonSerialize(),
            'relationships' => array_map(
                static fn (SocialRelationshipResult $relationship): array =>
                    $relationship->jsonSerialize(),
                $this->relationships,
            ),
            'issues' => $this->issues,
        ];
    }
}
