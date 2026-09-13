<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Hodnota jedné buňky tak, jak ji sešit uložil — bez přepočtu vzorců.
 *
 * Chyba vzorce (`#REF!`…) i vzorec bez uložené hodnoty jsou `error`, nikdy nula;
 * prázdná buňka je `empty`, taky nikdy nula.
 */
final class AttendanceCell
{
    public const EMPTY = 'empty';
    public const NUMBER = 'number';
    public const STRING = 'string';
    public const BOOL = 'bool';
    public const ERROR = 'error';

    public const NO_CACHED_VALUE = 'bez uložené hodnoty';

    private function __construct(
        public readonly string $kind,
        public readonly ?float $number,
        public readonly ?string $text,
        public readonly ?string $format,
        public readonly bool $formula,
    ) {
    }

    public static function empty(): self
    {
        return new self(self::EMPTY, null, null, null, false);
    }

    public static function number(float $value, ?string $format = null, bool $formula = false): self
    {
        return new self(self::NUMBER, $value, null, $format, $formula);
    }

    public static function string(string $value, bool $formula = false): self
    {
        return new self(self::STRING, null, $value, null, $formula);
    }

    public static function bool(bool $value, bool $formula = false): self
    {
        return new self(self::BOOL, $value ? 1.0 : 0.0, null, null, $formula);
    }

    public static function error(string $code, bool $formula = false): self
    {
        return new self(self::ERROR, null, $code, null, $formula);
    }

    public function isEmpty(): bool
    {
        return $this->kind === self::EMPTY
            || ($this->kind === self::STRING && trim((string) $this->text) === '');
    }

    /**
     * Formát trvání nebo data s časem: `[h]:mm`, `h:mm`, `d.m.yyyy h:mm`.
     * Literály v uvozovkách a barvy v hranatých závorkách se nepočítají.
     */
    public function hasDurationFormat(): bool
    {
        if ($this->kind !== self::NUMBER || $this->format === null) {
            return false;
        }
        $format = strtolower($this->format);
        $format = (string) preg_replace('/"[^"]*"/', '', $format);
        $format = (string) preg_replace('/\[(?![hms]+\])[^\]]*\]/', '', $format);

        return str_contains($format, 'h') && (str_contains($format, ':') || str_contains($format, '[h'));
    }

    /** Text pro náhled a pro sloupce se jménem, číslem nebo poznámkou. */
    public function textValue(): string
    {
        return match ($this->kind) {
            self::STRING, self::ERROR => trim((string) $this->text),
            self::BOOL => $this->number === 1.0 ? 'PRAVDA' : 'NEPRAVDA',
            self::NUMBER => $this->numberText(),
            default => '',
        };
    }

    public function display(): string
    {
        if ($this->kind === self::NUMBER && $this->hasDurationFormat()) {
            $millihours = AttendanceDecimal::durationMillihours((float) $this->number);
            $minutes = intdiv(abs($millihours) * 60 + 500, 1000);

            return ($millihours < 0 ? '-' : '') . intdiv($minutes, 60) . ':'
                . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
        }
        $text = $this->textValue();

        return mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '…' : $text;
    }

    private function numberText(): string
    {
        $number = (float) $this->number;
        if (floor($number) === $number && abs($number) < 1e15) {
            return (string) (int) $number;
        }

        return AttendanceDecimal::fromFloat($number, 6);
    }
}
