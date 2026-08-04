<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use InvalidArgumentException;

enum RoundingMode: string
{
    case HalfUp = 'half-up';
    case Floor = 'floor';
    case Ceil = 'ceil';
    case TowardZero = 'toward-zero';
    case AwayFromZero = 'away-from-zero';

    public function roundFraction(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Rounding denominator must be positive.');
        }

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;
        if ($remainder === 0) {
            return $quotient;
        }

        $direction = $numerator <=> 0;

        return match ($this) {
            self::TowardZero => $quotient,
            self::AwayFromZero => $quotient + $direction,
            self::Floor => $numerator < 0 ? $quotient - 1 : $quotient,
            self::Ceil => $numerator > 0 ? $quotient + 1 : $quotient,
            self::HalfUp => abs($remainder) >= $denominator - abs($remainder)
                ? $quotient + $direction
                : $quotient,
        };
    }
}
