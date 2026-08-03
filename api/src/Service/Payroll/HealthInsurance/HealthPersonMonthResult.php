<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use JsonSerializable;

final readonly class HealthPersonMonthResult implements JsonSerializable
{
    /**
     * @param list<HealthRelationshipResult> $relationships
     * @param list<array{from:string,to:string,reason:string,evidence_reference:?string}> $minimumReductionEvidence
     * @param list<array{employer_reference:string,assessment_base_minor_units:int,employment_from:string,employment_to:?string,evidence_reference:string}> $otherEmployerEvidence
     * @param list<string> $issues
     */
    public function __construct(
        public string $personId,
        public HealthCalculationStatus $status,
        public HealthJurisdictionEvidence $jurisdiction,
        public ?string $jurisdictionEvidenceReference,
        public HealthInsurerSnapshotStatus $insurerStatus,
        public ?string $insurerCode,
        public ?string $insurerEvidenceReference,
        public int $assessmentBaseMinorUnits,
        public int $otherEmployerAssessmentBaseMinorUnits,
        public int $combinedAssessmentBaseMinorUnits,
        public int $employmentCalendarDays,
        public int $minimumExcludedCalendarDays,
        public int $minimumApplicableCalendarDays,
        public int $statutoryMonthlyMinimumMinorUnits,
        public int $effectiveMinimumMinorUnits,
        public HealthMinimumTopUpResponsibility $topUpResponsibility,
        public ?string $topUpResponsibilityEvidenceReference,
        public ?string $selectedTopUpEmployerEvidenceReference,
        public ?int $standardContributionMinorUnits,
        public ?int $employeeStandardContributionMinorUnits,
        public ?int $employerStandardContributionMinorUnits,
        public ?int $employeeMinimumTopUpMinorUnits,
        public ?int $employerMinimumTopUpMinorUnits,
        public ?int $employeeContributionMinorUnits,
        public ?int $employerContributionMinorUnits,
        public ?int $totalContributionMinorUnits,
        public array $relationships,
        public array $minimumReductionEvidence,
        public array $otherEmployerEvidence,
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
            'insurer_status' => $this->insurerStatus->value,
            'insurer_code' => $this->insurerCode,
            'insurer_evidence_reference' => $this->insurerEvidenceReference,
            'assessment_base_minor_units' => $this->assessmentBaseMinorUnits,
            'other_employer_assessment_base_minor_units' =>
                $this->otherEmployerAssessmentBaseMinorUnits,
            'combined_assessment_base_minor_units' =>
                $this->combinedAssessmentBaseMinorUnits,
            'employment_calendar_days' => $this->employmentCalendarDays,
            'minimum_excluded_calendar_days' => $this->minimumExcludedCalendarDays,
            'minimum_applicable_calendar_days' => $this->minimumApplicableCalendarDays,
            'statutory_monthly_minimum_minor_units' =>
                $this->statutoryMonthlyMinimumMinorUnits,
            'effective_minimum_minor_units' => $this->effectiveMinimumMinorUnits,
            'top_up_responsibility' => $this->topUpResponsibility->value,
            'top_up_responsibility_evidence_reference' =>
                $this->topUpResponsibilityEvidenceReference,
            'selected_top_up_employer_evidence_reference' =>
                $this->selectedTopUpEmployerEvidenceReference,
            'standard_contribution_minor_units' => $this->standardContributionMinorUnits,
            'employee_standard_contribution_minor_units' =>
                $this->employeeStandardContributionMinorUnits,
            'employer_standard_contribution_minor_units' =>
                $this->employerStandardContributionMinorUnits,
            'employee_minimum_top_up_minor_units' =>
                $this->employeeMinimumTopUpMinorUnits,
            'employer_minimum_top_up_minor_units' =>
                $this->employerMinimumTopUpMinorUnits,
            'employee_contribution_minor_units' => $this->employeeContributionMinorUnits,
            'employer_contribution_minor_units' => $this->employerContributionMinorUnits,
            'total_contribution_minor_units' => $this->totalContributionMinorUnits,
            'relationships' => array_map(
                static fn (HealthRelationshipResult $relationship): array =>
                    $relationship->jsonSerialize(),
                $this->relationships,
            ),
            'minimum_reduction_evidence' => $this->minimumReductionEvidence,
            'other_employer_evidence' => $this->otherEmployerEvidence,
            'issues' => $this->issues,
        ];
    }
}
