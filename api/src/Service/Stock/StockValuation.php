<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

/**
 * Vážený aritmetický klouzavý průměr — jediná oceňovací metoda v1 (§49/3 vyhl.
 * 500/2002, ČÚS 015 bod 3.6). ČISTÁ, bezstavová matematika v celočíselných
 * jednotkách, aby šla přesně unit-testovat a nekumulovala plovoucí chybu:
 *
 *   - množství (DECIMAL 14,3) drží jako `qtyT` = tisíciny (int),
 *   - hodnota   (DECIMAL 15,2) drží jako `valueC` = haléře (int) — ZDROJ PRAVDY,
 *   - avg_unit_cost (DECIMAL 15,6) je odvozený = value_total / qty (jen pro čtení).
 *
 * Zaokrouhlení probíhá jen na hranici (haléře) při každém pohybu; protože se
 * ukládá přesná haléřová hodnota, chyba se nekumuluje.
 *
 * Invariant výdeje celého zůstatku: qtyOut == qty ⇒ řádek vydá PŘESNĚ valueC
 * (žádný haléřový sediment) a stav klesne na 0/0.
 *
 * Meze (64-bit int): `divRound` zdvojnásobuje čitatele, takže `issue()` vyžaduje
 * valueC × qtyOutT ≤ ~4,6e18 (karta do ~10 mil. Kč a ~5 mil. MJ), `avgUnitCostMicro`
 * omezuje hodnotu karty na ~4,6 mld Kč. Pro malou firmu (cíl v1) bezpečné; při
 * překročení `intdiv` selže TypeError (hlučně, bez tiché koruze) — ne tichý přetok.
 */
final class StockValuation
{
    public const QTY_SCALE = 1000; // DECIMAL(14,3) → tisíciny
    public const VAL_SCALE = 100;  // DECIMAL(15,2) → haléře
    public const MICRO      = 1_000_000; // DECIMAL(15,6)

    /**
     * Příjem na sklad.
     *
     * @param int $qtyT       aktuální stav (tisíciny)
     * @param int $valueC     aktuální hodnota (haléře) — zdroj pravdy
     * @param int $qtyInT     přijímané množství (tisíciny), > 0
     * @param int $lineValueC hodnota přijímaného zboží v haléřích (už zaokrouhlená na
     *                        2 des.) = round(qty_in × unit_cost, 2), VČETNĚ rozpuštěných
     *                        vedlejších pořizovacích nákladů (extra_cost)
     * @return array{qtyT:int,valueC:int,lineValueC:int,lineUnitCostMicro:int,avgMicro:int}
     */
    public static function receipt(int $qtyT, int $valueC, int $qtyInT, int $lineValueC): array
    {
        if ($qtyInT <= 0) {
            throw new \InvalidArgumentException('receipt qtyInT must be positive');
        }
        $newQtyT   = $qtyT + $qtyInT;
        $newValueC = $valueC + $lineValueC;

        return [
            'qtyT'              => $newQtyT,
            'valueC'            => $newValueC,
            'lineValueC'        => $lineValueC,
            'lineUnitCostMicro' => self::unitCostMicro($lineValueC, $qtyInT),
            'avgMicro'          => self::avgUnitCostMicro($newQtyT, $newValueC),
        ];
    }

    /**
     * Výdej ze skladu za klouzavý průměr. Volající MUSÍ předem ověřit dostupnost
     * (qtyOutT ≤ qtyT) — tady je tvrdý guard proti zápornému stavu (A3).
     *
     * @param int $qtyOutT vydávané množství (tisíciny), > 0
     * @return array{qtyT:int,valueC:int,lineValueC:int,lineUnitCostMicro:int,avgMicro:int}
     */
    public static function issue(int $qtyT, int $valueC, int $qtyOutT): array
    {
        if ($qtyOutT <= 0) {
            throw new \InvalidArgumentException('issue qtyOutT must be positive');
        }
        if ($qtyOutT > $qtyT) {
            // Pojistka — orchestrátor kontroluje dostupnost dřív a hlásí přívětivě
            // s výčtem chybějících položek; sem se to nesmí dostat.
            throw new \InvalidArgumentException('issue would create negative stock');
        }

        if ($qtyOutT === $qtyT) {
            // Výdej celého zůstatku — přesně, bez sedimentu.
            $lineValueC = $valueC;
            $newQtyT    = 0;
            $newValueC  = 0;
        } else {
            $lineValueC = self::divRound($valueC * $qtyOutT, $qtyT);
            $newQtyT    = $qtyT - $qtyOutT;
            $newValueC  = $valueC - $lineValueC;
        }

        return [
            'qtyT'              => $newQtyT,
            'valueC'            => $newValueC,
            'lineValueC'        => $lineValueC,
            'lineUnitCostMicro' => self::unitCostMicro($lineValueC, $qtyOutT),
            'avgMicro'          => self::avgUnitCostMicro($newQtyT, $newValueC),
        ];
    }

    /** avg_unit_cost v mikrojednotkách (DECIMAL 15,6) = value_total / qty. 0 při qty=0. */
    public static function avgUnitCostMicro(int $qtyT, int $valueC): int
    {
        if ($qtyT <= 0) {
            return 0;
        }
        // CZK/MJ = (valueC/100) / (qtyT/1000) = valueC*10 / qtyT ; ×1e6 (micro):
        return self::divRound($valueC * 10 * self::MICRO, $qtyT);
    }

    /** Jednotková cena řádku v micro (= value/qty), 0 při qty=0. */
    private static function unitCostMicro(int $lineValueC, int $qtyT): int
    {
        return self::avgUnitCostMicro($qtyT, $lineValueC);
    }

    /** Celočíselné dělení se zaokrouhlením half-up (nezáporný numerator, kladný jmenovatel). */
    private static function divRound(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            return 0;
        }
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    // ── Konverze DB DECIMAL ⇄ celočíselné jednotky ──────────────────────────────

    /** DECIMAL(14,3) string/float → tisíciny (int). */
    public static function qtyToT(string|float|int $qty): int
    {
        return (int) round((float) $qty * self::QTY_SCALE);
    }

    /** DECIMAL(15,2) string/float → haléře (int). */
    public static function valueToC(string|float|int $value): int
    {
        return (int) round((float) $value * self::VAL_SCALE);
    }

    /** Haléře (int) → přesný DECIMAL(15,2) string (bez plovoucí chyby). */
    public static function cToDecimal(int $valueC): string
    {
        $sign = $valueC < 0 ? '-' : '';
        $abs  = abs($valueC);
        return sprintf('%s%d.%02d', $sign, intdiv($abs, self::VAL_SCALE), $abs % self::VAL_SCALE);
    }

    /** Tisíciny (int) → přesný DECIMAL(14,3) string. */
    public static function tToDecimal(int $qtyT): string
    {
        $sign = $qtyT < 0 ? '-' : '';
        $abs  = abs($qtyT);
        return sprintf('%s%d.%03d', $sign, intdiv($abs, self::QTY_SCALE), $abs % self::QTY_SCALE);
    }

    /** Micro (int) → přesný DECIMAL(15,6) string. */
    public static function microToDecimal(int $micro): string
    {
        $sign = $micro < 0 ? '-' : '';
        $abs  = abs($micro);
        return sprintf('%s%d.%06d', $sign, intdiv($abs, self::MICRO), $abs % self::MICRO);
    }
}
