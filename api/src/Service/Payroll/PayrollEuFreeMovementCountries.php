<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/**
 * Státy EU, EHP a Švýcarsko — jediný seznam pro mzdy.
 *
 * Jejich občan má volný přístup na trh práce (§ 87 zákona č. 435/2004 Sb.,
 * v REGZEC důvod volného přístupu „1 — Občan EU/EHP a Švýcarska") a ve
 * zdravotním pojištění postavení občana EU (koordinační nařízení (ES)
 * č. 883/2004). Česko v seznamu není: český občan cizincem není.
 */
final class PayrollEuFreeMovementCountries
{
    /** Důvod volného přístupu na trh práce (CIS „Důvod pro volný přístup na trh práce"). */
    public const FREE_ACCESS_REASON_CODE = '1';

    /** @var list<string> */
    public const CODES = [
        'AT', 'BE', 'BG', 'CY', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR',
        'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT',
        'RO', 'SE', 'SI', 'SK',
        'IS', 'LI', 'NO', 'CH',
    ];

    public static function contains(?string $countryCode): bool
    {
        return $countryCode !== null && in_array(strtoupper($countryCode), self::CODES, true);
    }
}
