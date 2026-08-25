<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use DateTimeImmutable;
use MyInvoice\Service\Payroll\Calculation\HealthMinimumTopUpPayer;
use OverflowException;

final class HealthMinimumResolver
{
    /**
     * @param list<HealthRelationshipFacts> $facts
     * @param array<string,HealthParticipationDecision> $decisions
     */
    public function resolve(
        string $calculationDate,
        HealthPersonMonthInput $input,
        array $facts,
        array $decisions,
        int $ownAssessmentBaseMinorUnits,
        int $statutoryMonthlyMinimumMinorUnits,
    ): HealthMinimumAssessment {
        $monthStart = new DateTimeImmutable(substr($calculationDate, 0, 7) . '-01');
        $monthEnd = $monthStart->modify('last day of this month');
        $calendarDays = (int) $monthEnd->format('j');
        $activeDays = [];

        foreach ($facts as $fact) {
            $relationship = $fact->relationship;
            if (
                $decisions[$relationship->relationshipId]->status
                !== HealthParticipationStatus::Participates
            ) {
                continue;
            }
            $this->addIntervalDays(
                $activeDays,
                new DateTimeImmutable($relationship->employmentFrom),
                $relationship->employmentTo === null
                    ? $monthEnd
                    : new DateTimeImmutable($relationship->employmentTo),
                $monthStart,
                $monthEnd,
            );
        }
        $localActiveParticipation = $activeDays !== [];

        $otherEmployerBase = 0;
        $issues = [];
        foreach ($input->otherEmployerBases as $otherEmployer) {
            $otherEmployerStart = new DateTimeImmutable($otherEmployer->employmentFrom);
            $otherEmployerEnd = $otherEmployer->employmentTo === null
                ? $monthEnd
                : new DateTimeImmutable($otherEmployer->employmentTo);
            if ($otherEmployerStart > $monthEnd || $otherEmployerEnd < $monthStart) {
                if ($otherEmployer->assessmentBaseMinorUnits !== 0) {
                    $issues[] = 'other_employer_base_outside_calculation_month:'
                        . $otherEmployer->employerReference;
                }
                continue;
            }
            $otherEmployerBase = $this->add(
                $otherEmployerBase,
                $otherEmployer->assessmentBaseMinorUnits,
            );
            $this->addIntervalDays(
                $activeDays,
                $otherEmployerStart,
                $otherEmployerEnd,
                $monthStart,
                $monthEnd,
            );
        }

        $reducedDays = [];
        $evidence = [];
        foreach ($input->minimumReductions as $reduction) {
            $evidence[] = [
                'from' => $reduction->from,
                'to' => $reduction->to,
                'reason' => $reduction->reason->value,
                'evidence_reference' => $reduction->evidenceReference,
            ];
            if ($reduction->reason === HealthMinimumReductionReason::Unverified) {
                $issues[] = 'minimum_reduction_unverified';
                continue;
            }
            if (
                $reduction->reason->requiresWholeMonth()
                && ($reduction->from > $monthStart->format('Y-m-d')
                    || $reduction->to < $monthEnd->format('Y-m-d'))
            ) {
                $issues[] = 'minimum_reduction_requires_whole_month:' .
                    $reduction->reason->value;
                continue;
            }
            if (
                $reduction->reason === HealthMinimumReductionReason::FosterRewardOnly
                && !$this->hasOnlyFosterRewardRelationships(
                    $facts,
                    $decisions,
                    $input->otherEmployerBases === [],
                )
            ) {
                $issues[] = 'foster_reward_only_exception_relationships_mismatch';
                continue;
            }
            $intervalDays = [];
            $this->addIntervalDays(
                $intervalDays,
                new DateTimeImmutable($reduction->from),
                new DateTimeImmutable($reduction->to),
                $monthStart,
                $monthEnd,
            );
            foreach ($intervalDays as $day => $_) {
                if (isset($activeDays[$day])) {
                    $reducedDays[$day] = true;
                }
            }
        }

        ksort($activeDays, SORT_STRING);
        ksort($reducedDays, SORT_STRING);
        usort(
            $evidence,
            static fn (array $left, array $right): int =>
                ($left['from'] <=> $right['from'])
                ?: ($left['to'] <=> $right['to'])
                ?: ($left['reason'] <=> $right['reason']),
        );

        $employmentDays = count($activeDays);
        $excludedDays = count($reducedDays);
        $applicableDays = max(0, $employmentDays - $excludedDays);
        $effectiveMinimum = $localActiveParticipation
            ? $this->ceilDiv(
                $this->multiply($statutoryMonthlyMinimumMinorUnits, $applicableDays),
                $calendarDays,
            )
            : 0;
        $combinedBase = $this->add($ownAssessmentBaseMinorUnits, $otherEmployerBase);
        $gap = max(0, $effectiveMinimum - $combinedBase);
        $hasOtherEmployers = $input->otherEmployerBases !== [];

        if (
            $gap > 0
            && $hasOtherEmployers
            && $input->topUpEmployerSelection
                === HealthMinimumTopUpEmployerSelection::Unverified
        ) {
            $issues[] = 'selected_top_up_employer_unverified';
        }
        $topUpAssignedToThisEmployer = !$hasOtherEmployers
            || $input->topUpEmployerSelection
                === HealthMinimumTopUpEmployerSelection::ThisEmployer;
        if (
            $gap > 0
            && $topUpAssignedToThisEmployer
            && $input->topUpResponsibility === HealthMinimumTopUpResponsibility::Unverified
        ) {
            $issues[] = 'minimum_top_up_responsibility_unverified';
        }
        if ($ownAssessmentBaseMinorUnits < 0) {
            $issues[] = 'negative_assessment_base_requires_period_revision';
        }

        $minimumForThisEmployer = $gap > 0 && $topUpAssignedToThisEmployer
            ? $this->add($ownAssessmentBaseMinorUnits, $gap)
            : 0;
        $payer = $minimumForThisEmployer === 0
            ? null
            : match ($input->topUpResponsibility) {
                HealthMinimumTopUpResponsibility::Employee =>
                    HealthMinimumTopUpPayer::Employee,
                HealthMinimumTopUpResponsibility::EmployerObstacleVerified =>
                    HealthMinimumTopUpPayer::Employer,
                HealthMinimumTopUpResponsibility::Unverified => null,
            };

        $issues = array_values(array_unique($issues));
        sort($issues, SORT_STRING);

        return new HealthMinimumAssessment(
            $calendarDays,
            $employmentDays,
            $excludedDays,
            $applicableDays,
            $statutoryMonthlyMinimumMinorUnits,
            $effectiveMinimum,
            $otherEmployerBase,
            $combinedBase,
            $minimumForThisEmployer,
            $payer,
            $evidence,
            $issues,
        );
    }

