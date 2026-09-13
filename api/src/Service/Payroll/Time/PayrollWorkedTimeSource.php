<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

/**
 * Odpracovaná doba měsíce ze zmrazeného zdroje pracovního souhrnu.
 *
 * Jediné místo, které z `source_snapshot_json` souhrnu JMHZ říká „kolik se
 * odpracovalo". Čte ho sestavení souhrnu
 * ({@see PayrollJmhzWorkMonthSummaryBuilder}) i odvození průměrného výdělku
 * ({@see \MyInvoice\Service\Payroll\Absence\AverageEarningDerivationService});
 * dřív měl každý vlastní kopii průchodu směnami a kontrola „dny stojí na téže
 * evidenci jako hodiny" měla vypovídací hodnotu jen do chvíle, kdy se kopie
 * rozejdou.
 *
 * Zdroje jsou dva a vzájemně se vylučují (`payroll_time_months.work_source`):
 *
 * - `time_entries` — intervaly docházky. Minuty, dny i přesčas vznikají
 *   z téhož průchodu: záznam vyřazený kvůli přesahu měsíce nebo záporné době
 *   se neobjeví v žádné z veličin.
 * - `import_summary` — měsíční součty z importu docházky v millihodinách.
 *   Dny ani přesčas se z nich NEDOPOČÍTÁVAJÍ; co podklady nenesou, zůstává
 *   `null` = neuvedeno.
 */
final class PayrollWorkedTimeSource
{
    public const KIND_TIME_ENTRIES = 'time_entries';
    public const KIND_IMPORT_SUMMARY = 'import_summary';

    /**
     * @param array<string,mixed> $source dekódovaný `source_snapshot_json`
     * @return array{
     *   kind:string,
     *   worked_minutes:?int,
     *   worked_millihours:?int,
     *   worked_days:?int,
     *   overtime_minutes:?int,
     *   overtime_millihours:?int,
     *   issues:list<array{code:string,message:string}>
     * }
     */
    public static function fromSnapshot(array $source, string $periodStart): array
    {
        if (array_key_exists(self::KIND_IMPORT_SUMMARY, $source)) {
            return self::fromImportSummary($source[self::KIND_IMPORT_SUMMARY]);
        }
        $entries = $source[self::KIND_TIME_ENTRIES] ?? null;
        if (!is_array($entries)) {
            return self::result(self::KIND_TIME_ENTRIES, null, null, null, null, [[
                'code' => 'worked_source_missing',
                'message' => 'Zdroj pracovního souhrnu nenese odpracovanou dobu.',
            ]]);
        }

        return self::fromEntries($entries, $periodStart);
    }

