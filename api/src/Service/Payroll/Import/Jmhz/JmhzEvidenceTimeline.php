<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

/**
 * Změna jedné časové řady zákonné evidence „od prvního dne měsíce hlášení".
 *
 * Pracuje nad řádky z {@see \MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository::editorView()}
 * a vrací cílový stav řady, který se pošle zpátky přes `save()` — repozitář si
 * rozdíl spočítá sám a hlídá překryvy, navazování i zmrazená období.
 *
 * Pravidla (evidence se vede po celých měsících):
 *  - platí-li od toho dne už shodná hodnota, nic se nemění,
 *  - verze začínající týmž dnem se opraví na místě,
 *  - starší verze se ukončí posledním dnem předchozího měsíce a nová platí do
 *    konce té původní (historie předchozích měsíců zůstane, jak byla),
 *  - chybí-li verze, vznikne nová do dne před další existující verzí,
 *  - měsíc uzavřený schválenou mzdou se nemění vůbec.
 *
 * Třída je čistá (bez databáze), aby šla stejně použít v náhledu i při zápisu.
 */
final class JmhzEvidenceTimeline
{
    /**
     * @param list<array<string,mixed>> $rows řádky JEDNÉ řady
     * @param array<string,?string> $values
     * @param list<string> $compare pole, podle kterých se posuzuje shoda
     * @return array{rows:list<array<string,mixed>>,changed:bool,current:?array<string,mixed>,warning:?string}
     */
    public static function setFrom(
        array $rows,
        string $monthStart,
        array $values,
        array $compare,
        bool $contiguous,
        ?string $frozenThrough,
    ): array {
        $covering = self::covering($rows, $monthStart);
        $unchanged = ['rows' => $rows, 'changed' => false, 'current' => $covering, 'warning' => null];
        if ($covering !== null && self::same($covering, $values, $compare)) {
            return $unchanged;
        }
        if ($frozenThrough !== null && $monthStart <= $frozenThrough) {
            return ['warning' => 'Měsíc ' . substr($monthStart, 0, 7) . ' je uzavřený schválenou mzdou, '
                . 'evidence se za něj z hlášení nemění.'] + $unchanged;
        }

        if ($covering !== null) {
            $result = [];
            foreach ($rows as $row) {
                if ((int) ($row['id'] ?? 0) !== (int) $covering['id']) {
                    $result[] = $row;
                    continue;
                }
                if ((string) $row['effective_from'] === $monthStart) {
                    $result[] = array_merge($row, $values);
                    continue;
                }
                $result[] = ['effective_to' => self::previousDay($monthStart)] + $row;
                $result[] = self::newRow($values, $monthStart, self::text($row['effective_to'] ?? null));
            }

            return ['rows' => $result, 'changed' => true, 'current' => $covering, 'warning' => null];
        }

        $next = null;
        $previous = null;
        foreach ($rows as $row) {
            $from = (string) $row['effective_from'];
            $to = self::text($row['effective_to'] ?? null);
            if ($from > $monthStart && ($next === null || $from < (string) $next['effective_from'])) {
                $next = $row;
            }
            if ($to !== null && $to < $monthStart
                && ($previous === null || $to > (string) $previous['effective_to'])
            ) {
                $previous = $row;
            }
        }
        if ($contiguous && $previous !== null && (string) $previous['effective_to'] !== self::previousDay($monthStart)) {
            return ['warning' => 'Evidence má před měsícem ' . substr($monthStart, 0, 7)
                . ' mezeru, kterou hlášení nevyplní. Doplňte ji ručně v zákonné evidenci osoby.'] + $unchanged;
        }
        $rows[] = self::newRow(
            $values,
            $monthStart,
            $next === null ? null : self::previousDay((string) $next['effective_from']),
        );

        return ['rows' => $rows, 'changed' => true, 'current' => null, 'warning' => null];
    }

    /**
     * Ukončí verzi platnou k prvnímu dni měsíce posledním dnem předchozího
     * měsíce — ale jen tu, kterou `$owned` uzná (import ukončuje jen to, co
     * sám založil).
     *
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):bool $owned
     * @return array{rows:list<array<string,mixed>>,changed:bool,current:?array<string,mixed>,warning:?string}
     */
    public static function endBefore(
        array $rows,
        string $monthStart,
        callable $owned,
        ?string $frozenThrough,
    ): array {
        $covering = self::covering($rows, $monthStart);
        $unchanged = ['rows' => $rows, 'changed' => false, 'current' => $covering, 'warning' => null];
        if ($covering === null || !$owned($covering)) {
            return $unchanged;
        }
        if ((string) $covering['effective_from'] >= $monthStart
            || ($frozenThrough !== null && $monthStart <= $frozenThrough)
        ) {
            return $unchanged;
        }
        $result = [];
        foreach ($rows as $row) {
            $result[] = (int) ($row['id'] ?? 0) === (int) $covering['id']
                ? ['effective_to' => self::previousDay($monthStart)] + $row
                : $row;
        }

        return ['rows' => $result, 'changed' => true, 'current' => $covering, 'warning' => null];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    public static function covering(array $rows, string $date): ?array
    {
        $best = null;
        foreach ($rows as $row) {
            $from = (string) $row['effective_from'];
            $to = self::text($row['effective_to'] ?? null);
            if ($from > $date || ($to !== null && $to < $date)) {
                continue;
            }
            if ($best === null || $from > (string) $best['effective_from']) {
                $best = $row;
            }
        }

        return $best;
    }

    public static function previousDay(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,?string> $values
     * @param list<string> $compare
     */
    private static function same(array $row, array $values, array $compare): bool
    {
        foreach ($compare as $field) {
            if (self::text($row[$field] ?? null) !== self::text($values[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,?string> $values
     * @return array<string,mixed>
     */
    private static function newRow(array $values, string $from, ?string $to): array
    {
        return ['id' => null, 'effective_from' => $from, 'effective_to' => $to, 'evidence_note' => null] + $values;
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
