<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Expense;

/**
 * Pure heuristika pro časové rozlišení ročního předplatného (381) — bez DB, jednotkově
 * testovatelná (Automatizace 2026). Doplněk k {@see \MyInvoice\Service\Accounting\Closing\ClosingService::prepaidExpenseAccrualPreview}:
 * ta počítá, kolik z JIŽ OZNAČENÉHO období (accrual_from/to) se odloží na 381; tahle třída
 * naopak NAVRHNE ty datumy tam, kde je účetní ještě nevyplnila, u dodavatelů s pravidlem
 * příznakem `recurring_prepaid`.
 *
 * HEURISTIKA (záměrně konzervativní, každý bod je rozhodovací místo — viz PLAN):
 *   1. Faktura v DRUHÉ POLOVINĚ roku (měsíc ≥ {@see SECOND_HALF_MONTH}). Roční předplatné z 1. pololetí
 *      se do konce roku spotřebuje celé, rozlišovat není co; z 2. pololetí přesahuje do N+1.
 *   2. Období rozlišení = {@see COVERAGE_MONTHS} měsíců od data plnění (typické roční předplatné/pojistné).
 *   3. Návrh se dá JEN když skutečně přesahuje přes přelom roku (jinak není co odkládat).
 *   4. Nikdy nepřepisuje už vyplněné accrual_from/to — účetní má přednost.
 *
 * VŠE JE JEN NÁVRH. Účetní ho v editoru potvrdí (zapíše accrual_from/to na řádek), teprve pak
 * uzávěrka odloží na 381. Sama tato třída nic nezapisuje ani neúčtuje.
 */
final class RecurringPrepaidAccrualSuggester
{
    /** Měsíc, od kterého výš považujeme fakturu za „druhá polovina roku". */
    public const SECOND_HALF_MONTH = 7;

    /** Předpokládaná délka krytí ročního předplatného. */
    public const COVERAGE_MONTHS = 12;

    /**
     * @param string  $coverageStart datum plnění (Y-m-d), od kterého předplatné běží
     * @param ?string $existingFrom  už vyplněné accrual_from na řádku (NULL = nevyplněno)
     * @param ?string $existingTo    už vyplněné accrual_to na řádku
     * @return array{from:string,to:string}|null NULL = nekvalifikuje se / nechat na účetní
     */
    public static function suggest(
        string $coverageStart,
        ?string $existingFrom = null,
        ?string $existingTo = null,
        int $coverageMonths = self::COVERAGE_MONTHS,
    ): ?array {
        // Účetní už rozlišení určila — nepřepisuj.
        if ($existingFrom !== null || $existingTo !== null) {
            return null;
        }
        if ($coverageMonths <= 0) {
            return null;
        }
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($coverageStart, 0, 10));
        if ($start === false) {
            return null;
        }
        // Jen 2. pololetí — z 1. pololetí se roční krytí do konce roku spotřebuje.
        if ((int) $start->format('n') < self::SECOND_HALF_MONTH) {
            return null;
        }
        $to = $start->modify('+' . $coverageMonths . ' months')->modify('-1 day');
        // Musí skutečně přesáhnout do dalšího roku, jinak není co odkládat.
        if ($to->format('Y') === $start->format('Y')) {
            return null;
        }
        return ['from' => $start->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    }
}
