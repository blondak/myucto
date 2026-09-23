<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/** Jediný PHP výklad částky řádku deníku včetně červeného storna. */
final class JournalLineAmount
{
    /** @param array{amount:float|int|string,is_red_storno?:bool|int} $line */
    public static function signed(array $line): float
    {
        $amount = (float) $line['amount'];
        return !empty($line['is_red_storno']) ? -$amount : $amount;
    }

    /** @param array{amount:float|int|string,is_red_storno?:bool|int} $line */
    public static function signedCents(array $line): int
    {
        return (int) round(self::signed($line) * 100.0);
    }

    /**
     * Protizápis běžného řádku obrátí stranu. Červený řádek už je záporný,
     * proto jej vyruší kladný řádek na stejné straně.
     *
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    public static function reversal(array $line): array
    {
        if (empty($line['is_red_storno'])) {
            $line['side'] = $line['side'] === 'debit' ? 'credit' : 'debit';
        }
        $line['is_red_storno'] = false;
        return $line;
    }
}
