<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Payroll;

use MyInvoice\Service\Accounting\Payroll\WithholdingTaxCalculator as W;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Srážková daň ze samostatného základu — § 6 odst. 4, § 7 odst. 6 a § 36 ZDP.
 *
 * Matice daní z příjmů to vedla mezi vysokými riziky: „firmy na DPP musí mzdy počítat
 * mimo systém". Mzdový modul znal jen zálohovou daň ze závislé činnosti.
 *
 * Dvě věci, které testy hlídají především:
 *   1. Překročení limitu není „o kolik víc" — daní se běžným režimem CELÁ částka,
 *      ne jen část nad limitem ({@see testOverLimitTaxesWholeAmountNormally()}).
 *   2. Podepsané prohlášení srážku VYLUČUJE i pod limitem. Bez té podmínky by se
 *      zaměstnanci s prohlášením upřely slevy na dani.
 */
final class WithholdingTaxCalculatorTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $c = [
        'withholding_rate' => 0.15,
        'dpp_withholding_limit' => 10000,
        'author_fee_withholding_limit' => 10000,
    ];

    /** DPP do limitu bez prohlášení → srážka 15 %. */
    public function testDppUnderLimitWithoutDeclarationIsWithheld(): void
    {
        self::assertTrue(W::applies(W::REASON_DPP, 9000.0, $this->c));

        $r = W::compute(W::REASON_DPP, 9000.0, $this->c);

        self::assertSame(9000, $r['base']);
        self::assertSame(1350, $r['tax'], '15 % z 9 000.');
        self::assertSame(7650, $r['net']);
        self::assertFalse($r['insurance_applies'], 'Z DPP do limitu se pojistné neodvádí.');
    }

    /** Přesně na limitu se ještě sráží — hranice je „do", ne „pod". */
    public function testExactlyAtLimitStillWithheld(): void
    {
        self::assertTrue(W::applies(W::REASON_DPP, 10000.0, $this->c));
    }

    /**
     * Nad limitem se srážka NEUPLATNÍ VŮBEC — ani na část do limitu. Kdyby se srazilo
     * z prvních 10 000, poplatník by odvedl špatnou daň a systém by o tom mlčel.
     */
    public function testOverLimitTaxesWholeAmountNormally(): void
    {
        self::assertFalse(W::applies(W::REASON_DPP, 10001.0, $this->c));

        $msg = W::overLimitReason(W::REASON_DPP, 10001.0, $this->c);
        self::assertNotNull($msg);
        self::assertStringContainsString('celá částka', $msg);
    }

    /** Nad limitem výpočet odmítne počítat, místo aby vrátil tiše špatné číslo. */
    public function testComputeRefusesOverLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        W::compute(W::REASON_DPP, 15000.0, $this->c);
    }

    /**
     * Podepsané prohlášení srážku vylučuje i pod limitem — jinak by se zaměstnanci
     * upřely slevy na dani, na které má nárok.
     */
    public function testSignedDeclarationExcludesWithholding(): void
    {
        self::assertFalse(W::applies(W::REASON_DPP, 9000.0, $this->c, taxDeclarationSigned: true));
    }

    /** Autorský honorář do limitu (§ 7/6) — prohlášení tu roli nehraje. */
    public function testAuthorFeeUnderLimitIsWithheld(): void
    {
        $r = W::compute(W::REASON_AUTHOR_FEE, 8000.0, $this->c, taxDeclarationSigned: true);

        self::assertSame(1200, $r['tax']);
    }

    /** Nerezident (§ 36) nemá limit — rozhoduje rezidence, ne výše příjmu. */
    public function testNonResidentHasNoLimit(): void
    {
        self::assertTrue(W::applies(W::REASON_NON_RESIDENT, 500000.0, $this->c));
        self::assertNull(W::overLimitReason(W::REASON_NON_RESIDENT, 500000.0, $this->c));
    }

    /**
     * U nerezidenta se HLÁSÍ, že sazbu může měnit smlouva o zamezení dvojího zdanění.
     * Systém smlouvy nezná, takže mlčet by budilo dojem, že je sazba ověřená.
     */
    public function testNonResidentWarnsAboutTreaty(): void
    {
        $r = W::compute(W::REASON_NON_RESIDENT, 100000.0, $this->c);

        self::assertStringContainsString('dvojího zdanění', implode("\n", $r['warnings']));
    }

    /**
     * Varování u DPP musí zmínit OBĚ mezery, ne jen tu, kterou systém zavřít nemůže.
     *
     * Původní text upozorňoval jen na souběh u JINÝCH zaměstnavatelů, které systém
     * nevidí, a mlčel o tom, že § 6 odst. 4 ZDP počítá ÚHRN odměn u TÉHOŽ plátce —
     * což systém vidí, ale drží jen jeden mzdový záznam na měsíc, takže úhrn musí
     * zadat uživatel. Uživatel byl tím pádem uklidněn ohledně jediné mezery, kterou
     * zavřít lze.
     */
    public function testDppWarningCoversBothAggregationRisks(): void
    {
        $w = implode("\n", W::compute(W::REASON_DPP, 9000.0, $this->c)['warnings']);

        self::assertStringContainsString('ÚHRN', $w, 'Souběh dohod u TÉHOŽ plátce.');
        self::assertStringContainsString('JINÉHO zaměstnavatele', $w, 'Souběh u jiného plátce.');
    }

    /** Základ i daň se zaokrouhlují dolů na celé koruny (§ 36 odst. 3). */
    public function testRoundsDownToWholeCrowns(): void
    {
        $r = W::compute(W::REASON_DPP, 9999.90, $this->c);

        self::assertSame(9999, $r['base']);
        self::assertSame(1499, $r['tax'], '15 % z 9 999 = 1 499,85 → dolů.');
        self::assertSame(8500, $r['net']);
    }

    /** Nulová a záporná odměna se nedaní. */
    public function testZeroOrNegativeDoesNotApply(): void
    {
        self::assertFalse(W::applies(W::REASON_DPP, 0.0, $this->c));
        self::assertFalse(W::applies(W::REASON_DPP, -100.0, $this->c));
    }

    /** Neznámý důvod se odmítne — tichý default by zdanil něco, co se zdanit nemá. */
    public function testUnknownReasonIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        W::applies('vymyslene', 5000.0, $this->c);
    }

    /** Chybějící limit v konstantách se ohlásí, místo aby se dosadila nula. */
    public function testMissingLimitInConstantsIsReported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        W::applies(W::REASON_DPP, 5000.0, ['withholding_rate' => 0.15]);
    }

    /**
     * Limit DPP NENÍ pevných 10 000 Kč. § 6 odst. 4 ZDP odkazuje na rozhodnou částku
     * pro účast na nemocenském pojištění, a ta je podle § 7a z. 187/2006 (ve znění
     * 163/2024 Sb.) 25 % průměrné mzdy zaokrouhlených DOLŮ na celých 500 Kč.
     *
     * Konstanta byla zadrátovaná na 10 000 i pro roky, kde už neplatí — odměna
     * 11 000 Kč v roce 2025 by spadla do zálohové daně a do odvodů SP+ZP, přestože
     * se má zdanit srážkou 15 % a pojistné se z ní neodvádí.
     */
    public function testDppLimitFollowsSicknessParticipationThreshold(): void
    {
        $expected = [2024 => 10_000, 2025 => 11_500, 2026 => 12_000];

        foreach ($expected as $year => $limit) {
            $c = TaxConstants::forYear($year);
            self::assertSame($limit, (int) $c['dpp_withholding_limit'], "Limit DPP pro rok {$year}.");
            self::assertTrue(W::applies(W::REASON_DPP, (float) $limit, $c), "Částka na limitu je ještě srážková ({$year}).");
            self::assertFalse(W::applies(W::REASON_DPP, $limit + 1.0, $c), "Nad limitem srážková není ({$year}).");
        }
    }

    /** Odměna 11 000 Kč je od roku 2025 srážková; při starém limitu 10 000 nebyla. */
    public function testAmountBetweenOldAndNewLimitIsWithheldFrom2025(): void
    {
        self::assertFalse(W::applies(W::REASON_DPP, 11_000.0, TaxConstants::forYear(2024)));
        self::assertTrue(W::applies(W::REASON_DPP, 11_000.0, TaxConstants::forYear(2025)));
        self::assertTrue(W::applies(W::REASON_DPP, 11_000.0, TaxConstants::forYear(2026)));
    }
}
