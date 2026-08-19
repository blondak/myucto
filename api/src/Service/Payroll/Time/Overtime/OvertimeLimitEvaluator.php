<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

/**
 * Hlídání limitů a zákazů přesčasové práce podle zákoníku práce ve znění
 * účinném pro rok 2026.
 *
 * ─── CO ŘÍKÁ ZÁKON ──────────────────────────────────────────────────────────
 *
 * § 93 odst. 2 — „Nařízená práce přesčas nesmí u zaměstnance činit více než
 *               8 hodin v jednotlivých týdnech a 150 hodin v kalendářním roce."
 * § 93 odst. 3 — nad tenhle rozsah lze přesčas požadovat „pouze na základě
 *               dohody se zaměstnancem".
 * § 93 odst. 4 — „Celkový rozsah práce přesčas nesmí činit v průměru více než
 *               8 hodin týdně v období, které může činit nejvýše 26 týdnů po
 *               sobě jdoucích. Jen kolektivní smlouva může vymezit toto období
 *               nejvýše na 52 týdnů po sobě jdoucích."
 * § 93 odst. 5 — „Do počtu hodin nejvýše přípustné práce přesčas ve
 *               vyrovnávacím období podle odstavce 4 se nezahrnuje práce
 *               přesčas, za kterou bylo zaměstnanci poskytnuto náhradní volno."
 * § 78 odst. 1 písm. i) — „U zaměstnanců s kratší pracovní dobou je prací
 *               přesčas práce přesahující stanovenou týdenní pracovní dobu;
 *               těmto zaměstnancům není možné práci přesčas nařídit."
 * § 240 odst. 3 — „Zakazuje se zaměstnávat těhotné zaměstnankyně prací
 *               přesčas. Zaměstnankyním a zaměstnancům, kteří pečují o dítě
 *               mladší než 1 rok, nesmí zaměstnavatel nařídit práci přesčas."
 * § 245 odst. 1 — „Zakazuje se zaměstnávat mladistvé zaměstnance prací
 *               přesčas…" Mladistvý = mladší 18 let (§ 350 odst. 2).
 * § 350a      — „Týdnem se pro účely tohoto zákona rozumí 7 po sobě
 *               následujících kalendářních dnů."
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
 * 2. TÝDEN se posuzuje dvakrát. Kalendářní týden pondělí–neděle dává rozpad,
 *    který účetní vidí v přehledu, a jeho překročení je VAROVÁNÍ. Vedle toho
 *    běží klouzavé okno 7 po sobě jdoucích dnů podle § 350a; jeho překročení
 *    v týdnu, který kalendářní mřížka minula, je INFORMACE — mřížka rozvrhu
 *    směn je ustálený výklad, ale definice týdne v § 350a na ni vázaná není
 *    a přesčas rozložený přes neděli a pondělí by jinak proklouzl beze stopy.
 *
 * 3. ROK je kalendářní rok, jak stojí v odst. 2. Počítadlo se k 1. lednu nuluje.
 *
 * 4. VYROVNÁVACÍ OBDOBÍ je klouzavé okno posledních N celých týdnů, které končí
 *    posledním týdnem uzavřeným do konce mzdového období. Nedokončený týden se
 *    do okna nebere vůbec — jinak by se neúplná data poměřovala s plným
 *    osmihodinovým stropem. Na začátku pracovního poměru je okno kratší
 *    (limit = 8 h × skutečný počet týdnů), takže se první měsíce nehodnotí
 *    proti stropu, který zaměstnanec nemohl vyčerpat. Délka N je firemní údaj
 *    ({@see OvertimeLimits}), ne konstanta.
 *
 * 5. NÁHRADNÍ VOLNO podle odst. 5 se odečítá VÝHRADNĚ z vyrovnávacího okna.
 *    Vynětí je v zákoně navázané na odstavec 4 a nikde jinde; odečítat ho
 *    i z nařízeného přesčasu podle odst. 2 by bylo rozšíření výjimky, které
 *    zákon nedovoluje. Kompenzace se navíc ořezává rozsahem přesčasu daného
 *    dne, aby zápis „8 h náhradního volna" k dni se 4 h přesčasu nemohl
 *    z okna odečíst hodiny, které se nikdy neodpracovaly.
 *
 * 6. ZÁKAZY. Absolutní zákaz (mladistvý, těhotná) se poměřuje s CELKOVÝM
 *    přesčasem — souhlas podle § 93 odst. 3 ho neprolomí. Podmíněné zákazy
 *    (péče o dítě mladší 1 roku, kratší pracovní doba) míří jen na NAŘÍZENÝ
 *    přesčas, takže se poměřují s přesčasem bez evidovaného souhlasu. Ani
 *    jeden z nich nekončí tichým povolením ani tichým zamítnutím: vzniká
 *    nález, který si vynutí ruční výjimku s pojmenovaným důvodem.
 */
