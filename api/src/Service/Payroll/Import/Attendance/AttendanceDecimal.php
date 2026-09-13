<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Převody čísel z podkladů na celočíselné jednotky (millihodiny, haléře).
 *
 * Nikde se nenásobí float bez zaokrouhlení: float z Excelu se nejdřív
 * předzaokrouhlí na pevný počet desetinných míst jako text a dál se počítá
 * v celých číslech. `round(x * 100)` nad floatem umí na hraně haléře
 * ujet o jednotku.
 */
final class AttendanceDecimal
{
    /**
     * České i anglické zápisy čísla: „71 875,00 Kč", „149,6", „1.234,50", „-3.5".
     * Vrací normalizovaný desetinný řetězec, nebo null, když hodnota číslo není.
     */
    public static function parseNumber(string $value): ?string
    {
        $value = str_replace(["\u{00A0}", "\u{202F}", "\u{2007}"], ' ', trim($value));
        $value = (string) preg_replace('/\s*(kč|czk|hod\.?|h)\s*$/iu', '', $value);
        $value = str_replace(' ', '', $value);
        if ($value === '') {
            return null;
        }
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $value, $m) === 1) {
            $negative = true;
            $value = $m[1];
        }
        if (str_starts_with($value, '-') || str_starts_with($value, '−')) {
            $negative = !$negative;
            $value = ltrim(ltrim($value, '-'), '−');
        } elseif (str_starts_with($value, '+')) {
            $value = substr($value, 1);
        }
        if (preg_match('/^\d{1,3}(\.\d{3})+,\d+$/', $value) === 1) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (preg_match('/^\d{1,3}(,\d{3})+\.\d+$/', $value) === 1) {
            $value = str_replace(',', '', $value);
        } elseif (preg_match('/^\d+,\d+$/', $value) === 1) {
            $value = str_replace(',', '.', $value);
        } elseif (preg_match('/^\d{1,3}(\.\d{3}){2,}$/', $value) === 1) {
            $value = str_replace('.', '', $value);
        }
        if (preg_match('/^(\d+)(?:\.(\d*))?$/', $value, $parts) !== 1 || strlen($parts[1]) > 15) {
            return null;
        }
        $fraction = rtrim($parts[2] ?? '', '0');
        $integer = ltrim($parts[1], '0');
        $integer = $integer === '' ? '0' : $integer;
        $result = $fraction === '' ? $integer : $integer . '.' . $fraction;

        return $negative && $result !== '0' ? '-' . $result : $result;
    }

    /**
     * Časový zápis „8:00", „36:30", „-1:15", „7:30:00" → millihodiny.
     * Hodiny se nezkracují na čas dne, 36:30 je 36,5 h.
     */
    public static function parseClockMillihours(string $value): ?int
    {
        $value = trim(str_replace(["\u{00A0}", ' '], '', $value));
        if (preg_match('/^([-−]?)(\d{1,5}):([0-5]\d)(?::([0-5]\d))?$/u', $value, $m) !== 1) {
            return null;
        }
        $seconds = ((int) $m[2]) * 3600 + ((int) $m[3]) * 60 + (int) ($m[4] ?? 0);
        $millihours = intdiv($seconds * 1000 + 1800, 3600);

        return $m[1] !== '' ? -$millihours : $millihours;
    }

    /**
     * Desetinný řetězec → celé číslo v dané škále se zaokrouhlením half-up
     * (od nuly). Škála 3 = millihodiny z hodin, 2 = haléře z korun.
     */
    public static function scaled(string $decimal, int $scale): int
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $decimal, $m) !== 1 || strlen($m[2]) > 15) {
            throw new \InvalidArgumentException("Hodnota „{$decimal}“ není podporované číslo.");
        }
        $fraction = str_pad($m[3] ?? '', $scale + 1, '0');
        $kept = (int) ($m[2] . substr($fraction, 0, $scale));
        if ((int) $fraction[$scale] >= 5) {
            ++$kept;
        }

        return $m[1] === '-' && $kept !== 0 ? -$kept : $kept;
    }

    /** Float z buňky → desetinný řetězec předzaokrouhlený na `$decimals` míst. */
    public static function fromFloat(float $value, int $decimals): string
    {
        if (!is_finite($value) || abs($value) >= 1e15) {
            throw new \InvalidArgumentException('Číslo v buňce je mimo podporovaný rozsah.');
        }
        $text = sprintf('%.' . $decimals . 'F', $value);

        return self::parseNumber($text) ?? '0';
    }

    /**
     * Excelové trvání je zlomek dne. 1,5 = 36 h — nikdy čas dne modulo 24.
     * Den se předzaokrouhlí na 9 míst (nanodny) a násobí se v celých číslech.
     */
    public static function durationMillihours(float $days): int
    {
        $nanoDays = self::scaled(self::fromFloat($days, 9), 9);
        $negative = $nanoDays < 0;
        $product = abs($nanoDays) * 24;
        $millihours = intdiv($product + 500_000, 1_000_000);

        return $negative ? -$millihours : $millihours;
    }

    public static function formatMillihours(int $millihours): string
    {
        $negative = $millihours < 0;
        $centi = intdiv(abs($millihours) + 5, 10);
        $text = intdiv($centi, 100) . '.' . str_pad((string) ($centi % 100), 2, '0', STR_PAD_LEFT);

        return $negative && $centi !== 0 ? '-' . $text : $text;
    }

    public static function formatMinor(int $minor): string
    {
        $negative = $minor < 0;
        $abs = abs($minor);
        $text = intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return $negative ? '-' . $text : $text;
    }
}
