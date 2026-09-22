<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Formát údajů do poznámek a hlášek převzatých mezd (česky, bez závislosti na locale).
 */
final class PayrollTakeoverFormat
{
    /** `2026-03-05` => `5. 3. 2026`. */
    public static function czechDate(string $iso): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', substr($iso, 0, 10)) + [0, 0, 0]);
        return "{$d}. {$m}. {$y}";
    }

    /** `2026-03` => `3/2026`. */
    public static function czechPeriod(string $period): string
    {
        return (int) substr($period, 5, 2) . '/' . substr($period, 0, 4);
    }

    /** Číslo do věty protokolu: desetinná čárka, bez zbytečných nul. */
    public static function decimal(float $value): string
    {
        $text = number_format($value, 2, ',', ' ');
        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }

    /** Neprázdný oříznutý text, jinak `null`. */
    public static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
