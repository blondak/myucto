<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration;

/**
 * Převod více roků v jednom jobu (POHODA, PREMIER): roky z parametrů jobu a výsledný stav.
 * Roky běží vzestupně, každý s vlastním během a protokolem.
 */
final class ImportYears
{
    /** Do logu jobu: zkouška nanečisto víc roků vrací každý rok zpět. */
    public const DRY_RUN_NOTE = 'Zkouška nanečisto převádí každý rok samostatně a vrací ho zpět: pozdější rok v ní nevidí data předchozího roku (počáteční stavy, převzaté doklady a úhrady), jeho výsledek se proto od ostrého převodu může lišit.';

    private const RANK = ['completed' => 0, 'completed_with_warnings' => 1, 'cancelled' => 2, 'failed' => 3];

    /**
     * Roky převodu z parametrů jobu (nebo těla požadavku) vzestupně: `years`, u jobu
     * založeného před výběrem více roků jen `year`.
     *
     * @param array<string,mixed> $params
     * @return list<int>
     */
    public static function fromParams(array $params): array
    {
        $raw = is_array($params['years'] ?? null) ? $params['years'] : [$params['year'] ?? 0];
        $years = [];
        foreach ($raw as $year) {
            $year = filter_var($year, FILTER_VALIDATE_INT);
            if (is_int($year) && $year > 0) {
                $years[$year] = $year;
            }
        }
        sort($years);
        return array_values($years);
    }

    /**
     * `years` v těle požadavku je seznam kladných celých čísel (chybí = platí `year`).
     *
     * @param array<string,mixed> $body
     */
    public static function validBody(array $body): bool
    {
        if (!array_key_exists('years', $body)) {
            return true;
        }
        if (!is_array($body['years'])) {
            return false;
        }
        foreach ($body['years'] as $year) {
            if (!is_int(filter_var($year, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Nejhorší ze stavů roků; job bez jediného doběhlého roku skončil chybou.
     *
     * @param list<string> $statuses
     */
    public static function worstStatus(array $statuses): string
    {
        if ($statuses === []) {
            return 'failed';
        }
        $worst = 'completed';
        foreach ($statuses as $status) {
            $status = isset(self::RANK[$status]) ? $status : 'failed';
            if (self::RANK[$status] > self::RANK[$worst]) {
                $worst = $status;
            }
        }
        return $worst;
    }
}
