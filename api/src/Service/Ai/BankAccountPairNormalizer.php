<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

final class BankAccountPairNormalizer
{
    /** @return array{debit:string,credit:string} */
    public static function orient(string $direction, string $debit, string $credit): array
    {
        if (($direction === 'in' && str_starts_with($credit, '221') && !str_starts_with($debit, '221'))
            || ($direction === 'out' && str_starts_with($debit, '221') && !str_starts_with($credit, '221'))) {
            return ['debit' => $credit, 'credit' => $debit];
        }
        return ['debit' => $debit, 'credit' => $credit];
    }

    public static function hasExactlyOneBankSide(string $debit, string $credit): bool
    {
        return str_starts_with($debit, '221') !== str_starts_with($credit, '221');
    }
}
