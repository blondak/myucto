<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

final class LeaveEntitlementCalculator
{
    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    public function calculate(
        string $applicationDate,
        string $relationType,
        int $weeklyMinutes,
        int $entitlementWeeks,
        int $continuousCalendarDays,
        int $workedEquivalentMinutes,
        string $rationale,
    ): LeaveEntitlementResult {
        if (!in_array(
            $relationType,
            [
                'employment',
                'small_scale_employment',
                'dpp',
                'dpc',
                'partner_dependent',
                'statutory_body',
            ],
            true,
        )) {
            throw new InvalidArgumentException('Druh pracovního vztahu není podporovaný.');
        }
        if ($weeklyMinutes <= 0 || $entitlementWeeks <= 0 || $workedEquivalentMinutes <= 0) {
            throw new InvalidArgumentException('Výpočet dovolené vyžaduje kladné časové vstupy.');
        }

        $rules = AbsenceRuleset::forDate($this->rulesets, $applicationDate);
        $statutoryMinimumWeeks = $rules->leaveStatutoryMinimumWeeks();
        $minimumCalendarDays = $rules->leaveMinimumContinuousCalendarDays();
        $minimumWeekMultiples = $rules->leaveMinimumWorkedWeekMultiples();
        $weeksPerYear = $rules->leaveWeeksPerYear();

        if ($entitlementWeeks < $statutoryMinimumWeeks) {
            throw new InvalidArgumentException(
                "Výměra dovolené nesmí být nižší než zákonné minimum {$statutoryMinimumWeeks} týdny."
            );
        }
        if ($continuousCalendarDays < $minimumCalendarDays) {
            throw new InvalidArgumentException(
                'Pracovněprávní vztah musí pro vznik nároku trvat nepřetržitě alespoň'
                . " {$minimumCalendarDays} kalendářních dnů."
            );
        }
        if (trim($rationale) === '') {
            throw new InvalidArgumentException(
                'Ruční posouzení započitatelných a náhradních dob musí mít odůvodnění.'
            );
        }

        $effectiveWeeklyMinutes = in_array($relationType, ['dpp', 'dpc'], true)
            ? $rules->leaveAgreementWeeklyMinutes()
            : $weeklyMinutes;
        $uncappedWorkedWeekMultiples = intdiv($workedEquivalentMinutes, $effectiveWeeklyMinutes);
        $workedWeekMultiples = min($uncappedWorkedWeekMultiples, $weeksPerYear);
        if ($workedWeekMultiples < $minimumWeekMultiples) {
            throw new InvalidArgumentException(
                'Pro vznik nároku musí být započten alespoň'
                . " {$minimumWeekMultiples}násobek týdenní pracovní doby."
            );
        }

        $numerator = $effectiveWeeklyMinutes * $entitlementWeeks;
        if ($numerator > intdiv(PHP_INT_MAX, $workedWeekMultiples)) {
            throw new \OverflowException('Výpočet nároku dovolené překročil celočíselný rozsah.');
        }
        $numerator *= $workedWeekMultiples;
        $denominator = $weeksPerYear * 60;
        $entitlementHours = intdiv($numerator, $denominator);
        if ($numerator % $denominator !== 0) {
            $entitlementHours++;
        }
        $entitlementMinutes = $entitlementHours * 60;

        return new LeaveEntitlementResult(
            $effectiveWeeklyMinutes,
            $workedWeekMultiples,
            $entitlementMinutes,
            'manual_review',
            [
                'relation_type' => $relationType,
                'input_weekly_minutes' => $weeklyMinutes,
                'effective_weekly_minutes' => $effectiveWeeklyMinutes,
                'entitlement_weeks' => $entitlementWeeks,
                'statutory_minimum_weeks' => $statutoryMinimumWeeks,
                'continuous_calendar_days' => $continuousCalendarDays,
                'minimum_continuous_calendar_days' => $minimumCalendarDays,
                'worked_equivalent_minutes' => $workedEquivalentMinutes,
                'uncapped_worked_week_multiples' => $uncappedWorkedWeekMultiples,
                'worked_week_multiples' => $workedWeekMultiples,
                'weeks_per_year' => $weeksPerYear,
                'entitlement_minutes' => $entitlementMinutes,
                'rounding' => 'ceil-to-whole-hour',
                'review_reason' => trim($rationale),
            ],
        );
    }
}
