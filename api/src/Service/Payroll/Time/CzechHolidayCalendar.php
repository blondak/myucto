<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Pojmenovaný kalendář svátků pro fond pracovní doby. Sadu dnů drží shodnou
 * s `CzechWorkingDays`, který je zdrojem pro lhůty — tady navíc přibývá kód
 * a název svátku, které fond potřebuje a lhůty ne.
 *
 * Velikonoce se počítají výhradně algoritmem z `CzechWorkingDays`. Vlastní
 * výpočet přes `easter_days()` tady vracel shodné datum, ale vyžadoval
 * `ext-calendar`, kterou `composer.json` nedeklaruje a kterou se reports
 * vrstva vědomě rozhodla nepoužívat, aby helper běžel všude.
 */
final class CzechHolidayCalendar
{
    /** @return array<string,array{code:string,name:string}> */
    public function forYear(int $year): array
    {
        if ($year < 1900 || $year > 2200) {
            throw new \InvalidArgumentException('Rok kalendáře je mimo podporovaný rozsah.');
        }

        $holidays = [
            "{$year}-01-01" => ['code' => 'new_year', 'name' => 'Nový rok'],
            "{$year}-05-01" => ['code' => 'labour_day', 'name' => 'Svátek práce'],
            "{$year}-05-08" => ['code' => 'victory_day', 'name' => 'Den vítězství'],
            "{$year}-07-05" => ['code' => 'cyril_methodius', 'name' => 'Den slovanských věrozvěstů Cyrila a Metoděje'],
            "{$year}-07-06" => ['code' => 'jan_hus', 'name' => 'Den upálení mistra Jana Husa'],
            "{$year}-09-28" => ['code' => 'statehood_day', 'name' => 'Den české státnosti'],
            "{$year}-10-28" => ['code' => 'independent_state_day', 'name' => 'Den vzniku samostatného československého státu'],
            "{$year}-11-17" => ['code' => 'freedom_democracy_day', 'name' => 'Den boje za svobodu a demokracii'],
            "{$year}-12-24" => ['code' => 'christmas_eve', 'name' => 'Štědrý den'],
            "{$year}-12-25" => ['code' => 'christmas_day', 'name' => '1. svátek vánoční'],
            "{$year}-12-26" => ['code' => 'boxing_day', 'name' => '2. svátek vánoční'],
        ];

        $easterSunday = CzechWorkingDays::easterSunday($year);
        $goodFriday = $easterSunday->modify('-2 days')->format('Y-m-d');
        $easterMonday = $easterSunday->modify('+1 day')->format('Y-m-d');
        $holidays[$goodFriday] = ['code' => 'good_friday', 'name' => 'Velký pátek'];
        $holidays[$easterMonday] = ['code' => 'easter_monday', 'name' => 'Velikonoční pondělí'];

        ksort($holidays);
        return $holidays;
    }
}
