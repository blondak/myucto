<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Service\Invoice\TimeBilling;

final class TimeBillingExport
{
    /** @param array<string,mixed> $item */
    public static function durationMinutes(array $item): ?int
    {
        return TimeBilling::durationMinutes($item);
    }

    /** @param array<string,mixed> $item */
    public static function quantity(array $item): float
    {
        $minutes = self::durationMinutes($item);
        return $minutes === null
            ? (float) ($item['quantity'] ?? 1)
            : $minutes / 60;
    }

    /** @param array<string,mixed> $item */
    public static function formatQuantity(array $item, string $legacy): string
    {
        $minutes = self::durationMinutes($item);
        if ($minutes === null) {
            return $legacy;
        }

        return self::trimmed($minutes / 60, 12, 0);
    }

    /** @param array<string,mixed> $item */
    public static function usesPreciseHourlyRate(array $item): bool
    {
        return self::durationMinutes($item) !== null
            || ((!isset($item['stock_item_id']) || (int) $item['stock_item_id'] <= 0)
                && TimeBilling::isHourUnit($item['unit'] ?? null)
                && abs((float) ($item['unit_price_without_vat'] ?? 0) - round((float) ($item['unit_price_without_vat'] ?? 0), 2)) > 0.0000001);
    }

    public static function formatRate(float $rate): string
    {
        return self::trimmed($rate, 6, 2);
    }

    private static function trimmed(float $value, int $maxDecimals, int $minDecimals): string
    {
        $formatted = number_format($value, $maxDecimals, '.', '');
        if ($maxDecimals > $minDecimals) {
            $formatted = rtrim($formatted, '0');
            $decimalPos = strpos($formatted, '.');
            $decimals = $decimalPos === false ? 0 : strlen($formatted) - $decimalPos - 1;
            if ($decimals < $minDecimals) {
                if ($decimalPos === false) {
                    $formatted .= '.';
                }
                $formatted .= str_repeat('0', $minDecimals - $decimals);
            }
            if ($minDecimals === 0) {
                $formatted = rtrim($formatted, '.');
            }
        }

        return $formatted === '-0' ? '0' : $formatted;
    }
}
