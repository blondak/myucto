<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\CzechBirthNumber;

/**
 * Věková hranice zaměstnance v registračním podání (EDV 1.4.0.6).
 *
 * Kontrolu „Datum narození x Datum nástupu" (REGZEC, ID 10056 a 10223) i
 * kontrolu „Rodné číslo x Předpokládané datum nástupu" (PREZEC P1) dělá ČSSZ
 * na vstupu a podání zamítne, je-li zaměstnanci k nástupu méně než 14 let.
 * Od EDV 1.4.0.6 bez výjimky pro dohody (změna JMHZ-3970). Hranice žije jen
 * tady, ať ji všechny cesty čtou stejně.
 */
final class PayrollRegistrationMinimumAge
{
    public const YEARS = 14;

    /** True, je-li k `$onDate` zaměstnanci méně než 14 let (obě data RRRR-MM-DD). */
    public static function isUnderage(string $birthDate, string $onDate): bool
    {
        $birth = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
        $on = \DateTimeImmutable::createFromFormat('!Y-m-d', $onDate);
        if ($birth === false || $on === false
            || $birth->format('Y-m-d') !== $birthDate
            || $on->format('Y-m-d') !== $onDate
        ) {
            return false;
        }

        return $birth->modify('+' . self::YEARS . ' years') > $on;
    }

    /**
     * Datum narození odvozené z rodného čísla (čísla bez lomítka i s ním).
     * EČP datum nenese, takže pro něj a pro nesmyslnou hodnotu vrací null.
     */
    public static function birthDateFromBirthNumber(?string $birthNumber): ?string
    {
        if ($birthNumber === null || trim($birthNumber) === '') {
            return null;
        }
        try {
            return CzechBirthNumber::birthDate(
                CzechBirthNumber::normalize($birthNumber),
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
