<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use InvalidArgumentException;

final class LeaveEntitlementCalculator
{
    public function calculate(
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
        if ($continuousCalendarDays < 28) {
            throw new InvalidArgumentException(
                'Pracovněprávní vztah musí pro vznik nároku trvat nepřetržitě alespoň 4 týdny.'
            );
        }
        if (trim($rationale) === '') {
            throw new InvalidArgumentException(
                'Ruční posouzení započitatelných a náhradních dob musí mít odůvodnění.'
            );
        }

        $effectiveWeeklyMinutes = in_array($relationType, ['dpp', 'dpc'], true)
            ? 1_200
            : $weeklyMinutes;
        $uncappedWorkedWeekMultiples = intdiv($workedEquivalentMinutes, $effectiveWeeklyMinutes);
        $workedWeekMultiples = min($uncappedWorkedWeekMultiples, 52);
        if ($workedWeekMultiples < 4) {
            throw new InvalidArgumentException(
                'Pro vznik nároku musí být započten alespoň čtyřnásobek týdenní pracovní doby.'
            );
        }

        $numerator = $effectiveWeeklyMinutes * $entitlementWeeks;
        if ($numerator > intdiv(PHP_INT_MAX, $workedWeekMultiples)) {
            throw new \OverflowException('Výpočet nároku dovolené překročil celočíselný rozsah.');
        }
        $numerator *= $workedWeekMultiples;
        $denominator = 52 * 60;
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
                'continuous_calendar_days' => $continuousCalendarDays,
                'worked_equivalent_minutes' => $workedEquivalentMinutes,
                'uncapped_worked_week_multiples' => $uncappedWorkedWeekMultiples,
                'worked_week_multiples' => $workedWeekMultiples,
                'entitlement_minutes' => $entitlementMinutes,
                'rounding' => 'ceil-to-whole-hour',
                'review_reason' => trim($rationale),
            ],
        );
    }
}
