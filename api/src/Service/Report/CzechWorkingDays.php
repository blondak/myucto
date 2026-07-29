<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * České pracovní dny pro lhůty daňových podání.
 *
 * § 33 odst. 4 daňového řádu (280/2009 Sb.): připadne-li poslední den lhůty
 * na sobotu, neděli nebo svátek, je posledním dnem lhůty nejblíže následující
 * pracovní den. Bez posunu aplikace chybně hlásila „po termínu" (např.
 * KH 06/2026: 25. 7. 2026 = sobota → skutečný termín pondělí 27. 7. 2026).
 *
 * Svátky dle zákona 245/2000 Sb.: pevné + Velký pátek a Velikonoční pondělí
 * (odvozené z data Velikonoc — anonymní gregoriánský algoritmus, přesný pro
 * celý rozsah let, který výkazy připouštějí).
 */
final class CzechWorkingDays
{
    /** Pevné svátky jako 'm-d' (245/2000 Sb.). */
    private const FIXED_HOLIDAYS = [
        '01-01', // Den obnovy samostatného českého státu / Nový rok
        '05-01', // Svátek práce
        '05-08', // Den vítězství
        '07-05', // Cyril a Metoděj
        '07-06', // Jan Hus
        '09-28', // Den české státnosti
        '10-28', // Vznik samostatného československého státu
        '11-17', // Den boje za svobodu a demokracii
        '12-24', // Štědrý den
        '12-25', // 1. svátek vánoční
        '12-26', // 2. svátek vánoční
    ];

    /**
     * Zákonný termín podání jako `Y-m-d` — zadané datum už posunuté podle § 33/4 DŘ.
     * Jediné místo, kde se lhůty výkazů skládají, ať se posun nikde nezapomene
     * (DPH přiznání, kontrolní i souhrnné hlášení, upozornění na dashboardu).
     */
    public static function deadline(int $year, int $month, int $day = 25): string
    {
        return self::shiftToWorkingDay(
            new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day))
        )->format('Y-m-d');
    }

    /** Posune datum na nejbližší NÁSLEDUJÍCÍ pracovní den (samo o sobě včetně). */
    public static function shiftToWorkingDay(\DateTimeImmutable $d): \DateTimeImmutable
    {
        while (!self::isWorkingDay($d)) {
            $d = $d->modify('+1 day');
        }
        return $d;
    }

    public static function isWorkingDay(\DateTimeImmutable $d): bool
    {
        // N = ISO den v týdnu (6 sobota, 7 neděle)
        if ((int) $d->format('N') >= 6) {
            return false;
        }
        return !self::isPublicHoliday($d);
    }

    public static function isPublicHoliday(\DateTimeImmutable $d): bool
    {
        if (in_array($d->format('m-d'), self::FIXED_HOLIDAYS, true)) {
            return true;
        }
        $easter = self::easterSunday((int) $d->format('Y'));
        $ymd = $d->format('Y-m-d');
        return $ymd === $easter->modify('-2 days')->format('Y-m-d')  // Velký pátek
            || $ymd === $easter->modify('+1 day')->format('Y-m-d');  // Velikonoční pondělí
    }

    /**
     * Velikonoční neděle (gregoriánský kalendář) — anonymní/Meeusův algoritmus.
     * Záměrně bez ext-calendar (easter_date), aby helper běžel všude.
     */
    public static function easterSunday(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
