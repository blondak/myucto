<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

final class InvoiceRounding
{
    public const MODES = ['auto', 'none', 'whole_czk'];

    public static function normalize(mixed $mode): string
    {
        if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Neplatný způsob zaokrouhlení faktury.');
        }
        return $mode;
    }

    public static function adjustment(float $payable, string $mode, string $currency, string $paymentMethod, string $invoiceType): float
    {
        self::normalize($mode);
        if ($currency !== 'CZK' || $paymentMethod === 'card'
            || !in_array($invoiceType, ['invoice', 'credit_note'], true)
            || $mode === 'none' || ($mode === 'auto' && $paymentMethod !== 'cash')) {
            return 0.0;
        }
        $payable = round($payable, 2, PHP_ROUND_HALF_UP);
        return round(round($payable, 0, PHP_ROUND_HALF_UP) - $payable, 2, PHP_ROUND_HALF_UP);
    }
}
