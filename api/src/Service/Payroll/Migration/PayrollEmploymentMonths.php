<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Měsíce roku, ve kterých u plátce trval pracovní vztah — JEDINÉ pravidlo.
 *
 * Bere se PŘEKRYV s měsícem, ne jeho počátek: kdo nastoupil 15. ledna, má leden
 * ve mzdách i v zúčtování. Rozpracované a stornované vztahy se nepočítají —
 * mzda z nich nevznikla (`STATUSES`).
 *
 * Existuje proto, že tutéž otázku kladou dvě různé cesty: roční zúčtování
 * (za které měsíce se posuzuje prohlášení a rezidence) a přechod v průběhu roku
 * (za které převzaté měsíce MUSÍ existovat počáteční stav). Kdyby si každá
 * držela vlastní výklad, vyúčtování by chtělo převzatá data za jiné měsíce, než
 * jaké bere v úvahu roční zúčtování.
 */
final class PayrollEmploymentMonths
{
    /** Stavy vztahu, ze kterých mzda vzniká. */
    public const STATUSES = ['active', 'ended'];

    /**
     * @param list<array<string,mixed>> $spans řádky se `start_date` a `end_date`
     * @return list<int> měsíce 1–12, seřazené
     */
    public static function covered(array $spans, int $year): array
    {
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-12-31', $year);
        $months = [];
        foreach ($spans as $row) {
            $start = is_string($row['start_date'] ?? null) && $row['start_date'] !== ''
                ? substr($row['start_date'], 0, 10)
                : $yearStart;
            $end = is_string($row['end_date'] ?? null) && $row['end_date'] !== ''
                ? substr($row['end_date'], 0, 10)
                : $yearEnd;
            for ($month = 1; $month <= 12; $month++) {
                $monthStart = sprintf('%04d-%02d-01', $year, $month);
                $monthEnd = date('Y-m-t', (int) strtotime($monthStart));
                if ($start <= $monthEnd && $end >= $monthStart) {
                    $months[$month] = true;
                }
            }
        }
        ksort($months);

        return array_map('intval', array_keys($months));
    }
}
