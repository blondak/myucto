<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverMonth;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;

/**
 * Rozhodné období do NEMPRI (`CtRozhodneObdobi`).
 *
 * ## Proč se posílá, když jsou tu jednotná měsíční hlášení
 *
 * § 97 odst. 4 zák. č. 187/2006 Sb. ukládá sdělovat vyměřovací základy
 * a vyloučené dny potřebné pro výpočet dávek jednotným měsíčním hlášením.
 * ÚSSZ si je pak z hlášení bere sama — ale jen za měsíce, za které nějaké
 * hlášení existuje. Rozhodné období sahá 12 měsíců zpět, takže u sociální
 * události v roce 2026 pokrývá i měsíce, za které se měsíční hlášení
 * nepodávalo vůbec (před rokem 2026), nebo je nepodávalo MyÚčto (před začátkem
 * vedení mezd, kdy mzdy zpracoval jiný program). Bez těchto měsíců ÚSSZ nemá
 * z čeho denní vyměřovací základ spočítat a počítá ho z minimální mzdy —
 * přijatá podání jiných mzdových programů proto rozhodné období nesou vždy.
 *
 * Věta proto nese:
 *
 *  - hranice rozhodného období (§ 18 odst. 4: 12 kalendářních měsíců před
 *    měsícem vzniku sociální události; začalo-li zaměstnání později, od jeho
 *    začátku),
 *  - měsíce, které NEPOKRÝVÁ měsíční hlášení z MyÚčta — tedy měsíce před rokem
 *    2026 a měsíce před prvním mzdovým obdobím firmy v MyÚčtu. Jejich
 *    započitatelný příjem a vyloučené dny se berou z převzatých mezd; ruční
 *    doplnění u případu má přednost,
 *  - pravděpodobnou výši příjmu, když rozhodné období nemá aspoň 30
 *    kalendářních dnů (sociální událost v prvním měsíci zaměstnání nebo krátce
 *    po nástupu).
 *
 * Měsíce, které MyÚčto samo vykázalo měsíčním hlášením, se do věty VĚDOMĚ
 * nepřidávají. Tam už číslo ÚSSZ má a druhá verze téhož údaje by se mohla
 * rozejít s opravným hlášením.
 *
 * Součty za celé období jdou do věty jen tehdy, když seznam měsíců pokrývá
 * celé rozhodné období. Součet částečného seznamu by ÚSSZ přečetla jako součet
 * celého období a vyšel by jí nižší denní vyměřovací základ.
 */
final class NempriDecisivePeriodResolver
{
    /**
     * První měsíc, za který se podávalo jednotné měsíční hlášení. Měsíce před
     * ním v žádném hlášení nejsou, ať je zpracoval kdokoli.
     */
    public const FIRST_MONTHLY_REPORT_PERIOD = '2026-01';

    /** § 18: kratší rozhodné období nestačí a nastupuje pravděpodobný příjem. */
    private const MINIMUM_DAYS = 30;

