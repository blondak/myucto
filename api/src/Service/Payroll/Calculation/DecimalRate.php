<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use InvalidArgumentException;
use JsonSerializable;
use OverflowException;

final readonly class DecimalRate implements JsonSerializable
{
    private function __construct(
        public int $numerator,
        public int $scale,
        public int $denominator,
        private string $canonical,
    ) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Rate must be a canonical decimal string.');
        }

        $negative = $matches[1] === '-';
        $integerPart = $matches[2];
        $fractionPart = rtrim($matches[3] ?? '', '0');
        $scale = strlen($fractionPart);
        $denominator = self::powerOfTen($scale);
        $digits = ltrim($integerPart . $fractionPart, '0');

        if ($digits === '') {
            return new self(0, 0, 1, '0');
        }

        self::assertMagnitudeFitsInteger($digits, $negative);
        $minimumMagnitude = ltrim((string) PHP_INT_MIN, '-');
        $numerator = $negative && $digits === $minimumMagnitude
            ? PHP_INT_MIN
            : (int) $digits * ($negative ? -1 : 1);

        $canonical = ($negative ? '-' : '') . $integerPart;
        if ($fractionPart !== '') {
            $canonical .= '.' . $fractionPart;
        }

        return new self($numerator, $scale, $denominator, $canonical);
    }

    public function toCanonicalString(): string
    {
        return $this->canonical;
    }

    /** @return array{decimal:string,numerator:int,scale:int,denominator:int} */
    public function jsonSerialize(): array
    {
        return [
            'decimal' => $this->canonical,
            'numerator' => $this->numerator,
            'scale' => $this->scale,
            'denominator' => $this->denominator,
        ];
    }

    public function toCanonicalJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }

    private static function powerOfTen(int $scale): int
    {
        $denominator = 1;
        for ($i = 0; $i < $scale; $i++) {
            if ($denominator > intdiv(PHP_INT_MAX, 10)) {
                throw new OverflowException('Rate scale exceeds the integer range.');
            }
            $denominator *= 10;
        }

        return $denominator;
    }

    private static function assertMagnitudeFitsInteger(string $digits, bool $negative): void
    {
        $maximum = $negative ? ltrim((string) PHP_INT_MIN, '-') : (string) PHP_INT_MAX;
        if (
            strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)
        ) {
            throw new OverflowException('Rate numerator exceeds the integer range.');
        }
    }
}