    /**
     * Odpracované minuty (10268), dny (10267) a přesčas (10269) z intervalů.
     *
     * Bere kategorie `regular` a `overtime` (přesčas je odpracovaná doba i pro
     * průměr podle § 353 odst. 1 ZP), odečítá přestávku a odmítá interval přes
     * hranici místního měsíce, překryv dvou intervalů i zápornou čistou dobu.
     * Den se počítá podle MÍSTNÍHO data začátku intervalu a jen tehdy, když
     * v něm opravdu vznikla kladná odpracovaná doba — dělená směna je jeden
     * den, interval s nulovou čistou dobou žádný.
     *
     * @param array<mixed> $entries
     * @return array{
     *   kind:string,
     *   worked_minutes:?int,
     *   worked_millihours:?int,
     *   worked_days:?int,
     *   overtime_minutes:?int,
     *   overtime_millihours:?int,
     *   issues:list<array{code:string,message:string}>
     * }
     */
    public static function fromEntries(array $entries, string $periodStart): array
    {
        $periodMonth = substr($periodStart, 0, 7);
        $utc = new \DateTimeZone('UTC');
        $minutes = 0;
        $overtimeMinutes = 0;
        $days = [];
        $issues = [];
        $intervals = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)
                || !is_string($entry['category'] ?? null)
                || !is_string($entry['starts_at_utc'] ?? null)
                || !is_string($entry['ends_at_utc'] ?? null)
                || !is_string($entry['timezone_name'] ?? null)
                || !is_int($entry['break_minutes'] ?? null)
            ) {
                $issues[] = [
                    'code' => 'worked_interval_invalid',
                    'message' => 'Evidovaný pracovní interval nemá úplné údaje.',
                ];
                continue;
            }
            if (!in_array($entry['category'], ['regular', 'overtime'], true)) {
                continue;
            }
            try {
                $timezone = new \DateTimeZone($entry['timezone_name']);
                $start = new \DateTimeImmutable($entry['starts_at_utc'], $utc);
                $end = new \DateTimeImmutable($entry['ends_at_utc'], $utc);
            } catch (\Exception) {
                $issues[] = [
                    'code' => 'worked_interval_invalid',
                    'message' => 'Evidovaný pracovní interval nemá úplné údaje.',
                ];
                continue;
            }
            $localStart = $start->setTimezone($timezone);
            $startMonth = $localStart->format('Y-m');
            $endMonth = $end->setTimezone($timezone)->format('Y-m');
            if ($startMonth !== $periodMonth && $endMonth !== $periodMonth && $startMonth === $endMonth) {
                continue;
            }
            if ($startMonth !== $periodMonth || $endMonth !== $periodMonth) {
                $issues[] = [
                    'code' => 'worked_interval_crosses_month',
                    'message' => 'Odpracovaný interval překračuje místní hranici měsíce.',
                ];
                continue;
            }
            foreach ($intervals as [$seenStart, $seenEnd]) {
                if ($start < $seenEnd && $end > $seenStart) {
                    $issues[] = [
                        'code' => 'worked_intervals_overlap',
                        'message' => 'Základní a přesčasové intervaly se překrývají.',
                    ];
                    break;
                }
            }
            $intervals[] = [$start, $end];
            $net = intdiv($end->getTimestamp() - $start->getTimestamp(), 60) - $entry['break_minutes'];
            if ($net < 0) {
                $issues[] = [
                    'code' => 'worked_interval_negative',
                    'message' => 'Přestávka je delší než evidovaný pracovní interval.',
                ];
                continue;
            }
            $minutes += $net;
            if ($entry['category'] === 'overtime') {
                $overtimeMinutes += $net;
            }
            if ($net > 0) {
                $days[$localStart->format('Y-m-d')] = true;
            }
        }

        return self::result(
            self::KIND_TIME_ENTRIES,
            $minutes,
            self::minutesToMillihours($minutes),
            count($days),
            $overtimeMinutes,
            $issues,
        );
    }

    /**
     * Odpracovaná doba ze souhrnu importu docházky.
     *
     * Millihodiny se přebírají beze změny. Minuty se uvádějí jen tam, kde
     * millihodiny odpovídají celým minutám — převod se nezaokrouhluje.
     * Dny zůstávají `null`, pokud je podklady výslovně nenesou.
     *
     * @return array{
     *   kind:string,
     *   worked_minutes:?int,
     *   worked_millihours:?int,
     *   worked_days:?int,
     *   overtime_minutes:?int,
     *   overtime_millihours:?int,
     *   issues:list<array{code:string,message:string}>
     * }
     */
    public static function fromImportSummary(mixed $summary): array
    {
        $values = is_array($summary) ? ($summary['values'] ?? null) : null;
        if (!is_array($values)) {
            return self::result(self::KIND_IMPORT_SUMMARY, null, null, null, null, [[
                'code' => 'import_summary_missing',
                'message' => 'Pracovní měsíc bere docházku ze souhrnu importu, ale aktuální revize měsíce '
                    . 'žádný souhrn nemá. Použijte import docházky za měsíc znovu.',
            ]]);
        }
        $issues = [];
        $worked = $values['worked_hours'] ?? null;
        if (!is_int($worked) || $worked < 0) {
            $worked = null;
            $issues[] = [
                'code' => 'import_worked_hours_missing',
                'message' => 'Souhrn z importu docházky neuvádí odpracované hodiny.',
            ];
        }
        $overtime = $values['overtime_hours'] ?? null;
        if ($overtime !== null && (!is_int($overtime) || $overtime < 0)) {
            $overtime = null;
            $issues[] = [
                'code' => 'import_overtime_invalid',
                'message' => 'Přesčasové hodiny ze souhrnu importu nejsou nezáporné číslo.',
            ];
        }
        if ($worked !== null && $overtime !== null && $overtime > $worked) {
            $issues[] = [
                'code' => 'import_overtime_exceeds_worked',
                'message' => 'Přesčasové hodiny z importu docházky převyšují odpracované hodiny. '
                    . 'Přesčas je částí odpracované doby; opravte podklady.',
            ];
        }
        $days = $summary['worked_days'] ?? null;

        return [
            'kind' => self::KIND_IMPORT_SUMMARY,
            'worked_minutes' => $worked === null ? null : self::millihoursToMinutes($worked),
            'worked_millihours' => $worked,
            'worked_days' => is_int($days) && $days >= 0 ? $days : null,
            'overtime_minutes' => $overtime === null ? null : self::millihoursToMinutes($overtime),
            'overtime_millihours' => $overtime,
            'issues' => $issues,
        ];
    }

    /**
     * @param list<array{code:string,message:string}> $issues
     * @return array{
     *   kind:string,
     *   worked_minutes:?int,
     *   worked_millihours:?int,
     *   worked_days:?int,
     *   overtime_minutes:?int,
     *   overtime_millihours:?int,
     *   issues:list<array{code:string,message:string}>
     * }
     */
    private static function result(
        string $kind,
        ?int $minutes,
        ?int $millihours,
        ?int $days,
        ?int $overtimeMinutes,
        array $issues,
    ): array {
        return [
            'kind' => $kind,
            'worked_minutes' => $minutes,
            'worked_millihours' => $millihours,
            'worked_days' => $days,
            'overtime_minutes' => $overtimeMinutes,
            'overtime_millihours' => $overtimeMinutes === null ? null : self::minutesToMillihours($overtimeMinutes),
            'issues' => $issues,
        ];
    }

    private static function minutesToMillihours(int $minutes): ?int
    {
        return ($minutes * 1000) % 60 === 0 ? intdiv($minutes * 1000, 60) : null;
    }

    private static function millihoursToMinutes(int $millihours): ?int
    {
        return ($millihours * 60) % 1000 === 0 ? intdiv($millihours * 60, 1000) : null;
    }
}
