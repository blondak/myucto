<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

/**
 * Průběh pracovních vztahů napříč měsíci jedné dávky hlášení.
 *
 * Jednotlivý formulář říká jen to, co platilo v jeho měsíci. Řada měsíců ale
 * dokládá i to, co hlášení výslovně nenese: kdy vztah začal a skončil, jaký je
 * úvazek a jestli jde o měsíční mzdu. Tahle třída to odvozuje JEDNOU nad celou
 * dávkou, aby náhled, založení osoby i zápis podmínek četly totéž.
 *
 * Vztah se pozná podle ID PPV; formulář větve B (osoba ještě bez OIČ) podle
 * jména a data narození ({@see JmhzReportForm::relationKey()}).
 *
 * ── Úplný měsíc ─────────────────────────────────────────────────────────────
 * Zaměstnavatel hlásí KAŽDÝ trvající vztah každý měsíc, i s nulovým příjmem.
 * Chybí-li vztah v měsíci, za který dávka nese řádné hlášení se všemi balíky,
 * v předchozím měsíci skončil. Opravné podání takový důkaz není — může nést
 * jen opravované součásti.
 *
 * ── Co se jen odhaduje ──────────────────────────────────────────────────────
 * Nástup, který vyjde na první den nejstaršího hlášeného měsíce, je dolní odhad:
 * vztah mohl začít dřív, jen hlášení za dřívější měsíce v dávce nejsou.
 */
final class JmhzEmploymentHistory
{
    public const START_DATE = 'start_date';
    public const START_INSURANCE_FROM = 'insurance_from';
    public const START_FIRST_REPORT = 'first_report';

    public const END_INSURANCE_TO = 'insurance_to';
    public const END_MISSING_NEXT = 'missing_next';

    /** @var array<string,array<string,JmhzBatchItem>> klíč vztahu => období => formulář */
    private array $months = [];
    /** @var array<string,true> */
    private array $completePeriods = [];

    public static function fromBatch(JmhzBatch $batch): self
    {
        $history = new self();
        foreach ($batch->effective() as $item) {
            $key = $item->form->relationKey();
            if ($key === null) {
                continue;
            }
            $history->months[$key][$item->period()] = $item;
        }
        foreach ($batch->items() as $item) {
            if ($item->file->submissionType === 'R' && $batch->packageComplete($item)) {
                $history->completePeriods[$item->period()] = true;
            }
        }
        foreach ($history->months as &$periods) {
            ksort($periods, SORT_STRING);
        }
        unset($periods);

        return $history;
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->months);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @return array<string,JmhzBatchItem> období => formulář, vzestupně */
    public function months(string $key): array
    {
        return $this->months[$key] ?? [];
    }

    public function first(string $key): ?JmhzBatchItem
    {
        $months = $this->months($key);

        return $months === [] ? null : reset($months);
    }

    public function latest(string $key): ?JmhzBatchItem
    {
        $months = $this->months($key);

        return $months === [] ? null : end($months);
    }

    public function isComplete(string $period): bool
    {
        return isset($this->completePeriods[$period]);
    }

    /**
     * Nástup vztahu: datum nástupu z identifikace (10223), jinak začátek
     * pojištění v prvním hlášeném měsíci. Začátek pojištění prvního dne měsíce
     * je přesný jen tehdy, když dávka nese úplné hlášení předchozího měsíce
     * a vztah v něm není.
     *
     * @return array{on:string,source:string,period:string,needs_check:bool}|null
     */
    public function start(string $key): ?array
    {
        $first = $this->first($key);
        if ($first === null) {
            return null;
        }
        $declared = null;
        foreach ($this->months($key) as $item) {
            if ($item->form->startDate !== null) {
                $declared = $declared === null ? $item->form->startDate : min($declared, $item->form->startDate);
            }
        }
        if ($declared !== null) {
            return ['on' => $declared, 'source' => self::START_DATE, 'period' => $first->period(), 'needs_check' => false];
        }
        $monthStart = $first->file->periodStart();
        $from = $first->form->insuranceFrom;
        if ($from !== null && $from > $monthStart && $from <= $first->file->periodEnd()) {
            return ['on' => $from, 'source' => self::START_INSURANCE_FROM, 'period' => $first->period(), 'needs_check' => false];
        }

        return [
            'on' => $monthStart,
            'source' => $from === null ? self::START_FIRST_REPORT : self::START_INSURANCE_FROM,
            'period' => $first->period(),
            'needs_check' => !$this->isComplete(self::previousPeriod($first->period())),
        ];
    }

