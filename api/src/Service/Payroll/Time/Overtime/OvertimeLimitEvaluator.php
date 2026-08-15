<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

/**
 * Hlídání limitů přesčasové práce podle § 93 zákoníku práce (znění účinné pro
 * rok 2026).
 *
 * ─── CO ŘÍKÁ ZÁKON ──────────────────────────────────────────────────────────
 *
 *   odst. 2 — „Nařízená práce přesčas nesmí u zaměstnance činit více než
 *             8 hodin v jednotlivých týdnech a 150 hodin v kalendářním roce."
 *   odst. 3 — nad tenhle rozsah lze přesčas požadovat „pouze na základě dohody
 *             se zaměstnancem".
 *   odst. 4 — „Celkový rozsah práce přesčas nesmí činit v průměru více než
 *             8 hodin týdně v období, které může činit nejvýše 26 týdnů po sobě
 *             jdoucích." Kolektivní smlouva smí období vymezit až na 52 týdnů.
 *   odst. 5 — do vyrovnávacího období podle odst. 4 se nezahrnuje přesčas, za
 *             který bylo poskytnuto náhradní volno.
 *
 * ─── JAK SE TO POČÍTÁ TADY ──────────────────────────────────────────────────
 *
 * 1. NAŘÍZENÝ vs. DOHODNUTÝ. Docházka rozlišení nenese — `payroll_time_entries`
 *    zná jen kategorii `overtime`. Rozlišovacím kritériem je proto evidovaný
 *    souhlas (`payroll_overtime_consents`): přesčas ve dnech krytých souhlasem
 *    je DOHODNUTÝ podle odst. 3, zbytek je NAŘÍZENÝ podle odst. 2. Bez evidence
 *    souhlasu se tedy všechen přesčas poměřuje s 8 h/týden a 150 h/rok, což je
 *    ta konzervativní strana: neevidovaná dohoda je sama o sobě právní vada.
 *
 * 2. TÝDEN je kalendářní týden pondělí–neděle (ISO-8601). Vyhodnocují se
 *    všechny týdny, které zasahují do mzdového období — týden na přelomu měsíců
 *    se tak posoudí v obou bězích, ne v žádném.
 *
 * 3. ROK je kalendářní rok, jak stojí v odst. 2. Počítadlo se k 1. lednu nuluje.
 *
 * 4. VYROVNÁVACÍ OBDOBÍ je klouzavé okno posledních N celých týdnů, které končí
 *    posledním týdnem uzavřeným do konce mzdového období. Nedokončený týden se
 *    do okna nebere vůbec — jinak by se neúplná data poměřovala s plným
 *    osmihodinovým stropem. Na začátku pracovního poměru je okno kratší
 *    (limit = 8 h × skutečný počet týdnů), takže se první měsíce nehodnotí
 *    proti stropu, který zaměstnanec nemohl vyčerpat.
 *
 * Náhradní volno podle odst. 5 modul neeviduje (viz `payroll_absences`, kde pro
 * ně není typ), takže se z okna nic neodečítá. Odchylka je vědomá a je na
 * bezpečné straně — kontrola může upozornit i tam, kde se přesčas kompenzoval
 * volnem; opačná chyba (mlčet u skutečného překročení) by byla horší.
 */
