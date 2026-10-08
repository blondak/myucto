<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

/**
 * Věková podmínka důvodu slevy na pojistném podle § 7a odst. 1 písm. a), d), g)
 * zákona č. 589/1992 Sb. - jediné místo, které ji vyhodnocuje.
 *
 * Důvod slevy se na kartě vztahu jen deklaruje. Tři důvody ale mají věkovou
 * hranici, která se dá ověřit proti datu narození:
 *
 *   * a) zaměstnanec „dosáhl alespoň 55 let",
 *   * d) „je mladší 26 let" (a zároveň studuje - studium z data narození poznat
 *     nejde, to zůstává deklarací),
 *   * g) „je mladší 21 let".
 *
 * § 7b odst. 4 požaduje, aby podmínky byly splněné „po celou dobu trvání
 * pracovního nebo služebního poměru v kalendářním měsíci". Dolní hranici (55)
 * proto musí zaměstnanec splňovat už první den pokrytí, horní hranice (26, 21)
 * ještě poslední den pokrytí. V měsíci, ve kterém narozeniny hranici protnou,
 * sleva nenáleží.
 */
final class SocialPartTimeDiscountAgeCondition
{
    public const MET = 'met';
    public const NOT_MET = 'not_met';
    public const UNKNOWN = 'unknown';
    public const NOT_APPLICABLE = 'not_applicable';

    /**
     * Pokrytí měsíce: průnik období a trvání zaměstnání. Stejné měřítko jako
     * u kontroly 291 (OzuspojDiscountEligibility), proto je to jedna funkce.
     *
     * @return array{0:string,1:string} první a poslední den pokrytí
     */
    public static function coverage(
        string $periodStart,
        string $periodEnd,
        ?string $employmentFrom,
        ?string $employmentTo,
    ): array {
        $start = $employmentFrom !== null && $employmentFrom > $periodStart
            ? $employmentFrom
            : $periodStart;
        $end = $employmentTo !== null && $employmentTo < $periodEnd
            ? $employmentTo
            : $periodEnd;

        return [$start, $end];
    }

    /**
     * @return self::MET|self::NOT_MET|self::UNKNOWN|self::NOT_APPLICABLE
     */
    public static function assess(
        ?SocialPartTimeDiscountReason $reason,
        ?string $birthDate,
        string $coverageStart,
        string $coverageEnd,
    ): string {
        $years = match ($reason) {
            SocialPartTimeDiscountReason::Age55Plus => 55,
            SocialPartTimeDiscountReason::StudyUnder26 => 26,
            SocialPartTimeDiscountReason::Under21 => 21,
            default => null,
        };
        if ($reason === null || $years === null) {
            return self::NOT_APPLICABLE;
        }
        $anniversary = self::anniversary($birthDate, $years);
        if ($anniversary === null) {
            return self::UNKNOWN;
        }
        if ($reason === SocialPartTimeDiscountReason::Age55Plus) {
            return $anniversary <= $coverageStart ? self::MET : self::NOT_MET;
        }

        return $anniversary > $coverageEnd ? self::MET : self::NOT_MET;
    }

    /**
     * Posouzení rovnou nad údaji měsíce, tak jak je má mzdový běh.
     *
     * @return self::MET|self::NOT_MET|self::UNKNOWN|self::NOT_APPLICABLE
     */
    public static function forMonth(
        mixed $reason,
        ?string $birthDate,
        string $periodStart,
        string $periodEnd,
        ?string $employmentFrom,
        ?string $employmentTo,
    ): string {
        $parsed = is_string($reason) ? SocialPartTimeDiscountReason::tryFrom($reason) : null;
        [$start, $end] = self::coverage($periodStart, $periodEnd, $employmentFrom, $employmentTo);

        return self::assess($parsed, $birthDate, $start, $end);
    }

    /**
     * Den, kdy osoba narozená v `$birthDate` dosáhne `$years` let. Narodil-li se
     * někdo 29. února, v nepřestupném roce připadá narozeniny na poslední den
     * února - jinak by `modify('+N years')` přeteklo do března.
     */
    private static function anniversary(?string $birthDate, int $years): ?string
    {
        if ($birthDate === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $birthDate) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $birthDate) {
            return null;
        }
        $year = (int) $date->format('Y') + $years;
        $month = (int) $date->format('n');
        $day = (int) $date->format('j');
        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->format('t');

        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $lastDay));
    }
}
