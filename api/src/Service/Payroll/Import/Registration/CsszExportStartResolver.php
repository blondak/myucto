<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

use MyInvoice\Service\Payroll\Import\Jmhz\JmhzBatch;

/**
 * Nástup pro větu exportu zaměstnanců ČSSZ, který export sám nenese.
 *
 * Hledá se v platných formulářích měsíčního hlášení téže dávky se stejným
 * ID PPV. Přednost má datum nástupu z identifikace formuláře (10223), jinak
 * začátek pojištění v hlášeném měsíci (10354); z více formulářů vyhrává
 * nejdřívější datum. Začátek pojištění je jen dolní odhad: když vyjde na první
 * den nejstaršího hlášeného měsíce, mohl vztah začít i dřív.
 */
final class CsszExportStartResolver
{
    public const SOURCE_START_DATE = 'start_date';
    public const SOURCE_INSURANCE_FROM = 'insurance_from';

    /** @return array{on:string,source:string,period:string,earliest_period:string}|null */
    public static function resolve(JmhzBatch $batch, string $employmentIdentifier): ?array
    {
        $best = null;
        $earliestPeriod = null;
        foreach ($batch->effective() as $item) {
            if ($item->form->employmentIdentifier !== $employmentIdentifier) {
                continue;
            }
            $period = $item->period();
            $earliestPeriod = $earliestPeriod === null ? $period : min($earliestPeriod, $period);
            [$on, $source] = match (true) {
                $item->form->startDate !== null => [$item->form->startDate, self::SOURCE_START_DATE],
                $item->form->insuranceFrom !== null => [$item->form->insuranceFrom, self::SOURCE_INSURANCE_FROM],
                default => [null, null],
            };
            if ($on === null) {
                continue;
            }
            $rank = [$on, $source === self::SOURCE_START_DATE ? 0 : 1];
            if ($best === null || $rank < $best['rank']) {
                $best = ['rank' => $rank, 'on' => $on, 'source' => $source, 'period' => $period];
            }
        }
        if ($best === null || $earliestPeriod === null) {
            return null;
        }

        return [
            'on' => $best['on'],
            'source' => $best['source'],
            'period' => $best['period'],
            'earliest_period' => $earliestPeriod,
        ];
    }

    /**
     * Začátek pojištění na prvním dni nejstaršího hlášeného měsíce: pojištění
     * mohlo trvat už dřív, skutečný nástup je potřeba ověřit.
     *
     * @param array{on:string,source:string,period:string,earliest_period:string} $start
     */
    public static function needsCheck(array $start): bool
    {
        return $start['source'] === self::SOURCE_INSURANCE_FROM
            && $start['on'] === $start['earliest_period'] . '-01';
    }
}