    /** @param array<string,true> $target */
    private function addIntervalDays(
        array &$target,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        DateTimeImmutable $monthStart,
        DateTimeImmutable $monthEnd,
    ): void {
        $cursor = $start < $monthStart ? $monthStart : $start;
        $last = $end > $monthEnd ? $monthEnd : $end;
        while ($cursor <= $last) {
            $target[$cursor->format('Y-m-d')] = true;
            $cursor = $cursor->modify('+1 day');
        }
    }

    private function add(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new OverflowException('Health insurance aggregation exceeds the integer range.');
        }

        return $left + $right;
    }

    private function multiply(int $left, int $right): int
    {
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new OverflowException(
                'Health minimum proportional calculation exceeds the integer range.',
            );
        }

        return $left * $right;
    }

    private function ceilDiv(int $numerator, int $denominator): int
    {
        if ($numerator === 0) {
            return 0;
        }

        return intdiv($numerator - 1, $denominator) + 1;
    }

    /**
     * @param list<HealthRelationshipFacts> $facts
     * @param array<string,HealthParticipationDecision> $decisions
     */
    private function hasOnlyFosterRewardRelationships(
        array $facts,
        array $decisions,
        bool $hasNoOtherEmployer,
    ): bool {
        if (!$hasNoOtherEmployer) {
            return false;
        }
        $participating = 0;
        foreach ($facts as $fact) {
            if (
                $decisions[$fact->relationship->relationshipId]->status
                !== HealthParticipationStatus::Participates
            ) {
                continue;
            }
            $participating++;
            if ($fact->relationship->kind !== HealthEmploymentKind::FosterReward) {
                return false;
            }
        }

        return $participating > 0;
    }
}
