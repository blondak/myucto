<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

final readonly class SocialRelationshipFacts
{
    /**
     * @param list<string> $includedParticipationComponents
     * @param list<string> $excludedParticipationComponents
     * @param list<string> $includedAssessmentBaseComponents
     * @param list<string> $excludedAssessmentBaseComponents
     * @param list<string> $issues
     */
    public function __construct(
        public SocialInsuranceRelationshipInput $relationship,
        public int $participationIncomeMinorUnits,
        public int $assessmentBaseMinorUnits,
        public array $includedParticipationComponents,
        public array $excludedParticipationComponents,
        public array $includedAssessmentBaseComponents,
        public array $excludedAssessmentBaseComponents,
        public array $issues,
    ) {}
}
