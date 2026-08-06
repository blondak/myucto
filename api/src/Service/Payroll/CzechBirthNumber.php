<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Normalizace a kontrola českého rodného čísla.
 *
 * Vytaženo z PayrollPersonProfileValidator, aby stejné pravidlo mohla zavolat
 * i evidence vyživovaných osob (MZ-04-W05) — privátní helper uvnitř jedné
 * validace se okopíruje rychleji, než kdyby neexistoval.
 */
final class CzechBirthNumber
{
    /** @return string kanonický tvar RRMMDD/XXXX */
    public static function normalize(string $value): string
    {
        self::rejectMaskPlaceholder($value);
        $digits = (string) preg_replace('/\D/', '', $value);
        if (!preg_match('/^\d{9,10}$/', $digits)) {
            throw new InvalidArgumentException('Rodné číslo musí mít 9 nebo 10 číslic.');
        }

        $yearPart = (int) substr($digits, 0, 2);
        $month = (int) substr($digits, 2, 2);
        $day = (int) substr($digits, 4, 2);
        foreach ([70, 50, 20] as $offset) {
            if ($month > $offset) {
                $month -= $offset;
                break;
            }
        }
        $year = strlen($digits) === 9 || $yearPart >= 54
            ? 1900 + $yearPart
            : 2000 + $yearPart;
        if (!checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('Rodné číslo neobsahuje platné datum narození.');
        }
        $birthDate = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        if ($birthDate > new DateTimeImmutable('today')) {
            throw new InvalidArgumentException('Rodné číslo nesmí obsahovat budoucí datum narození.');
        }
        if (strlen($digits) === 9) {
            if ($year >= 1954) {
                throw new InvalidArgumentException('Devítimístné rodné číslo je přípustné jen před rokem 1954.');
            }
        } else {
            $number = (int) $digits;
            $legacyException = $year < 1985
                && ((int) substr($digits, 0, 9)) % 11 === 10
                && (int) substr($digits, -1) === 0;
            if ($number % 11 !== 0 && !$legacyException) {
                throw new InvalidArgumentException('Rodné číslo neprošlo kontrolou modulo 11.');
            }
        }

        return substr($digits, 0, 6) . '/' . substr($digits, 6);
    }

    /** @return string datum narození ve tvaru Y-m-d odvozené z rodného čísla */
    public static function birthDate(string $normalized): string
    {
        $digits = (string) preg_replace('/\D/', '', $normalized);
        $yearPart = (int) substr($digits, 0, 2);
        $month = (int) substr($digits, 2, 2);
        $day = (int) substr($digits, 4, 2);
        foreach ([70, 50, 20] as $offset) {
            if ($month > $offset) {
                $month -= $offset;
                break;
            }
        }
        $year = strlen($digits) === 9 || $yearPart >= 54
            ? 1900 + $yearPart
            : 2000 + $yearPart;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public static function rejectMaskPlaceholder(string $value): void
    {
        if (str_contains($value, '•') || preg_match('/\*{3,}/u', $value) === 1) {
            throw new InvalidArgumentException('Hodnota nesmí obsahovat maskovaný údaj.');
        }
    }
}