final class OvertimeLimitEvaluator
{
    /**
     * @param list<OvertimeSegment> $segments přesčas z docházky; musí pokrývat
     *        aspoň celé vyrovnávací období a celý dosavadní kalendářní rok
     * @param list<OvertimeConsentWindow> $consents
     * @param list<OvertimeCompensation> $compensations náhradní volno podle
     *        § 93 odst. 5, klíčované dnem přesčasu
     * @param list<OvertimeProtectionWindow> $protections zákazy podle § 240 odst. 3
     */
    public function assess(
        int $employmentId,
        string $periodStart,
        string $periodEnd,
        array $segments,
        array $consents,
        OvertimeLimits $limits,
        ?string $employmentStart = null,
        array $compensations = [],
        array $protections = [],
        ?OvertimeEmploymentProfile $profile = null,
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
        $flaggedWeeks = [];
        foreach ($weeks as $week) {
            if ($week['minutes'] <= $limits->orderedWeeklyMaxMinutes) {
                continue;
            }
            $flaggedWeeks[] = $week;
            $findings[] = new OvertimeLimitFinding(
                OvertimeLimitFinding::CODE_WEEKLY,
                'warning',
                $this->weeklyMessage($week, $limits, $consents),
                $week['minutes'],
                $limits->orderedWeeklyMaxMinutes,
                $week['week_start'],
                $week['week_end'],
                self::coveredAnyDay($consents, $week['week_start'], $week['week_end']),
                '§ 93 odst. 2 zákoníku práce',
            );
        }

        $rolling = $this->worstRollingWeek($ordered, $start, $end, $limits, $flaggedWeeks);
        if ($rolling !== null) {
            $findings[] = new OvertimeLimitFinding(
                OvertimeLimitFinding::CODE_ROLLING_WEEK,
                'info',
                sprintf(
                    'Nařízený přesčas přesáhl %s v 7 po sobě jdoucích dnech od %s do %s — '
                    . 'evidováno %s. Kalendářní týden mřížka nepřekročila, týdnem se ale '
                    . 'podle § 350a rozumí kterýchkoli 7 po sobě následujících dnů '
                    . '(§ 93 odst. 2 zákoníku práce).',
                    self::hours($limits->orderedWeeklyMaxMinutes),
                    self::czechDate($rolling['from']),
                    self::czechDate($rolling['to']),
                    self::hours($rolling['minutes']),
                ),
                $rolling['minutes'],
                $limits->orderedWeeklyMaxMinutes,
                $rolling['from'],
                $rolling['to'],
                self::coveredAnyDay($consents, $rolling['from'], $rolling['to']),
                '§ 93 odst. 2 ve spojení s § 350a zákoníku práce',
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
                '§ 93 odst. 2 zákoníku práce',
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
                '§ 93 odst. 2 zákoníku práce',
            );
        }

        $window = $this->averagingWindow($end, $limits, $employmentStart);
        $averagingMinutes = 0;
        $averagingLimit = 0;
        $compensatedMinutes = 0;
        if ($window !== null) {
            $gross = self::sumBetween($total, $window['from'], $window['to']);
            $compensatedMinutes = self::compensatedBetween(
                $compensations,
                $total,
                $window['from'],
                $window['to'],
            );
            $averagingMinutes = $gross - $compensatedMinutes;
            $averagingLimit = $limits->averagingWeeklyMaxMinutes * $window['weeks'];
            if ($averagingMinutes > $averagingLimit) {
                $findings[] = new OvertimeLimitFinding(
                    OvertimeLimitFinding::CODE_AVERAGING,
                    'warning',
                    $this->averagingMessage(
                        $window,
                        $limits,
                        $averagingMinutes,
                        $averagingLimit,
                        $compensatedMinutes,
                    ),
                    $averagingMinutes,
                    $averagingLimit,
                    $window['from'],
                    $window['to'],
                    $consentEvidenced,
                    '§ 93 odst. 4 zákoníku práce',
                );
            }
        }

        [$prohibitionFindings, $prohibitedMinutes] = $this->prohibitions(
            $start,
            $end,
            $ordered,
            $total,
            $protections,
            $profile,
        );
        foreach ($prohibitionFindings as $finding) {
            $findings[] = $finding;
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
            $compensatedMinutes,
            $limits->averagingBasis,
            $limits->averagingReference,
            $prohibitedMinutes,
        );
    }

