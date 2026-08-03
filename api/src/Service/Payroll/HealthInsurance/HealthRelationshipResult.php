<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use JsonSerializable;

final readonly class HealthRelationshipResult implements JsonSerializable
{
    /**
     * @param list<string> $includedParticipationComponents
     * @param list<string> $excludedParticipationComponents
     * @param list<string> $includedAssessmentBaseComponents
     * @param list<string> $excludedAssessmentBaseComponents
     */
    public function __construct(
        public string $relationshipId,
        public HealthEmploymentKind $kind,
        public HealthParticipationDecision $participation,
        public int $assessmentBaseMinorUnits,
        public int $participatingAssessmentBaseMinorUnits,
        public array $includedParticipationComponents,
        public array $excludedParticipationComponents,
        public array $includedAssessmentBaseComponents,
        public array $excludedAssessmentBaseComponents,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'relationship_id' => $this->relationshipId,
            'kind' => $this->kind->value,
            'participation' => $this->participation->jsonSerialize(),
            'assessment_base_minor_units' => $this->assessmentBaseMinorUnits,
            'participating_assessment_base_minor_units' =>
                $this->participatingAssessmentBaseMinorUnits,
            'included_participation_components' => $this->includedParticipationComponents,
            'excluded_participation_components' => $this->excludedParticipationComponents,
            'included_assessment_base_components' => $this->includedAssessmentBaseComponents,
            'excluded_assessment_base_components' => $this->excludedAssessmentBaseComponents,
        ];
    }
}
