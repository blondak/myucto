<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

final class BankAmountSearch
{
    public static function normalize(string $value): ?string
    {
        $value = preg_replace('/[\s\x{00a0}\x{202f}]/u', '', trim($value)) ?? '';
        $value = preg_replace('/^[+-]/', '', $value) ?? '';
        if (preg_match('/^\d+(?:[.,]\d{1,2})?$/D', $value) === 1) {
            return str_replace(',', '.', $value);
        }
        if (preg_match('/^\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?$/D', $value) === 1) {
            return str_replace(',', '.', str_replace('.', '', $value));
        }
        if (preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/D', $value) === 1) {
            return str_replace(',', '', $value);
        }
        return null;
    }
}