    /**
     * Zákazy práce přesčas. Vrací nálezy a rozpad zakázaných minut podle důvodu.
     *
     * @param array<string,int> $ordered
     * @param array<string,int> $total
     * @param list<OvertimeProtectionWindow> $protections
     * @return array{0:list<OvertimeLimitFinding>,1:array<string,int>}
     */
    private function prohibitions(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        array $ordered,
        array $total,
        array $protections,
        ?OvertimeEmploymentProfile $profile,
    ): array {
        $from = $start->format('Y-m-d');
        $to = $end->format('Y-m-d');
        $juvenile = 0;
        $pregnancy = 0;
        $childCare = 0;
        $partTime = 0;
        $unknownAge = 0;
        $juvenileFrom = null;
        $juvenileTo = null;

        foreach ($total as $date => $minutes) {
            if ($date < $from || $date > $to || $minutes === 0) {
                continue;
            }
            $orderedMinutes = $ordered[$date] ?? 0;
            if ($profile !== null && $profile->juvenileOn($date)) {
                $juvenile += $minutes;
                $juvenileFrom = $juvenileFrom === null ? $date : min($juvenileFrom, $date);
                $juvenileTo = $juvenileTo === null ? $date : max($juvenileTo, $date);
            }
            if ($profile !== null && $profile->birthDate === null) {
                $unknownAge += $minutes;
            }
            if (self::protectedOn($protections, OvertimeProtectionWindow::PREGNANCY, $date)) {
                $pregnancy += $minutes;
            }
            if ($orderedMinutes > 0
                && self::protectedOn($protections, OvertimeProtectionWindow::CHILD_UNDER_ONE, $date)
            ) {
                $childCare += $orderedMinutes;
            }
            if ($orderedMinutes > 0 && $profile !== null && $profile->partTimeOn($date)) {
                $partTime += $orderedMinutes;
            }
        }

        $findings = [];
        if ($juvenile > 0) {
            $findings[] = new OvertimeLimitFinding(
                OvertimeLimitFinding::CODE_PROHIBITED_JUVENILE,
                'warning',
                sprintf(
                    'Mladistvému zaměstnanci je evidován přesčas %s ve dnech od %s do %s. '
                    . 'Zaměstnávat mladistvé zaměstnance prací přesčas se zakazuje '
                    . '(§ 245 odst. 1 zákoníku práce); zákaz neprolomí ani dohoda podle '
                    . '§ 93 odst. 3.',
                    self::hours($juvenile),
                    self::czechDate((string) $juvenileFrom),
                    self::czechDate((string) $juvenileTo),
                ),
                $juvenile,
                0,
                (string) $juvenileFrom,
                (string) $juvenileTo,
                false,
                '§ 245 odst. 1 zákoníku práce',
                true,
            );
        }
        if ($pregnancy > 0) {
            $findings[] = new OvertimeLimitFinding(
                OvertimeLimitFinding::CODE_PROHIBITED_PREGNANCY,
                'warning',
                sprintf(
                    'Zaměstnankyni s evidovaným těhotenstvím je v období od %s do %s '
                    . 'evidován přesčas %s. Zaměstnávat těhotné zaměstnankyně prací '
                    . 'přesčas se zakazuje (§ 240 odst. 3 věta první zákoníku práce); '
                    . 'zákaz neprolomí ani dohoda podle § 93 odst. 3.',
                    self::czechDate($from),
                    self::czechDate($to),
                    self::hours($pregnancy),
                ),
                $pregnancy,
                0,
                $from,
                $to,
                false,
                '§ 240 odst. 3 věta první zákoníku práce',
                true,
            );
        }
        if ($childCare > 0) {
            $findings[] = new OvertimeLimitFinding(
                OvertimeLimitFinding::CODE_PROHIBITED_CHILD_CARE,
                'warning',
                sprintf(
                    'Zaměstnanci pečujícímu o dítě mladší než 1 rok je v období od %s do %s '
                    . 'evidován přesčas %s bez souhlasu podle § 93 odst. 3, tedy jako '
                    . 'nařízený. Nařídit práci přesčas těmto zaměstnancům zaměstnavatel '
                    . 'nesmí (§ 240 odst. 3 věta druhá zákoníku práce). Dohodnutý přesčas '
                    . 'zakázaný není — pokud dohoda existuje, doplňte ji do evidence.',
                    self::czechDate($from),
                    self::czechDate($to),
                    self::hours($childCare),
                ),
                $childCare,
                0,
                $from,
                $to,
                false,
                '§ 240 odst. 3 věta druhá zákoníku práce',
                true,
            );
        }
        if ($partTime > 0) {
            $findings[] = new OvertimeLimitFinding(
                OvertimeLimitFinding::CODE_PROHIBITED_PART_TIME,
                'warning',
                sprintf(
                    'Zaměstnanci s kratší pracovní dobou je v období od %s do %s evidován '
                    . 'přesčas %s bez souhlasu podle § 93 odst. 3, tedy jako nařízený. '
                    . 'Těmto zaměstnancům není možné práci přesčas nařídit '
                    . '(§ 78 odst. 1 písm. i) zákoníku práce). Dohodnutý přesčas zakázaný '
                    . 'není — pokud dohoda existuje, doplňte ji do evidence.',
                    self::czechDate($from),
                    self::czechDate($to),
                    self::hours($partTime),
                ),
                $partTime,
                0,
                $from,
                $to,
                false,
                '§ 78 odst. 1 písm. i) zákoníku práce',
                true,
            );
        }
        // Chybějící datum narození je mezera v podkladech, ne prokázané
        // porušení: totožnost zaměstnance se v modulu vede primárně rodným
        // číslem, které je uložené šifrovaně a pro hromadnou kontrolu se
        // nedešifruje. Nález proto jen pojmenuje, že se zákaz u mladistvých
        // ověřit nedal — jako `warning` by při identifikaci rodným číslem
        // zaplavil každý běh a zapadly by v něm skutečné nálezy.
        if ($unknownAge > 0) {
            $findings[] = new OvertimeLimitFinding(
                OvertimeLimitFinding::CODE_BIRTH_DATE_MISSING,
                'info',
                sprintf(
                    'U zaměstnance chybí datum narození, takže nejde ověřit zákaz práce '
                    . 'přesčas u mladistvých (§ 245 odst. 1 ve spojení s § 350 odst. 2 '
                    . 'zákoníku práce). V období od %s do %s je přitom evidován přesčas %s.',
                    self::czechDate($from),
                    self::czechDate($to),
                    self::hours($unknownAge),
                ),
                $unknownAge,
                0,
                $from,
                $to,
                false,
                '§ 245 odst. 1 zákoníku práce',
                false,
            );
        }

        return [$findings, array_filter([
            'juvenile' => $juvenile,
            'pregnancy' => $pregnancy,
            'child_under_one' => $childCare,
            'part_time' => $partTime,
        ], static fn (int $minutes): bool => $minutes > 0)];
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
     * Nejhorší okno 7 po sobě jdoucích dnů, které zasahuje do posuzovaného
     * období a jehož překročení kalendářní mřížka neodhalila.
     *
     * Okna, která se překrývají s kalendářním týdnem už označeným jako
     * překročený, se přeskakují — jinak by tentýž přesčas vyrobil druhý nález
     * a účetní by řešila dvakrát totéž.
     *
     * @param array<string,int> $ordered
     * @param list<array{week_start:string,week_end:string,minutes:int}> $flaggedWeeks
     * @return array{from:string,to:string,minutes:int}|null
     */
    private function worstRollingWeek(
        array $ordered,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        OvertimeLimits $limits,
        array $flaggedWeeks,
    ): ?array {
        $worst = null;
        $cursor = $start->modify('-6 days');
        while ($cursor <= $end) {
            $from = $cursor->format('Y-m-d');
            $to = $cursor->modify('+6 days')->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
            if (self::overlapsFlaggedWeek($flaggedWeeks, $from, $to)) {
                continue;
            }
            $minutes = self::sumBetween($ordered, $from, $to);
            if ($minutes <= $limits->orderedWeeklyMaxMinutes) {
                continue;
            }
            // Při shodě vyhrává POZDĚJŠÍ okno: stejný součet drží několik oken
            // vedle sebe a to nejpozdější je z nich nejtěsnější — začíná až
            // prvním dnem, který se do překročení počítá.
            if ($worst === null || $minutes >= $worst['minutes']) {
                $worst = ['from' => $from, 'to' => $to, 'minutes' => $minutes];
            }
        }

        return $worst;
    }

    /** @param list<array{week_start:string,week_end:string,minutes:int}> $flaggedWeeks */
    private static function overlapsFlaggedWeek(
        array $flaggedWeeks,
        string $from,
        string $to,
    ): bool {
        foreach ($flaggedWeeks as $week) {
            if ($from <= $week['week_end'] && $to >= $week['week_start']) {
                return true;
            }
        }

        return false;
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
     * @param array{from:string,to:string,weeks:int} $window
     */
    private function averagingMessage(
        array $window,
        OvertimeLimits $limits,
        int $minutes,
        int $limit,
        int $compensated,
    ): string {
        $message = sprintf(
            'Celkový přesčas přesáhl ve vyrovnávacím období %d týdnů od %s do %s '
            . 'průměr %s týdně — započteno %s proti stropu %s '
            . '(§ 93 odst. 4 zákoníku práce).',
            $window['weeks'],
            self::czechDate($window['from']),
            self::czechDate($window['to']),
            self::hours($limits->averagingWeeklyMaxMinutes),
            self::hours($minutes),
            self::hours($limit),
        );
        if ($compensated > 0) {
            $message .= sprintf(
                ' Náhradním volnem už bylo z okna vyňato %s (§ 93 odst. 5).',
                self::hours($compensated),
            );
        }
        if ($limits->averagingBasis === OvertimeLimits::BASIS_COLLECTIVE_AGREEMENT) {
            $message .= sprintf(
                ' Delší vyrovnávací období je vymezeno kolektivní smlouvou: %s.',
                (string) $limits->averagingReference,
            );
        }

        return $message;
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

    /** @param list<OvertimeProtectionWindow> $protections */
    private static function protectedOn(array $protections, string $kind, string $date): bool
    {
        foreach ($protections as $protection) {
            if ($protection->kind === $kind && $protection->covers($date)) {
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

    /**
     * Součet náhradního volna vyjmutého z okna. Na každý den se odečte nejvýše
     * tolik minut, kolik se ten den přesčasu skutečně odpracovalo — jinak by
     * jediný přehnaný zápis vynuloval limit i za dny, kdy se nic nekompenzovalo.
     *
     * @param list<OvertimeCompensation> $compensations
     * @param array<string,int> $total
     */
    private static function compensatedBetween(
        array $compensations,
        array $total,
        string $from,
        string $to,
    ): int {
        $sum = 0;
        $seen = [];
        foreach ($compensations as $compensation) {
            $date = $compensation->date;
            if ($date < $from || $date > $to || isset($seen[$date])) {
                continue;
            }
            $seen[$date] = true;
            $sum += min($compensation->minutes, $total[$date] ?? 0);
        }

        return $sum;
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
