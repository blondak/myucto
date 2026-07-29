<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * B11 (audit 2026-07) — force-edit brána notes_only přijaté faktury (PF).
 *
 * Regrese: exchange_rate nebyl ve FINANCIAL_FIELDS ani se nekontroloval, přitom
 * updateDraft() zapisuje kurz z body při každém update a PostingService z něj počítá
 * CZK (total_with_vat * rate). Ruční změna kurzu pod force_mode=notes_only tak rozešla
 * doklad × deník (zápis v uzavřeném období drží starý kurz). Fix: numerické porovnání
 * kurzu ve financialFieldsChanged() — reálná změna blokuje notes_only, formátová neshoda
 * (25.00 vs 25) legitimní notes_only neblokuje.
 *
 * Čistě unit — financialFieldsChanged() je pure static predikát (bez DB), voláme přes reflexi.
 */
final class PfFinancialFieldsChangedTest extends TestCase
{
    private static function decide(array $body, array $existing): array
    {
        $ref = new ReflectionMethod(UpdatePurchaseInvoiceAction::class, 'financialFieldsChanged');
        /** @var array<int,string> $out */
        $out = $ref->invoke(null, $body, $existing);
        return $out;
    }

    public function testExchangeRateChangeIsFinancialChange(): void
    {
        $existing = ['exchange_rate' => 25.0, 'note_above_items' => 'x'];

        $changed = self::decide(['exchange_rate' => 30.0, 'note_above_items' => 'y'], $existing);
        self::assertContains('exchange_rate', $changed, 'Změna kurzu 25 → 30 je účetní změna → blokuje notes_only.');
    }

    public function testExchangeRateFormatMismatchIsNotChange(): void
    {
        $existing = ['exchange_rate' => 25.0, 'note_above_items' => 'x'];

        $same = self::decide(['exchange_rate' => '25.00', 'note_above_items' => 'y'], $existing);
        self::assertNotContains('exchange_rate', $same, 'Formátová neshoda kurzu (25.00 vs 25) neblokuje notes_only.');
    }

    public function testExchangeRateAbsentIsNotChange(): void
    {
        $existing = ['exchange_rate' => 25.0, 'note_above_items' => 'x'];

        $noRate = self::decide(['note_above_items' => 'y'], $existing);
        self::assertNotContains('exchange_rate', $noRate, 'Kurz v payloadu není → žádná změna.');
    }
}
