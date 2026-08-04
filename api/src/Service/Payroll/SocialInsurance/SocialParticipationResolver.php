<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use InvalidArgumentException;
use OverflowException;

final class SocialParticipationResolver
{
    /**
     * @param array<mixed> $facts
     * @return array<string, SocialParticipationDecision>
     */
    public function resolve(
        array $facts,
        int $smallScaleThresholdMinorUnits,
        int $dppThresholdMinorUnits,
    ): array {
        if ($smallScaleThresholdMinorUnits <= 0 || $dppThresholdMinorUnits <= 0) {
            throw new InvalidArgumentException('Social insurance participation thresholds must be positive.');
        }

        $eligibleDpp = [];
        $eligibleSmallScale = [];
        $dppGroupBlocked = false;
        $smallScaleGroupBlocked = false;
        foreach ($facts as $fact) {
            if (!$fact instanceof SocialRelationshipFacts) {
                throw new InvalidArgumentException(
                    'Social participation resolver expects relationship facts.',
                );
            }
            if (
                $fact->relationship->participationAggregationGroup ===
                SocialParticipationAggregationGroup::Dpp
            ) {
                if (
                    $fact->issues !== []
                    || (!$this->hasAttributableMonth($fact)
                        && ($fact->participationIncomeMinorUnits !== 0
                            || $fact->assessmentBaseMinorUnits !== 0))
                ) {
                    $dppGroupBlocked = true;
                }
                if ($this->hasAttributableMonth($fact)) {
                    $eligibleDpp[] = $fact;
                }
                continue;
            }
            if (
                $fact->relationship->participationAggregationGroup ===
                SocialParticipationAggregationGroup::SmallScaleCandidate
                && (
                    $fact->relationship->agreedMonthlyIncomeMinorUnits === null
                    || $fact->relationship->agreedMonthlyIncomeMinorUnits
                        < $smallScaleThresholdMinorUnits
                )
            ) {
                if (
                    $fact->issues !== []
                    || (!$this->hasAttributableMonth($fact)
                        && ($fact->participationIncomeMinorUnits !== 0
                            || $fact->assessmentBaseMinorUnits !== 0))
                ) {
                    $smallScaleGroupBlocked = true;
                }
                if ($this->hasAttributableMonth($fact)) {
                    $eligibleSmallScale[] = $fact;
                }
            }
        }

        $dppGroupIncome = $this->sumParticipationIncome($eligibleDpp);
        $smallScaleGroupIncome = $this->sumParticipationIncome($eligibleSmallScale);
        $decisions = [];

        foreach ($facts as $fact) {
            $relationship = $fact->relationship;
            $reasons = $fact->issues;

            if (!$this->hasAttributableMonth($fact)) {
                if (
                    !$relationship->activeInParticipationMonth
                    && $fact->participationIncomeMinorUnits === 0
                    && $fact->assessmentBaseMinorUnits === 0
                ) {
                    $decisions[$relationship->relationshipId] = new SocialParticipationDecision(
                        $relationship->relationshipId,
                        SocialParticipationStatus::DoesNotParticipate,
                        0,
                        0,
                        null,
                        ['inactive_without_attributable_income'],
                    );
                    continue;
                }
                $reasons[] = 'income_month_attribution_unverified';
            }

            if ($reasons !== []) {
                $reasons = array_values(array_unique($reasons));
                sort($reasons, SORT_STRING);
                $decisions[$relationship->relationshipId] = new SocialParticipationDecision(
                    $relationship->relationshipId,
                    SocialParticipationStatus::ManualReview,
                    $fact->participationIncomeMinorUnits,
                    $fact->participationIncomeMinorUnits,
                    null,
                    $reasons,
                );
                continue;
            }

            if (
                $relationship->participationAggregationGroup ===
                SocialParticipationAggregationGroup::Dpp
            ) {
                if ($dppGroupBlocked) {
                    $decisions[$relationship->relationshipId] = new SocialParticipationDecision(
                        $relationship->relationshipId,
                        SocialParticipationStatus::ManualReview,
                        $fact->participationIncomeMinorUnits,
                        $dppGroupIncome,
                        $dppThresholdMinorUnits,
                        ['dpp_group_contains_unresolved_relationship'],
                    );
                    continue;
                }
                $participates = $dppGroupIncome >= $dppThresholdMinorUnits;
                $decisions[$relationship->relationshipId] = new SocialParticipationDecision(
                    $relationship->relationshipId,
                    $participates
                        ? SocialParticipationStatus::Participates
                        : SocialParticipationStatus::DoesNotParticipate,
                    $fact->participationIncomeMinorUnits,
                    $dppGroupIncome,
                    $dppThresholdMinorUnits,
                    [$participates ? 'dpp_group_threshold_met' : 'dpp_group_below_threshold'],
                );
                continue;
            }

            if (
                $relationship->participationAggregationGroup ===
                SocialParticipationAggregationGroup::RegularRelationship
            ) {
                $decisions[$relationship->relationshipId] = new SocialParticipationDecision(
                    $relationship->relationshipId,
                    SocialParticipationStatus::Participates,
                    $fact->participationIncomeMinorUnits,
                    $fact->participationIncomeMinorUnits,
                    null,
                    ['regular_relationship'],
                );
                continue;
            }

            if (
                $relationship->agreedMonthlyIncomeMinorUnits !== null
                && $relationship->agreedMonthlyIncomeMinorUnits
                    >= $smallScaleThresholdMinorUnits
            ) {
                $decisions[$relationship->relationshipId] =
                    new SocialParticipationDecision(
                        $relationship->relationshipId,
                        SocialParticipationStatus::Participates,
                        $fact->participationIncomeMinorUnits,
                        $fact->participationIncomeMinorUnits,
                        $smallScaleThresholdMinorUnits,
                        ['agreed_income_threshold_met'],
                    );
                continue;
            }

            if ($smallScaleGroupBlocked) {
                $decisions[$relationship->relationshipId] = new SocialParticipationDecision(
                    $relationship->relationshipId,
                    SocialParticipationStatus::ManualReview,
                    $fact->participationIncomeMinorUnits,
                    $smallScaleGroupIncome,
                    $smallScaleThresholdMinorUnits,
                    ['small_scale_group_contains_unresolved_relationship'],
                );
                continue;
            }
            $participates = $smallScaleGroupIncome >= $smallScaleThresholdMinorUnits;
            $decisions[$relationship->relationshipId] = new SocialParticipationDecision(
                $relationship->relationshipId,
                $participates
                    ? SocialParticipationStatus::Participates
                    : SocialParticipationStatus::DoesNotParticipate,
                $fact->participationIncomeMinorUnits,
                $smallScaleGroupIncome,
                $smallScaleThresholdMinorUnits,
                [$participates
                    ? 'small_scale_group_threshold_met'
                    : 'small_scale_group_below_threshold'],
            );
        }

        ksort($decisions, SORT_STRING);

        return $decisions;
    }

    private function hasAttributableMonth(SocialRelationshipFacts $fact): bool
    {
        if ($fact->relationship->activeInParticipationMonth) {
            return $fact->relationship->incomeAttribution ===
                SocialIncomeAttribution::CurrentEmploymentMonth;
        }

        return $fact->relationship->incomeAttribution ===
            SocialIncomeAttribution::PostTerminationEndMonthVerified;
    }

    /** @param list<SocialRelationshipFacts> $facts */
    private function sumParticipationIncome(array $facts): int
    {
        $sum = 0;
        foreach ($facts as $fact) {
            if ($fact->participationIncomeMinorUnits < 0) {
                continue;
            }
            if ($sum > PHP_INT_MAX - $fact->participationIncomeMinorUnits) {
                throw new OverflowException(
                    'Social insurance participation income exceeds the integer range.',
                );
            }
            $sum += $fact->participationIncomeMinorUnits;
        }

        return $sum;
    }
}
