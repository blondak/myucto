<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Minimalistický vyhodnocovač pětipolových cron výrazů — „sedí tenhle výraz na
 * tuhle minutu?".
 *
 * Používá ho {@see CronDispatcher} k rozhodnutí, které úlohy z {@see CronCatalog}
 * jsou v aktuální minutě na řadě. Záměrně NEUMÍ plánovat dopředu ani počítat
 * „next run" — dispatcher se budí každou minutu a ptá se jen na přítomnost.
 *
 * Podporovaná syntax (pokrývá celý katalog i běžné ruční úpravy):
 *   *          každá hodnota
 *   5          konkrétní hodnota
 *   1-5        rozsah
 *   1,3,15     výčet
 *   * / 15     krok přes celý rozsah      (bez mezery)
 *   0-30/10    krok přes rozsah
 *
 * NEPODPORUJE zkratky @daily, jména měsíců/dnů (JAN, MON) ani nestandardní
 * rozšíření (L, W, #). Katalog je nepoužívá a tichá špatná interpretace by byla
 * horší než výjimka — proto se na neznámém tvaru hází {@see InvalidArgumentException}.
 */
final class CronExpression
{
    private const RANGES = [
        0 => [0, 59],   // minuta
        1 => [0, 23],   // hodina
        2 => [1, 31],   // den v měsíci
        3 => [1, 12],   // měsíc
        4 => [0, 7],    // den v týdnu (0 i 7 = neděle)
    ];

    /**
     * Sedí výraz na danou minutu?
     *
     * @throws InvalidArgumentException na nepodporovaný tvar výrazu
     */
    public static function matches(string $expression, DateTimeImmutable $when): bool
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($fields) !== 5) {
            throw new InvalidArgumentException("Cron výraz musí mít 5 polí: '{$expression}'");
        }

        $minute = (int) $when->format('i');
        $hour   = (int) $when->format('G');
        $dom    = (int) $when->format('j');
        $month  = (int) $when->format('n');
        $dow    = (int) $when->format('w'); // 0 = neděle

        if (!self::fieldMatches($fields[0], 0, $minute)) {
            return false;
        }
        if (!self::fieldMatches($fields[1], 1, $hour)) {
            return false;
        }
        if (!self::fieldMatches($fields[3], 3, $month)) {
            return false;
        }

        // Standardní cron zvláštnost: když jsou omezené OBĚ pole (den v měsíci
        // i den v týdnu), platí OR, ne AND — `0 0 1 * 1` znamená „prvního
        // v měsíci NEBO každé pondělí". Je-li omezené jen jedno, rozhoduje ono.
        $domRestricted = trim($fields[2]) !== '*';
        $dowRestricted = trim($fields[4]) !== '*';

        $domOk = self::fieldMatches($fields[2], 2, $dom);
        $dowOk = self::dowMatches($fields[4], $dow);

        if ($domRestricted && $dowRestricted) {
            return $domOk || $dowOk;
        }
        if ($domRestricted) {
            return $domOk;
        }
        if ($dowRestricted) {
            return $dowOk;
        }
        return true;
    }

    /** Ověří, že výraz je syntakticky zpracovatelný (pro testy a validaci katalogu). */
    public static function assertValid(string $expression): void
    {
        // Vyhodnocení proti libovolnému okamžiku projde všemi poli a vyhodí
        // výjimku na neznámém tvaru.
        self::matches($expression, new DateTimeImmutable('2026-01-01 00:00:00'));
    }

    /**
     * Neděle je v cronu 0 i 7. `date('w')` vrací 0, takže při porovnání
     * musíme uznat obě podoby, jinak by `0 0 * * 7` nikdy nesedělo.
     */
    private static function dowMatches(string $field, int $dow): bool
    {
        if (self::fieldMatches($field, 4, $dow)) {
            return true;
        }
        return $dow === 0 && self::fieldMatches($field, 4, 7);
    }

    private static function fieldMatches(string $field, int $fieldIndex, int $value): bool
    {
        [$min, $max] = self::RANGES[$fieldIndex];

        foreach (explode(',', trim($field)) as $part) {
            $part = trim($part);
            if ($part === '') {
                throw new InvalidArgumentException("Prázdná položka v cron poli: '{$field}'");
            }

            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $stepRaw] = explode('/', $part, 2);
                if (!ctype_digit($stepRaw) || (int) $stepRaw < 1) {
                    throw new InvalidArgumentException("Neplatný krok v cron poli: '{$field}'");
                }
                $step = (int) $stepRaw;
                $part = trim($part);
            }

            if ($part === '*') {
                $from = $min;
                $to = $max;
            } elseif (str_contains($part, '-')) {
                [$fromRaw, $toRaw] = explode('-', $part, 2);
                if (!ctype_digit(trim($fromRaw)) || !ctype_digit(trim($toRaw))) {
                    throw new InvalidArgumentException("Neplatný rozsah v cron poli: '{$field}'");
                }
                $from = (int) trim($fromRaw);
                $to = (int) trim($toRaw);
            } elseif (ctype_digit($part)) {
                $from = $to = (int) $part;
            } else {
                throw new InvalidArgumentException("Nepodporovaný tvar cron pole: '{$field}'");
            }

            if ($from < $min || $to > $max || $from > $to) {
                throw new InvalidArgumentException("Cron pole mimo povolený rozsah {$min}-{$max}: '{$field}'");
            }

            if ($value < $from || $value > $to) {
                continue;
            }
            if (($value - $from) % $step === 0) {
                return true;
            }
        }

        return false;
    }
}