    /**
     * @param list<PayrollTakeoverMonth> $takeoverMonths převzaté měsíce vztahu
     * @param array<string,array{income_minor:int,excluded_days:int}> $manualMonths
     *        ruční doplnění klíčované `YYYY-MM`
     */
    public function resolve(
        string $socialEventOn,
        string $employmentStart,
        ?string $payrollStartPeriod,
        array $takeoverMonths,
        array $manualMonths,
        ?int $probableIncomeCzk,
    ): ?NempriDecisivePeriod {
        [$from, $to] = self::bounds($socialEventOn, $employmentStart);
        $months = [];
        $missing = [];
        $excludedKnown = 0;
        $allListed = true;
        foreach (self::periodsBetween($from, $to) as $period) {
            if (self::coveredByOwnReport($period, $payrollStartPeriod)) {
                $allListed = false;
                continue;
            }
            [$year, $month] = array_map('intval', explode('-', $period));
            if (isset($manualMonths[$period])) {
                $months[] = new NempriDecisiveMonth(
                    $year,
                    $month,
                    $manualMonths[$period]['income_minor'],
                    $manualMonths[$period]['excluded_days'],
                    NempriDecisiveMonth::SOURCE_MANUAL,
                );
                $excludedKnown += $manualMonths[$period]['excluded_days'];
                continue;
            }
            $rows = array_values(array_filter(
                $takeoverMonths,
                static fn (PayrollTakeoverMonth $row): bool => $row->period === $period,
            ));
            if ($rows === []) {
                $missing[] = $period;
                continue;
            }
            $income = 0;
            $excluded = 0;
            foreach ($rows as $row) {
                $income += $row->socialBaseMinor;
                $excluded = max($excluded, $row->excludedDays);
            }
            $months[] = new NempriDecisiveMonth(
                $year,
                $month,
                $income,
                min(31, $excluded),
                NempriDecisiveMonth::SOURCE_TAKEOVER,
            );
            $excludedKnown += $excluded;
        }
        if ($missing !== []) {
            throw new SicknessException(
                'nempri_decisive_month_missing',
                'Rozhodné období obsahuje měsíce, které nevykázalo měsíční hlášení '
                . 'z MyÚčta, a chybí k nim převzatá mzda: ' . implode(', ', $missing)
                . '. Doplňte je v Kontrole převodu mezd, nebo je u případu zadejte ručně '
                . '(započitatelný příjem a vyloučené dny).',
            );
        }
        $calendarDays = $from <= $to ? self::inclusiveDays($from, $to) : 0;
        $needsProbable = $calendarDays - $excludedKnown < self::MINIMUM_DAYS;
        if ($needsProbable && $probableIncomeCzk === null) {
            throw new SicknessException(
                'nempri_probable_income_missing',
                'Rozhodné období je kratší než 30 dnů, takže ÚSSZ vychází z pravděpodobné '
                . 'výše příjmu. Zadejte ji u případu — návrh je měsíční hrubá mzda '
                . 'ze smlouvy.',
            );
        }
        if ($months === [] && !$needsProbable) {
            return null;
        }
        if ($from > $to) {
            // Sociální událost v prvním měsíci zaměstnání: rozhodné období
            // podle § 18 odst. 4 nevzniklo. Věta přesto musí nést obě hranice,
            // a tak nese den nástupu — jediný den, o kterém je jisté, že
            // do zaměstnání patří.
            $to = $from;
        }

        return new NempriDecisivePeriod(
            $from,
            $to,
            $months,
            $allListed && $months !== [],
            $needsProbable ? $probableIncomeCzk : null,
        );
    }

    /**
     * Pokrylo měsíc jednotné měsíční hlášení podané z MyÚčta?
     *
     * Ano, když je to měsíc od roku 2026 a firma ho už počítala v MyÚčtu
     * (není před `payroll_module_state.start_period`). Hranici vykládá jediné
     * místo, {@see PayrollHistoricalPeriodService::precedesStart()}.
     */
    public static function coveredByOwnReport(string $period, ?string $payrollStartPeriod): bool
    {
        return $period >= self::FIRST_MONTHLY_REPORT_PERIOD
            && !PayrollHistoricalPeriodService::precedesStart($payrollStartPeriod, $period);
    }

    /**
     * Hranice rozhodného období podle § 18 odst. 4.
     *
     * Vrácené `od` může být po `do` — to je sociální událost v prvním měsíci
     * zaměstnání, kdy rozhodné období nevzniklo.
     *
     * @return array{0:string,1:string}
     */
    public static function bounds(string $socialEventOn, string $employmentStart): array
    {
        $eventMonth = (new \DateTimeImmutable($socialEventOn))->modify('first day of this month');
        $from = $eventMonth->modify('-12 months')->format('Y-m-d');
        $to = $eventMonth->modify('-1 day')->format('Y-m-d');

        return [max($from, $employmentStart), $to];
    }

    /** @return list<string> `YYYY-MM` od měsíce `$from` do měsíce `$to` */
    private static function periodsBetween(string $from, string $to): array
    {
        if ($from > $to) {
            return [];
        }
        $periods = [];
        $cursor = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
        $last = substr($to, 0, 7);
        while ($cursor->format('Y-m') <= $last) {
            $periods[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return $periods;
    }

    private static function inclusiveDays(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))
            ->diff(new \DateTimeImmutable($to))->days + 1;
    }
}
