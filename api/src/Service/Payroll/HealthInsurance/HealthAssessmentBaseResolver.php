<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use OverflowException;

final class HealthAssessmentBaseResolver
{
    public function resolve(HealthInsuranceRelationshipInput $relationship): HealthRelationshipFacts
    {
        $participationIncome = 0;
        $assessmentBase = 0;
        $includedParticipation = [];
        $excludedParticipation = [];
        $includedAssessment = [];
        $excludedAssessment = [];
        $issues = [];

        foreach ($relationship->components as $component) {
            if ($component->correctionTreatment === HealthCorrectionTreatment::PriorPeriodRevision) {
                $issues[] = 'prior_period_correction_requires_period_revision:' . $component->code;
            } elseif ($component->correctionTreatment === HealthCorrectionTreatment::Unverified) {
                $issues[] = 'correction_period_unverified:' . $component->code;
            }

            match ($component->participationTreatment) {
                HealthComponentTreatment::Included => [
                    $participationIncome,
                    $includedParticipation,
                ] = [
                    $this->add($participationIncome, $component->amountMinorUnits),
                    [...$includedParticipation, $component->code],
                ],
                HealthComponentTreatment::Excluded =>
                    $excludedParticipation[] = $component->code,
                HealthComponentTreatment::ManualReview =>
                    $issues[] = 'participation_component_manual_review:' . $component->code,
            };
            match ($component->assessmentBaseTreatment) {
                HealthComponentTreatment::Included => [
                    $assessmentBase,
                    $includedAssessment,
                ] = [
                    $this->add($assessmentBase, $component->amountMinorUnits),
                    [...$includedAssessment, $component->code],
                ],
                HealthComponentTreatment::Excluded =>
                    $excludedAssessment[] = $component->code,
                HealthComponentTreatment::ManualReview =>
                    $issues[] = 'assessment_component_manual_review:' . $component->code,
            };
        }

        sort($includedParticipation, SORT_STRING);
        sort($excludedParticipation, SORT_STRING);
        sort($includedAssessment, SORT_STRING);
        sort($excludedAssessment, SORT_STRING);
        $issues = array_values(array_unique($issues));
        sort($issues, SORT_STRING);

        return new HealthRelationshipFacts(
            $relationship,
            $participationIncome,
            $assessmentBase,
            $includedParticipation,
            $excludedParticipation,
            $includedAssessment,
            $excludedAssessment,
            $issues,
        );
    }

    private function add(int $left, int $right): int
    {
        if (
            ($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new OverflowException(
                'Health insurance component aggregation exceeds the integer range.',
            );
        }

        return $left + $right;
    }
}
