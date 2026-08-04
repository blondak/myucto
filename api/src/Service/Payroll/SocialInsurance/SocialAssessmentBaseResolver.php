<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use OverflowException;

final class SocialAssessmentBaseResolver
{
    public function resolve(SocialInsuranceRelationshipInput $relationship): SocialRelationshipFacts
    {
        $participationIncome = 0;
        $assessmentBase = 0;
        $includedParticipation = [];
        $excludedParticipation = [];
        $includedAssessment = [];
        $excludedAssessment = [];
        $issues = [];

        foreach ($relationship->components as $component) {
            match ($component->participationTreatment) {
                SocialComponentTreatment::Included => [
                    $participationIncome,
                    $includedParticipation,
                ] = [
                    $this->add($participationIncome, $component->amountMinorUnits),
                    [...$includedParticipation, $component->code],
                ],
                SocialComponentTreatment::Excluded =>
                    $excludedParticipation[] = $component->code,
                SocialComponentTreatment::ManualReview =>
                    $issues[] = 'participation_component_manual_review:' . $component->code,
            };

            match ($component->assessmentBaseTreatment) {
                SocialComponentTreatment::Included => [
                    $assessmentBase,
                    $includedAssessment,
                ] = [
                    $this->add($assessmentBase, $component->amountMinorUnits),
                    [...$includedAssessment, $component->code],
                ],
                SocialComponentTreatment::Excluded =>
                    $excludedAssessment[] = $component->code,
                SocialComponentTreatment::ManualReview =>
                    $issues[] = 'assessment_component_manual_review:' . $component->code,
            };
        }

        if ($participationIncome < 0) {
            $issues[] = 'negative_participation_income_requires_period_revision';
        }
        if ($assessmentBase < 0) {
            $issues[] = 'negative_assessment_base_requires_period_revision';
        }

        sort($includedParticipation, SORT_STRING);
        sort($excludedParticipation, SORT_STRING);
        sort($includedAssessment, SORT_STRING);
        sort($excludedAssessment, SORT_STRING);
        $issues = array_values(array_unique($issues));
        sort($issues, SORT_STRING);

        return new SocialRelationshipFacts(
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
            throw new OverflowException('Social insurance component aggregation exceeds the integer range.');
        }

        return $left + $right;
    }
}