final class OvertimeLimitEvaluator
{
    /**
     * @param list<OvertimeSegment> $segments přesčas z docházky; musí pokrývat
     *        aspoň celé vyrovnávací období a celý dosavadní kalendářní rok
     * @param list<OvertimeConsentWindow> $consents
     */
    public function assess(
        int $employmentId,
        string $periodStart,
        string $periodEnd,
        array $segments,
        array $consents,
        OvertimeLimits $limits,
        ?string $employmentStart = null,
    ): OvertimeLimitAssessment {
        $start = self::date($periodStart, 'periodStart');
        $end = self::date($periodEnd, 'periodEnd');
        if ($end < $start) {
            throw new \InvalidArgumentException('Konec období nesmí předcházet jeho začátku.');
        }

        $ordered = [];
        $total = [];
        foreach ($segments as $segment) {
            if ($segment->minutes === 0) {
                continue;
            }
            $total[$segment->date] = ($total[$segment->date] ?? 0) + $segment->minutes;
            if (!self::covered($consents, $segment->date)) {
                $ordered[$segment->date] = ($ordered[$segment->date] ?? 0) + $segment->minutes;
            }
        }

        $findings = [];
        $weeks = $this->weeklyBreakdown($ordered, $start, $end);
        foreach ($weeks as $week) {
            if ($week['minutes'] <= $limits->orderedWeeklyMaxMinutes) {
                continue;
            }
            $findings[] = new OvertimeLimitFinding(
                OvertimeLimitFinding::CODE_WEEKLY,
                'warning',
                $this->weeklyMessage($week, $limits, $consents),
                $week['minutes'],
                $limits->orderedWeeklyMaxMinutes,
                $week['week_start'],
                $week['week_end'],
                self::coveredAnyDay($consents, $week['week_start'], $week['week_end']),
            );
        }

        $year = (int) $start->format('Y');
        $yearFrom = sprintf('%04d-01-01', $year);
        $yearTo = min($end->format('Y-m-d'), sprintf('%04d-12-31', $year));
        $orderedYearMinutes = self::sumBetween($ordered, $yearFrom, $yearTo);
        $agreedYearMinutes = self::sumBetween($total, $yearFrom, $yearTo) - $orderedYearMinutes;
        $consentEvidenced = self::coveredAnyDay(
            $consents,
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
        );

        if ($orderedYearMinutes > $limits->orderedYearlyMaxMinutes) {
            $findings[] = new OvertimeLimitFinding(
                OvertimeLimitFinding::CODE_YEARLY,
                'warning',
                $this->yearlyMessage($year, $orderedYearMinutes, $limits, $consentEvidenced),
                $orderedYearMinutes,
                $limits->orderedYearlyMaxMinutes,
                $yearFrom,
                $yearTo,
                $consentEvidenced,
            );
        } elseif ($limits->annualEarlyWarningBasisPoints > 0
            && $orderedYearMinutes * 10_000
                >= $limits->orderedYearlyMaxMinutes * $limits->annualEarlyWarningBasisPoints
        ) {
            $findings[] = new OvertimeLimitFinding(
                OvertimeLimitFinding::CODE_YEARLY_APPROACHING,
                'info',
                sprintf(
                    'Nařízený přesčas dosáhl v roce %d hodnoty %s z ročního limitu %s '
                    . '(§ 93 odst. 2 zákoníku práce). Nad tento rozsah lze přesčas konat '
                    . 'jen s dohodou zaměstnance.',
                    $year,
                    self::hours($orderedYearMinutes),
                    self::hours($limits->orderedYearlyMaxMinutes),
                ),
                $orderedYearMinutes,
                $limits->orderedYearlyMaxMinutes,
                $yearFrom,
                $yearTo,
                $consentEvidenced,
            );
        }

        $window = $this->averagingWindow($end, $limits, $employmentStart);
        $averagingMinutes = 0;
        $averagingLimit = 0;
        if ($window !== null) {
            $averagingMinutes = self::sumBetween($total, $window['from'], $window['to']);
            $averagingLimit = $limits->averagingWeeklyMaxMinutes * $window['weeks'];
            if ($averagingMinutes > $averagingLimit) {
                $findings[] = new OvertimeLimitFinding(
                    OvertimeLimitFinding::CODE_AVERAGING,
                    'warning',
                    sprintf(
                        'Celkový přesčas přesáhl ve vyrovnávacím období %d týdnů '
                        . 'od %s do %s průměr %s týdně — evidováno %s proti stropu %s '
                        . '(§ 93 odst. 4 zákoníku práce).',
                        $window['weeks'],
                        self::czechDate($window['from']),
                        self::czechDate($window['to']),
                        self::hours($limits->averagingWeeklyMaxMinutes),
                        self::hours($averagingMinutes),
                        self::hours($averagingLimit),
                    ),
                    $averagingMinutes,
                    $averagingLimit,
                    $window['from'],
                    $window['to'],
                    $consentEvidenced,
                );
            }
        }

        return new OvertimeLimitAssessment(
            $employmentId,
            $findings,
            $weeks,
            $orderedYearMinutes,
            $limits->orderedYearlyMaxMinutes,
            $agreedYearMinutes,
            $window === null ? null : $window['from'],
            $window === null ? null : $window['to'],
            $window === null ? 0 : $window['weeks'],
            $averagingMinutes,
            $averagingLimit,
            $consentEvidenced,
            $limits->fromRuleset,
        );
    }

    /**
     * @param array<string,int> $ordered
     * @return list<array{week_start:string,week_end:string,minutes:int}>
     */
    private function weeklyBreakdown(
        array $ordered,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
    ): array {
        $weeks = [];
        $cursor = self::weekStart($start);
        $last = self::weekStart($end);
        while ($cursor <= $last) {
            $from = $cursor->format('Y-m-d');
            $to = $cursor->modify('+6 days')->format('Y-m-d');
            $weeks[] = [
                'week_start' => $from,
                'week_end' => $to,
                'minutes' => self::sumBetween($ordered, $from, $to),
            ];
            $cursor = $cursor->modify('+7 days');
        }

        return $weeks;
    }

