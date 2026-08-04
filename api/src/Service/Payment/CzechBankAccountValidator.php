<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment;

final class CzechBankAccountValidator
{
    /**
     * @return array{
     *   canonical:string,
     *   account_number:string,
     *   bank_code:string,
     *   prefix:?string,
     *   base:string
     * }
     */
    public function parse(string $raw): array
    {
        $compact = preg_replace('/\s+/u', '', trim($raw));
        if (!is_string($compact)
            || preg_match(
                '/^(?:(\d{1,6})-)?(\d{2,10})\/(\d{4})$/D',
                $compact,
                $match,
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Český účet musí mít formát [předčíslí-]číslo/kód banky.',
            );
        }

        $prefix = $match[1];
        $base = $match[2];
        if (($prefix !== '' && !$this->validPart($prefix, 6))
            || !$this->validPart($base, 10)
        ) {
            throw new \InvalidArgumentException(
                'Český bankovní účet neprošel kontrolou modulo 11.',
            );
        }

        $normalizedBase = ltrim($base, '0');
        if ($normalizedBase === '') {
            throw new \InvalidArgumentException(
                'Číslo bankovního účtu nesmí být nulové.',
            );
        }
        $normalizedPrefix = ltrim($prefix, '0');
        $accountNumber = ($normalizedPrefix !== ''
            ? $normalizedPrefix . '-'
            : '') . $normalizedBase;

        return [
            'canonical' => $accountNumber . '/' . $match[3],
            'account_number' => $accountNumber,
            'bank_code' => $match[3],
            'prefix' => $normalizedPrefix !== '' ? $normalizedPrefix : null,
            'base' => $normalizedBase,
        ];
    }

    public function normalize(string $raw): string
    {
        return $this->parse($raw)['canonical'];
    }

    private function validPart(string $value, int $length): bool
    {
        $weights = $length === 6
            ? [10, 5, 8, 4, 2, 1]
            : [6, 3, 7, 9, 10, 5, 8, 4, 2, 1];
        $digits = str_pad($value, $length, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split($digits) as $index => $digit) {
            $sum += (int) $digit * $weights[$index];
        }

        return $sum % 11 === 0;
    }
}
