<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Service\Export\ClosingPackageService;
use PHPUnit\Framework\TestCase;

/**
 * EP-6: klasifikace stavu uzávěrkového balíčku — POVINNÉ jádro vs. doplňkové části.
 * Akceptační kritérium: balíček bez POVINNÉ části (mj. bez inventarizace rozvahových
 * účtů) NENÍ `completed`, i kdyby vznikl aspoň jeden soubor.
 */
final class ClosingPackageStatusTest extends TestCase
{
    /** Vše vyžádané se povedlo, žádná upozornění → completed. */
    public function testAllProducedNoWarningsIsCompleted(): void
    {
        $requested = ClosingPackageService::ALL_PARTS;
        $produced = ClosingPackageService::ALL_PARTS;
        $res = ClosingPackageService::classifyStatus($requested, $produced, false);
        $this->assertSame('completed', $res['status']);
        $this->assertSame([], $res['missing_required']);
    }

    /** Povinné jádro OK, ale doplňková část selhala (warning) → completed_with_warnings. */
    public function testRequiredCoreOkOptionalFailedIsCompletedWithWarnings(): void
    {
        $requested = ClosingPackageService::ALL_PARTS;
        // Vypadla doplňková 'dph_book' (nesestavena), ale povinné jádro je celé.
        $produced = array_values(array_diff(ClosingPackageService::ALL_PARTS, ['dph_book']));
        $res = ClosingPackageService::classifyStatus($requested, $produced, true);
        $this->assertSame('completed_with_warnings', $res['status']);
        $this->assertSame([], $res['missing_required']);
    }

    /** Chybí POVINNÁ inventarizace rozvahových účtů → failed (NE completed). */
    public function testMissingBalanceInventoryIsFailed(): void
    {
        $requested = ClosingPackageService::ALL_PARTS;
        $produced = array_values(array_diff(ClosingPackageService::ALL_PARTS, ['balance_inventory']));
        $res = ClosingPackageService::classifyStatus($requested, $produced, true);
        $this->assertSame('failed', $res['status']);
        $this->assertContains('balance_inventory', $res['missing_required']);
    }

    /** Chybí jiná povinná část (rozvaha) → failed, i když vznikla spousta jiných souborů. */
    public function testMissingBalanceSheetIsFailed(): void
    {
        $requested = ClosingPackageService::ALL_PARTS;
        $produced = array_values(array_diff(ClosingPackageService::ALL_PARTS, ['balance_sheet']));
        $res = ClosingPackageService::classifyStatus($requested, $produced, false);
        $this->assertSame('failed', $res['status']);
        $this->assertContains('balance_sheet', $res['missing_required']);
    }

    /** Nevzniklo nic → failed. */
    public function testNothingProducedIsFailed(): void
    {
        $res = ClosingPackageService::classifyStatus(ClosingPackageService::ALL_PARTS, [], false);
        $this->assertSame('failed', $res['status']);
    }

    /** Vyžádané jen doplňkové části (bez povinných), všechny vznikly → completed
     *  (povinná část, která NEBYLA vyžádána, balíček neblokuje). */
    public function testOnlyOptionalRequestedAllProducedIsCompleted(): void
    {
        $requested = ['dph_book', 'saldo_over_1y', 'accruals'];
        $produced = ['dph_book', 'saldo_over_1y', 'accruals'];
        $res = ClosingPackageService::classifyStatus($requested, $produced, false);
        $this->assertSame('completed', $res['status']);
        $this->assertSame([], $res['missing_required']);
    }

    /** balance_inventory je v POVINNÉM jádru. */
    public function testBalanceInventoryIsRequired(): void
    {
        $this->assertContains('balance_inventory', ClosingPackageService::REQUIRED_PARTS);
        $this->assertContains('balance_inventory', ClosingPackageService::ALL_PARTS);
    }

    /**
     * Přehledy podle § 18/2 patří do balíčku, ale ne do povinného jádra.
     *
     * Do teď v balíčku nebyly vůbec a uzávěrka na to jen upozorňovala varováním
     * „přiložte ručně" — velké a střední ÚJ tak systém neuměl vydat úplnou závěrku.
     * Povinné ale být nesmí: mikro a malá ÚJ je přikládat nemusí a balíček by kvůli
     * nim padal na `failed`.
     */
    public function testSection18StatementsArePartOfPackageButNotRequired(): void
    {
        foreach (['cash_flow', 'equity_changes'] as $part) {
            $this->assertContains($part, ClosingPackageService::ALL_PARTS, $part . ' musí jít vygenerovat.');
            $this->assertNotContains($part, ClosingPackageService::REQUIRED_PARTS, $part . ' není povinný pro každou ÚJ.');
        }

        // A když se nevygenerují, balíček nesmí spadnout — jen varovat.
        $produced = array_values(array_diff(ClosingPackageService::ALL_PARTS, ['cash_flow', 'equity_changes']));
        $res = ClosingPackageService::classifyStatus(ClosingPackageService::ALL_PARTS, $produced, true);
        $this->assertSame('completed_with_warnings', $res['status']);
    }
}
