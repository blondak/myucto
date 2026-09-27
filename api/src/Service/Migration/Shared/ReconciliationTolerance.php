<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Tolerance, se kterými převody z cizích účetních programů porovnávají MyÚčto se zdrojem.
 * Jediné místo těch čísel: rekonciliace, kontrola proti podáním i párování úhrad podle
 * částky se musí shodnout na tom, co je „stejná částka".
 */
final class ReconciliationTolerance
{
    /**
     * Shoda na haléř. Částky jsou ve float a zaokrouhlené na haléře; rozdíl menší než půl
     * haléře je šum aritmetiky, ne rozdíl. Porovnává se `abs(a - b) < CENT` (shoda),
     * resp. `>= CENT` (rozdíl).
     */
    public const CENT = 0.005;

    /**
     * Zaokrouhlení podání na celé koruny (kontrolní hlášení, přiznání k DPPO): zdrojový
     * program zaokrouhluje částky podání jinak než MyÚčto, rozdíl do koruny je
     * zaokrouhlení, ne neshoda.
     */
    public const FILING_ROUNDING = 1.0;

    /**
     * Haléřové zaokrouhlení obratové předvahy (K1): když se od zdroje liší KAŽDÝ účet
     * nejvýše o tuto částku (včetně), jde o zaokrouhlení dokladů ve zdrojovém programu,
     * ne o chybějící nebo špatně převedený zápis. Protokol ho ukáže jako upozornění
     * `rounding_difference`; větší rozdíl je rozdíl k přijetí.
     */
    public const ROUNDING_DIFFERENCE = 1.0;

    /** Částky se shodují na haléř. */
    public static function sameCent(float $a, float $b): bool
    {
        return abs($a - $b) < self::CENT;
    }

    /** Částka je na haléř nulová. */
    public static function isZeroCent(float $amount): bool
    {
        return abs($amount) < self::CENT;
    }

    /** Rozdíl je nejvýše {@see ROUNDING_DIFFERENCE} včetně (na haléř). */
    public static function isRounding(float $difference): bool
    {
        return abs($difference) < self::ROUNDING_DIFFERENCE + self::CENT;
    }
}
