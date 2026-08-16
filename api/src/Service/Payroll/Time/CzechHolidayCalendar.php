<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Pojmenovaný kalendář svátků pro fond pracovní doby.
 *
 * Vlastní seznam svátků NEMÁ — celou sadu i s kódy a názvy drží
 * {@see CzechWorkingDays}, který je zároveň zdrojem pro lhůty podání.
 * Dokud tu byla druhá kopie, obě odpovídaly na tutéž otázku „je tenhle den
 * pracovní" nezávisle na sobě; rozejít se mohly kdykoli a projev by byl
 * posunutá lhůta nebo špatně spočítaný fond — tedy vada, která se hledá těžko,
 * protože obojí vypadá jako běžné číslo.
 *
 * Zůstává jako samostatná třída ze dvou důvodů: je to injektovaná závislost
 * fondu pracovní doby (mzdová vrstva nemluví na reporty staticky) a hlídá
 * rozsah roku, který má pro fond smysl. Velikonoce se počítají algoritmem
 * z `CzechWorkingDays`, tedy bez `ext-calendar`, kterou `composer.json`
 * nedeklaruje.
 */
final class CzechHolidayCalendar
{
    /** @return array<string,array{code:string,name:string}> */
    public function forYear(int $year): array
    {
        if ($year < 1900 || $year > 2200) {
            throw new \InvalidArgumentException('Rok kalendáře je mimo podporovaný rozsah.');
        }

        return CzechWorkingDays::holidaysForYear($year);
    }
}
