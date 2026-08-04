<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;

final class HealthParticipationResolver
{
    /**
     * @param array<mixed> $facts
     * @return array<string,HealthParticipationDecision>
     */
    public function resolve(
        string $calculationDate,
        array $facts,
        int $dppThresholdMinorUnits,
        int $dpcThresholdMinorUnits,
    ): array {
        if ($dppThresholdMinorUnits <= 0 || $dpcThresholdMinorUnits <= 0) {
            throw new InvalidArgumentException(
                'Health insurance participation thresholds must be positive.',
            );
        }
        foreach ($facts as $fact) {
            if (!$fact instanceof HealthRelationshipFacts) {
                throw new InvalidArgumentException(
                    'Health participation resolver expects relationship facts.',
                );
            }
        }

        $month = substr($calculationDate, 0, 7);
        $groupFacts = [
            HealthEmploymentKind::Dpp->value => [],
            HealthEmploymentKind::Dpc->value => [],
        ];
        foreach ($facts as $fact) {
            if (
                $fact->relationship->kind === HealthEmploymentKind::Dpp
                || $fact->relationship->kind === HealthEmploymentKind::Dpc
            ) {
                $groupFacts[$fact->relationship->kind->value][] = $fact;
            }
        }
        $groupIncome = [
            HealthEmploymentKind::Dpp->value =>
                $this->sumIncome($groupFacts[HealthEmploymentKind::Dpp->value]),
            HealthEmploymentKind::Dpc->value =>
                $this->sumIncome($groupFacts[HealthEmploymentKind::Dpc->value]),
        ];
        $groupBlocked = [
            HealthEmploymentKind::Dpp->value =>
                $this->groupBlocked($month, $groupFacts[HealthEmploymentKind::Dpp->value]),
            HealthEmploymentKind::Dpc->value =>
                $this->groupBlocked($month, $groupFacts[HealthEmploymentKind::Dpc->value]),
        ];

        $decisions = [];
        foreach ($facts as $fact) {
            $relationship = $fact->relationship;
            $issues = $fact->issues;
            if (!$this->hasAttributableMonth($month, $relationship)) {
                if ($fact->participationIncomeMinorUnits === 0 && $fact->assessmentBaseMinorUnits === 0) {
                    $decisions[$relationship->relationshipId] = new HealthParticipationDecision(
                        $relationship->relationshipId,
                        HealthParticipationStatus::DoesNotParticipate,
                        0,
                        0,
                        null,
                        ['inactive_without_attributable_income'],
                    );
                    continue;
                }
                $issues[] = 'income_month_attribution_unverified';
            }
            if ($relationship->incomeAttribution === HealthIncomeAttribution::Unverified) {
                $issues[] = 'income_month_attribution_unverified';
            }

            if (
                $relationship->kind === HealthEmploymentKind::Dpp
                || $relationship->kind === HealthEmploymentKind::Dpc
            ) {
                $groupKey = $relationship->kind->value;
                $threshold = $relationship->kind === HealthEmploymentKind::Dpp
                    ? $dppThresholdMinorUnits
                    : $dpcThresholdMinorUnits;
                if ($groupBlocked[$groupKey]) {
                    $issues[] = strtolower($groupKey) . '_group_contains_unresolved_relationship';
                }
                if ($groupIncome[$groupKey] < 0) {
                    $issues[] = strtolower($groupKey) . '_group_negative_income_requires_period_revision';
                }
                if ($issues !== []) {
                    $decisions[$relationship->relationshipId] = $this->manual(
                        $fact,
                        $groupIncome[$groupKey],
                        $threshold,
                        $issues,
                    );
                    continue;
                }
                $participates = $groupIncome[$groupKey] >= $threshold;
                $decisions[$relationship->relationshipId] = new HealthParticipationDecision(
                    $relationship->relationshipId,
                    $participates
                        ? HealthParticipationStatus::Participates
                        : HealthParticipationStatus::DoesNotParticipate,
                    $fact->participationIncomeMinorUnits,
                    $groupIncome[$groupKey],
                    $threshold,
                    [$relationship->kind->value . ($participates
                        ? '_group_threshold_met'
                        : '_group_below_threshold')],
                );
                continue;
            }

            if ($issues !== []) {
                $decisions[$relationship->relationshipId] = $this->manual(
                    $fact,
                    $fact->participationIncomeMinorUnits,
                    null,
                    $issues,
                );
                continue;
            }
            $decisions[$relationship->relationshipId] = new HealthParticipationDecision(
                $relationship->relationshipId,
                HealthParticipationStatus::Participates,
                $fact->participationIncomeMinorUnits,
                $fact->participationIncomeMinorUnits,
                null,
                ['dependent_income_relationship'],
            );
        }

        ksort($decisions, SORT_STRING);

        return $decisions;
    }

    private function hasAttributableMonth(
        string $month,
        HealthInsuranceRelationshipInput $relationship,
    ): bool {
        if ($relationship->incomeAttribution === HealthIncomeAttribution::Unverified) {
            return false;
        }
        if ($relationship->incomeAttribution === HealthIncomeAttribution::PostTerminationEndMonthVerified) {
            return $relationship->employmentTo !== null
                && substr($relationship->employmentTo, 0, 7) === $month;
        }
        if (
            $relationship->incomeAttribution
            === HealthIncomeAttribution::PostTerminationPaymentMonthVerified
        ) {
            return $relationship->employmentTo !== null
                && $relationship->employmentTo < $month . '-01';
        }

        $monthStart = new DateTimeImmutable($month . '-01');
        $monthEnd = $monthStart->modify('last day of this month');
        $start = new DateTimeImmutable($relationship->employmentFrom);
        $end = $relationship->employmentTo === null
            ? $monthEnd
            : new DateTimeImmutable($relationship->employmentTo);

        return $start <= $monthEnd && $end >= $monthStart;
    }

    /** @param list<HealthRelationshipFacts> $facts */
    private function sumIncome(array $facts): int
    {
        $sum = 0;
        foreach ($facts as $fact) {
            $amount = $fact->participationIncomeMinorUnits;
            if (
                ($amount > 0 && $sum > PHP_INT_MAX - $amount)
                || ($amount < 0 && $sum < PHP_INT_MIN - $amount)
            ) {
                throw new OverflowException(
                    'Health insurance participation income exceeds the integer range.',
                );
            }
            $sum += $amount;
        }

        return $sum;
    }

    /** @param list<HealthRelationshipFacts> $facts */
    private function groupBlocked(string $month, array $facts): bool
    {
        foreach ($facts as $fact) {
            if ($fact->issues !== []) {
                return true;
            }
            if (
                !$this->hasAttributableMonth($month, $fact->relationship)
                && ($fact->participationIncomeMinorUnits !== 0
                    || $fact->assessmentBaseMinorUnits !== 0)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $issues */
    private function manual(
        HealthRelationshipFacts $fact,
        int $groupIncome,
        ?int $threshold,
        array $issues,
    ): HealthParticipationDecision {
        $issues = array_values(array_unique($issues));
        sort($issues, SORT_STRING);

        return new HealthParticipationDecision(
            $fact->relationship->relationshipId,
            HealthParticipationStatus::ManualReview,
            $fact->participationIncomeMinorUnits,
            $groupIncome,
            $threshold,
            $issues === [] ? ['manual_review'] : $issues,
        );
    }
}
