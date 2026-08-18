<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Aritmetika § 356 odst. 2 zákoníku práce oddělená od databáze, aby šla
 * ověřit přímo: koeficient 4,348, vážení týdenní pracovní doby kalendářními
 * dny a zaokrouhlení na tisíciny nahoru.
 */
final class AverageEarningsMonthlyMath
{
    private const MONTH_COEFFICIENT_NUMERATOR = 4348;
    private const MONTH_COEFFICIENT_DENOMINATOR = 1000;

    /** Týdenní pracovní doba v tisícinách hodiny z desetinného zápisu. */
    public static function weeklyHoursMilli(string $value): ?int
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,3}))?$/D', $value, $match) !== 1) {
            return null;
        }
        $fraction = str_pad($match[2] ?? '', 3, '0');
        $milli = ((int) $match[1]) * 1000 + (int) $fraction;

        return $milli > 0 ? $milli : null;
    }

    /**
     * Váhový průměr týdenní pracovní doby podle § 356 odst. 2 věty třetí —
     * úhrn součinů dob a kalendářních dnů dělený počtem dnů, zaokrouhleno
     * na tisíciny NAHORU.
     *
     * @param list<array{weekly_hours_milli:int,calendar_days:int}> $intervals
     */
    public static function weightedWeeklyHoursMilli(array $intervals): ?int
    {
        if ($intervals === []) {
            return null;
        }
        if (count($intervals) === 1) {
            return $intervals[0]['weekly_hours_milli'];
        }
        $weighted = 0;
        $days = 0;
        foreach ($intervals as $interval) {
            $weighted += $interval['weekly_hours_milli']
                * $interval['calendar_days'];
            $days += $interval['calendar_days'];
        }
        if ($days <= 0) {
            return null;
        }

        return intdiv($weighted + $days - 1, $days);
    }

    /** Hrubý měsíční výdělek v haléřích, zaokrouhlený matematicky. */
    public static function grossMonthlyMinorUnits(
        int $hourlyMinorUnits,
        int $weeklyHoursMilli,
    ): int {
        $numerator = $hourlyMinorUnits
            * $weeklyHoursMilli
            * self::MONTH_COEFFICIENT_NUMERATOR;
        $denominator = 1000 * self::MONTH_COEFFICIENT_DENOMINATOR;

        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    /** Potvrzení pro Úřad práce uvádí celé koruny. */
    public static function roundHalfUpToWholeCzk(int $minorUnits): int
    {
        return intdiv(2 * $minorUnits + 100, 200) * 100;
    }

    public static function calendarDays(string $from, string $to): int
    {
        $start = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);

        return $start->diff($end)->days + 1;
    }
}