    /**
     * Skončení vztahu podle posledního hlášeného měsíce.
     *
     *  - Pojištění končí před koncem měsíce a vztah v dalším měsíci dávky není
     *    ⇒ skončil ke dni konce pojištění.
     *  - Pojištění trvá do konce měsíce a úplné hlášení dalšího měsíce vztah
     *    nenese ⇒ skončil posledním dnem měsíce.
     *
     * @return array{on:string,source:string,period:string}|null
     */
    public function end(string $key): ?array
    {
        $last = $this->latest($key);
        if ($last === null) {
            return null;
        }
        $monthEnd = $last->file->periodEnd();
        $next = self::nextPeriod($last->period());
        $to = $last->form->insuranceTo;
        if ($to !== null && $to >= $last->file->periodStart() && $to < $monthEnd) {
            return ['on' => $to, 'source' => self::END_INSURANCE_TO, 'period' => $last->period()];
        }
        if ($this->isComplete($next)) {
            return ['on' => $monthEnd, 'source' => self::END_MISSING_NEXT, 'period' => $last->period()];
        }

        return null;
    }

    /**
     * Měsíční mzda: tarif (10329) v měsících bez dovolené, nemoci a OČR
     * je stejný, i když se fond pracovní doby liší. Svátek tarif
     * měsíční mzdy nekrátí, a proto se nepočítá. Při
     * hodinové mzdě by se tarif měnil s počtem hodin. Bere se poslední souvislá
     * skupina stejného tarifu — mzda se mohla během roku zvýšit.
     *
     * @return array{amount:int,period:string}|null částka v Kč a měsíc, od kterého platí
     */
    public function monthlySalary(string $key): ?array
    {
        $full = [];
        foreach ($this->months($key) as $period => $item) {
            $form = $item->form;
            if ($item->file->lenient || $form->tariff === null || $form->tariff <= 0
                || $form->unworkedMillihours === null || ($form->workedMillihours ?? 0) <= 0
                || ($form->leaveMillihours ?? 0) > 0 || $form->absenceMillihours !== 0
                || $form->fund === null
            ) {
                continue;
            }
            $full[$period] = ['tariff' => $form->tariff, 'fund' => $form->fund['agreed']];
        }
        if ($full === []) {
            return null;
        }
        $latest = end($full)['tariff'];
        $group = [];
        foreach (array_reverse($full, true) as $period => $row) {
            if ($row['tariff'] !== $latest) {
                break;
            }
            $group[$period] = $row['fund'];
        }
        if (count(array_unique($group)) < 2) {
            return null;
        }

        return ['amount' => $latest, 'period' => (string) array_key_last($group)];
    }

    /**
     * Upozornění na dlouhodobou nepřítomnost: posledních N hlášených měsíců bez
     * odpracované hodiny a bez mzdy, i když vztah trvá.
     *
     * @return array{months:int,from:string}|null
     */
    public function trailingIdleMonths(string $key): ?array
    {
        $count = 0;
        $from = null;
        foreach (array_reverse($this->months($key), true) as $period => $item) {
            $form = $item->form;
            if (($form->workedMillihours ?? 0) > 0 || ($form->wage ?? 0) > 0 || ($form->taxableIncome ?? 0) > 0) {
                break;
            }
            $count++;
            $from = $period;
        }

        return $count === 0 || $from === null ? null : ['months' => $count, 'from' => $from];
    }

    /**
     * Druh činnosti z kódu ELDP (10240), např. „1++" ⇒ „1". Jen pro vztahy účastné
     * na pojištění; bez ELDP druh vztahu z hlášení poznat nejde.
     */
    public function activityCode(string $key): ?string
    {
        foreach (array_reverse($this->months($key)) as $item) {
            if ($item->form->activityCode !== null) {
                return $item->form->activityCode;
            }
            $code = $item->form->eldp['code'] ?? null;
            if (is_string($code) && preg_match('/^([1-9A-J])\+/', $code, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    public static function previousPeriod(string $period): string
    {
        return (new \DateTimeImmutable($period . '-01'))->modify('-1 month')->format('Y-m');
    }

    public static function nextPeriod(string $period): string
    {
        return (new \DateTimeImmutable($period . '-01'))->modify('+1 month')->format('Y-m');
    }
}
