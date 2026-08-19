<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Sdílená peněžní pravidla pro CZK úhradu cizoměnového dokladu.
 *
 * Bankovní pohyb zůstává ve své skutečné měně a částce. Evidence platby na
 * faktuře naproti tomu pracuje v měně dokladu, takže úplná FX úhrada vypořádá
 * celý zbývající obnos, pokud se CZK pohyb vejde do přijímací tolerance.
 */
final class FxPaymentSettlement
{
    /** Tuzemská měna, ve které přichází protihodnota cizoměnové faktury. */
    public const LOCAL_CURRENCY = 'CZK';
    /** Absolutní floor tolerance pro peněžní shodu v CZK. */
    public const AMOUNT_TOLERANCE = 1.0;
    /**
     * Bankovní spread a pohyb kurzu mezi vystavením a úhradou běžně dosáhne
     * jednotek procent; 4 % ponechá rezervu, ale stále odmítne zjevnou podplatbu.
     */
    public const MATCH_TOLERANCE_PCT = 0.04;

    public static function expectedLocalAmount(float $invoiceAmount, float $rate): float
    {
        return round($invoiceAmount * ($rate > 0.0 ? $rate : 1.0), 2);
    }

    public static function matchTolerance(
        float $expectedAmount,
        float $absoluteTolerance = self::AMOUNT_TOLERANCE,
    ): float {
        return max($absoluteTolerance, abs($expectedAmount) * self::MATCH_TOLERANCE_PCT);
    }

    public static function isCzkPaymentOfForeignInvoice(
        ?string $transactionCurrency,
        string $invoiceCurrency,
    ): bool {
        return $transactionCurrency !== null
            && strtoupper($transactionCurrency) === self::LOCAL_CURRENCY
            && strtoupper($invoiceCurrency) !== self::LOCAL_CURRENCY;
    }

    public static function isFullCzkSettlement(
        float $transactionAmount,
        float $remaining,
        string $invoiceCurrency,
        float $rate,
        ?string $transactionCurrency,
    ): bool {
        if ($transactionAmount <= 0.0 || $remaining <= 0.0 || $rate <= 0.0
            || !self::isCzkPaymentOfForeignInvoice($transactionCurrency, $invoiceCurrency)
        ) {
            return false;
        }
        $expected = self::expectedLocalAmount($remaining, $rate);
        return abs($transactionAmount - $expected) <= self::matchTolerance($expected);
    }

    public static function amountInInvoiceCurrency(
        float $transactionAmount,
        string $invoiceCurrency,
        float $rate,
        ?string $transactionCurrency,
        float $fallback,
        bool $settleRemaining = false,
    ): float {
        $invoiceCurrency = strtoupper($invoiceCurrency);
        if ($transactionCurrency === null || strtoupper($transactionCurrency) === $invoiceCurrency) {
            return round($transactionAmount, 2);
        }
        if ($settleRemaining) {
            return round($fallback, 2);
        }
        if (strtoupper($transactionCurrency) === self::LOCAL_CURRENCY) {
            return round($transactionAmount / ($rate > 0.0 ? $rate : 1.0), 2);
        }
        return round($fallback, 2);
    }
}
