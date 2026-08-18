<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use MyInvoice\Service\Payroll\Calculation\PayrollRounding;
use OverflowException;

/**
 * Vyměřovací základ vztahu podle § 5 zák. č. 589/1992 Sb.
 *
 * § 5d zaokrouhluje vyměřovací základy podle § 5 až 5c nahoru na celé koruny,
 * a to PŘED sazbou — § 7 odst. 3 pak zaokrouhluje až vypočtené pojistné.
 * Jednotkou je zaměstnání, ne osoba: § 5 odst. 1 váže základ na „zaměstnání,
 * které zakládá účast na nemocenském pojištění" a § 7a odst. 3 mluví o „úhrnu
 * vyměřovacích základů zaměstnance ze všech zaměstnání" v množném čísle.
 * Tím se stanou celými korunami i všechny součty nad ním — základ osoby,
 * základ po ročním maximu i vyměřovací základy zaměstnavatele podle § 5a.
 *
 * Rozhodný příjem pro účast na pojištění se NEzaokrouhluje: § 6 a prahy
 * účasti nejsou vyměřovací základ podle § 5 až 5c a § 5d na ně nedopadá.
 */
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
        } else {
            $assessmentBase = PayrollRounding::ceilToCzk($assessmentBase);
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
