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
}
