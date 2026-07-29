<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

/**
 * Rozpouštění vedlejších pořizovacích nákladů (§49/1 vyhl. 500/2002 — doprava,
 * clo, provize) do ceny řádků příjemky. ČISTÁ funkce (unit-testovatelná).
 *
 * Alokace `by_value` (default) nebo `by_qty`; haléřový zbytek po zaokrouhlení jde
 * DETERMINISTICKY na řádek s nejvyšší hodnotou (`value`), při shodě na nejnižší
 * index. Σ rozpuštěných nákladů == Σ zadaných částek (přesně, v haléřích).
 */
final class LandedCostAllocator
{
    /**
     * @param list<array{value:int,qty:int}>            $lines řádky příjemky: hodnota (haléře) + množství (tisíciny)
     * @param list<array{amount:int,allocation:string}> $costs vedlejší náklady: částka (haléře) + báze
     * @return list<int> extra_cost per řádek (haléře) v pořadí vstupu; součet == Σ amount
     */
    public static function allocate(array $lines, array $costs): array
    {
        $n = count($lines);
        $out = array_fill(0, $n, 0);
        if ($n === 0) {
            return $out;
        }

        // Řádek s nejvyšší hodnotou (cíl pro haléřový zbytek); shoda → nejnižší index.
        $valueTargetIdx = 0;
        for ($i = 1; $i < $n; $i++) {
            if ((int) $lines[$i]['value'] > (int) $lines[$valueTargetIdx]['value']) {
                $valueTargetIdx = $i;
            }
        }

        foreach ($costs as $c) {
            $amount = (int) $c['amount'];
            if ($amount <= 0) {
                // Nulový/záporný náklad nealokujeme (floor-matematika níže vyžaduje
                // nezáporné vstupy; záporné vedlejší náklady v1 nepodporujeme).
                continue;
            }
            $basisKey = (($c['allocation'] ?? 'by_value') === 'by_qty') ? 'qty' : 'value';
            $basis = [];
            $total = 0;
            for ($i = 0; $i < $n; $i++) {
                $b = (int) $lines[$i][$basisKey];
                $basis[$i] = $b;
                $total += $b;
            }

            $alloc = array_fill(0, $n, 0);
            if ($total <= 0) {
                // Degenerovaná báze (samé nuly) → rovnoměrně, zbytek na první řádky.
                $base = intdiv($amount, $n);
                for ($i = 0; $i < $n; $i++) {
                    $alloc[$i] = $base;
                }
                $rem = $amount - $base * $n;
                for ($i = 0; $i < $rem; $i++) {
                    $alloc[$i]++;
                }
            } else {
                // FLOOR podíly (intdiv, nezáporné vstupy) → součet ≤ amount, takže
                // haléřový zbytek je VŽDY nezáporný a jde celý na řádek s nejvyšší
                // hodnotou. Tím je zaručeno 0 ≤ alloc ≤ amount na každém řádku —
                // extra_cost nemůže být záporný (review MEDIUM 5).
                $sum = 0;
                for ($i = 0; $i < $n; $i++) {
                    $alloc[$i] = intdiv($amount * $basis[$i], $total);
                    $sum += $alloc[$i];
                }
                $alloc[$valueTargetIdx] += $amount - $sum; // zbytek ≥ 0
            }

            for ($i = 0; $i < $n; $i++) {
                $out[$i] += $alloc[$i];
            }
        }

        return $out;
    }
}
