<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\ClosingProjectionCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Jednotkové testy projekce závěrkových operací do VH (Feature 1, Epic DP). Ověřuje směr
 * (+/−) a zahrnutí dopadů do vh_projected: 381 defer (+), fx zisk−ztráta (±), rozpuštění 381
 * minulého období (−); informativní návrhy (opravné položky, dohady) se do vh_projected NEpočítají.
 */
final class ClosingProjectionCalculatorTest extends TestCase
{
    private ClosingProjectionCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new ClosingProjectionCalculator();
    }

    /** Sanity dle zadání (FY2024): 5 685 370 + §DM 90 140 + prepaid 41 128 = 5 816 638. */
    public function testDeferralsRaiseProjectedVhTowardsSanityTarget(): void
    {
        $r = $this->calc->project(5685370.0, [
            'small_asset' => ['existing' => null, 'total' => 90140.0],
            'prepaid' => ['existing' => null, 'total' => 41128.0],
        ]);
        self::assertTrue($r['is_projection']);
        self::assertSame(5685370.0, $r['vh_posted']);
        self::assertSame(5816638.0, $r['vh_projected']);
        self::assertCount(2, $r['items']);
        self::assertSame(1, $r['items'][0]['sign']);
    }

    /** Už zaúčtované 381 (existing != null) se do projekce NEzahrne (jinak dvojí započtení). */
    public function testPostedDeferralsAreSkipped(): void
    {
        $r = $this->calc->project(1000000.0, [
            'small_asset' => ['existing' => ['entry_id' => 5], 'total' => 90140.0],
            'prepaid' => ['existing' => null, 'total' => 41128.0],
        ]);
        self::assertSame(1041128.0, $r['vh_projected']);
        self::assertCount(1, $r['items']);
        self::assertSame('prepaid_expense_accrual', $r['items'][0]['key']);
    }

    /** Kurzové rozdíly: zisk 663 (+) − ztráta 563 (−). Čistá ztráta → −VH. */
    public function testFxNetLossReducesProjectedVh(): void
    {
        $r = $this->calc->project(1000000.0, [
            'fx' => ['totals' => ['gain' => 2000.0, 'loss' => 5000.0]],
        ]);
        self::assertSame(997000.0, $r['vh_projected']);
        self::assertSame('fx_revaluation', $r['items'][0]['key']);
        self::assertSame(-1, $r['items'][0]['sign']);
        self::assertSame(3000.0, $r['items'][0]['amount']);
    }

    /** Rozpuštění 381 minulého období zvyšuje náklad → −VH. */
    public function testPriorReleaseReducesProjectedVh(): void
    {
        $r = $this->calc->project(1000000.0, [
            'prior_release' => ['applicable' => true, 'total' => 25000.0],
        ]);
        self::assertSame(975000.0, $r['vh_projected']);
        self::assertSame('prior_deferral_release', $r['items'][0]['key']);
        self::assertSame(-1, $r['items'][0]['sign']);
    }

    /** Opravné položky a dohady jsou INFORMATIVNÍ — zobrazí se, ale do vh_projected nevstupují. */
    public function testProvisionsAndEstimatesAreOptionalAndExcludedFromProjectedVh(): void
    {
        $r = $this->calc->project(1000000.0, [
            'provisions' => ['totals' => ['suggested_legal' => 50000.0, 'existing_legal' => 10000.0]],
            'estimates' => ['totals' => ['suggested_amount' => 8000.0]],
        ]);
        // Žádný neinformativní dopad → není to „projekce“ VH, číslo se nemění.
        self::assertFalse($r['is_projection']);
        self::assertSame(1000000.0, $r['vh_projected']);
        self::assertCount(2, $r['items']);
        foreach ($r['items'] as $it) {
            self::assertTrue($it['optional']);
            self::assertSame(-1, $it['sign']);
        }
        // Opravná položka = jen pending část nad rámec už vytvořených OP (50 000 − 10 000).
        $prov = array_values(array_filter($r['items'], static fn ($i) => $i['key'] === 'provision'))[0];
        self::assertSame(40000.0, $prov['amount']);
    }

    /** Prázdné zdroje → žádná projekce, vh_projected == vh_posted. */
    public function testEmptySourcesYieldNoProjection(): void
    {
        $r = $this->calc->project(1234.56, []);
        self::assertFalse($r['is_projection']);
        self::assertSame(1234.56, $r['vh_projected']);
        self::assertSame([], $r['items']);
    }
}