    /**
     * Klouzavé okno celých týdnů. Vrací `null`, když se do konce období nestihl
     * uzavřít ani jeden celý týden trvání pracovního vztahu — pak není co
     * průměrovat.
     *
     * @return array{from:string,to:string,weeks:int}|null
     */
    private function averagingWindow(
        \DateTimeImmutable $end,
        OvertimeLimits $limits,
        ?string $employmentStart,
    ): ?array {
        $lastWeekStart = self::weekStart($end);
        $lastComplete = $lastWeekStart->modify('-1 day');
        if ($lastWeekStart->modify('+6 days') <= $end) {
            $lastComplete = $lastWeekStart->modify('+6 days');
        }
        $windowStart = $lastComplete->modify('-6 days')
            ->modify(sprintf('-%d days', 7 * ($limits->averagingMaxWeeks - 1)));
        if ($employmentStart !== null) {
            $earliest = self::weekStart(self::date($employmentStart, 'employmentStart'));
            if ($earliest > $windowStart) {
                $windowStart = $earliest;
            }
        }
        if ($windowStart > $lastComplete) {
            return null;
        }
        $weeks = intdiv(
            (int) $windowStart->diff($lastComplete)->days + 1,
            7,
        );
        if ($weeks < 1) {
            return null;
        }

        return [
            'from' => $windowStart->format('Y-m-d'),
            'to' => $lastComplete->format('Y-m-d'),
            'weeks' => $weeks,
        ];
    }

    /**
     * @param array{week_start:string,week_end:string,minutes:int} $week
     * @param list<OvertimeConsentWindow> $consents
     */
    private function weeklyMessage(
        array $week,
        OvertimeLimits $limits,
        array $consents,
    ): string {
        $message = sprintf(
            'Nařízený přesčas přesáhl v týdnu od %s do %s zákonných %s — '
            . 'evidováno %s (§ 93 odst. 2 zákoníku práce).',
            self::czechDate($week['week_start']),
            self::czechDate($week['week_end']),
            self::hours($limits->orderedWeeklyMaxMinutes),
            self::hours($week['minutes']),
        );
        if (!self::coveredAnyDay($consents, $week['week_start'], $week['week_end'])) {
            $message .= ' Souhlas zaměstnance s prací přesčas nad nařízený rozsah není evidován.';
        }

        return $message;
    }

    private function yearlyMessage(
        int $year,
        int $minutes,
        OvertimeLimits $limits,
        bool $consentEvidenced,
    ): string {
        $message = sprintf(
            'Nařízený přesčas přesáhl v roce %d zákonných %s — evidováno %s '
            . '(§ 93 odst. 2 zákoníku práce).',
            $year,
            self::hours($limits->orderedYearlyMaxMinutes),
            self::hours($minutes),
        );
        if (!$consentEvidenced) {
            $message .= ' Souhlas zaměstnance s prací přesčas nad nařízený rozsah není evidován.';
        }

        return $message;
    }

    /** @param list<OvertimeConsentWindow> $consents */
    private static function covered(array $consents, string $date): bool
    {
        foreach ($consents as $consent) {
            if ($consent->covers($date)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<OvertimeConsentWindow> $consents */
    private static function coveredAnyDay(array $consents, string $from, string $to): bool
    {
        $cursor = self::date($from, 'from');
        $end = self::date($to, 'to');
        while ($cursor <= $end) {
            if (self::covered($consents, $cursor->format('Y-m-d'))) {
                return true;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return false;
    }

    /** @param array<string,int> $byDate */
    private static function sumBetween(array $byDate, string $from, string $to): int
    {
        $sum = 0;
        foreach ($byDate as $date => $minutes) {
            if ($date >= $from && $date <= $to) {
                $sum += $minutes;
            }
        }

        return $sum;
    }

    private static function weekStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->modify('-' . ((int) $date->format('N') - 1) . ' days');
    }

    private static function hours(int $minutes): string
    {
        return sprintf('%d:%02d h', intdiv($minutes, 60), $minutes % 60);
    }

    private static function czechDate(string $date): string
    {
        return self::date($date, 'date')->format('j. n. Y');
    }

    private static function date(string $value, string $field): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone('UTC'),
        );
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("{$field} musí být datum YYYY-MM-DD.");
        }

        return $parsed;
    }
}
