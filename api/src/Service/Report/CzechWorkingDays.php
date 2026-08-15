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
    /**
     * Pevné svátky (245/2000 Sb.) jako 'm-d' → kód a název.
     *
     * JEDINÝ zdroj pravdy o českých svátcích v aplikaci. Lhůty potřebují jen
     * odpověď „je to svátek", fond pracovní doby navíc kód a název — a právě
     * proto tu sada žije s popisem, ne dvakrát ve dvou tvarech.
     * {@see \MyInvoice\Service\Payroll\Time\CzechHolidayCalendar} je nad tímhle
     * jen pojmenovaný pohled, vlastní seznam nemá.
     *
     * @var array<string,array{code:string,name:string}>
     */
    private const FIXED_HOLIDAYS = [
        '01-01' => ['code' => 'new_year', 'name' => 'Nový rok'],
        '05-01' => ['code' => 'labour_day', 'name' => 'Svátek práce'],
        '05-08' => ['code' => 'victory_day', 'name' => 'Den vítězství'],
        '07-05' => ['code' => 'cyril_methodius', 'name' => 'Den slovanských věrozvěstů Cyrila a Metoděje'],
        '07-06' => ['code' => 'jan_hus', 'name' => 'Den upálení mistra Jana Husa'],
        '09-28' => ['code' => 'statehood_day', 'name' => 'Den české státnosti'],
        '10-28' => ['code' => 'independent_state_day', 'name' => 'Den vzniku samostatného československého státu'],
        '11-17' => ['code' => 'freedom_democracy_day', 'name' => 'Den boje za svobodu a demokracii'],
        '12-24' => ['code' => 'christmas_eve', 'name' => 'Štědrý den'],
        '12-25' => ['code' => 'christmas_day', 'name' => '1. svátek vánoční'],
        '12-26' => ['code' => 'boxing_day', 'name' => '2. svátek vánoční'],
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

    /**
     * Lhůta zadaná jako `MM-DD` v roce podání, posunutá podle § 33/4 DŘ.
     *
     * Termíny přiznání a přehledů jsou v daňových konstantách uložené jako holý
     * den v roce a jsou navíc uživatelsky editovatelné, takže posun se musí
     * uplatnit až při ČTENÍ — zapéct ho do konstanty by znamenalo, že platí
     * jen pro rok, ve kterém ji někdo naposledy přepsal. Neplatný vstup se
     * vrací beze změny, aby validaci tvaru řešila jediná vrstva (kodebook).
     */
    public static function deadlineFromMonthDay(int $year, string $monthDay): string
    {
        if (preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/D', $monthDay) !== 1) {
            return sprintf('%04d-%s', $year, $monthDay);
        }
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            sprintf('%04d-%s', $year, $monthDay),
        );
        if (!$date instanceof \DateTimeImmutable) {
            return sprintf('%04d-%s', $year, $monthDay);
        }

        return self::shiftToWorkingDay($date)->format('Y-m-d');
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
        return isset(self::holidaysForYear((int) $d->format('Y'))[$d->format('Y-m-d')]);
    }

    /**
     * Všechny svátky roku jako `Y-m-d` → kód a název, seřazené podle data.
     *
     * Pevná sada plus dva pohyblivé svátky odvozené od Velikonoc. Odpověď na
     * „je tenhle den svátek" i „jak se ten svátek jmenuje" se skládá z jedné
     * a téže množiny — dva nezávislé seznamy by se dřív nebo později rozešly
     * a projevilo by se to posunutou lhůtou nebo špatným fondem pracovní doby.
     *
     * @return array<string,array{code:string,name:string}>
     */
    public static function holidaysForYear(int $year): array
    {
        /** @var array<int,array<string,array{code:string,name:string}>> $cache */
        static $cache = [];
        if (isset($cache[$year])) {
            return $cache[$year];
        }

        $holidays = [];
        foreach (self::FIXED_HOLIDAYS as $monthDay => $holiday) {
            $holidays[sprintf('%04d-%s', $year, $monthDay)] = $holiday;
        }

        $easter = self::easterSunday($year);
        $holidays[$easter->modify('-2 days')->format('Y-m-d')]
            = ['code' => 'good_friday', 'name' => 'Velký pátek'];
        $holidays[$easter->modify('+1 day')->format('Y-m-d')]
            = ['code' => 'easter_monday', 'name' => 'Velikonoční pondělí'];

        ksort($holidays);

        return $cache[$year] = $holidays;
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
