<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use OverflowException;

/**
 * Vyměřovací základ zaměstnance podle § 3 zák. č. 592/1992 Sb.
 *
 * Základ se tu ZÁMĚRNĚ nezaokrouhluje. Zdravotní pojištění nemá obdobu § 5d
 * zák. č. 589/1992 Sb.: zákon č. 592/1992 Sb. zaokrouhluje nahoru na celé
 * koruny jen POJISTNÉ (§ 2 odst. 2) a odvozované částky (minimální vyměřovací
 * základ osoby samostatně výdělečně činné v § 3a odst. 2), nikoli vyměřovací
 * základ zaměstnance. Doplnit mu zaokrouhlení „pro souměrnost se sociálním"
 * by znamenalo odvést víc, než zákon ukládá, a rozešlo by se to s předpisy
 * zdravotních pojišťoven. Ověřeno proti znění účinnému k 1. 1. 2026.
 */
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
