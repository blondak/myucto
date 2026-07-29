<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoEpoXmlParser;
use MyInvoice\Service\Tax\Return\DppoReconciliationDiffBuilder;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Jednotkové testy výpočtu DPPO (Epic DP, issue #18). Hodnoty ručně dopočtené.
 * Pokrývá: kladný/záporný VH, oba směry rozdílu odpisů, ztráta §34, dary s capem,
 * zaokrouhlení ↓1000, sleva §35, zálohy dle prahů §38a.
 */
final class DppoReturnCalculatorTest extends TestCase
{
    private DppoReturnCalculator $calc;

    /** @var array<string,mixed> */
    private array $c = [
        'corporate_tax_rate' => 0.21,
        'donation_cap_po_pct' => 0.30,
        'disabled_employee_credit' => 18000,
        'disabled_employee_credit_severe' => 60000,
        'advance_threshold_low' => 30000,
        'advance_threshold_high' => 150000,
        'rounding_base_po' => 1000,
    ];

    protected function setUp(): void
    {
        $this->calc = new DppoReturnCalculator();
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $inputs @return array<string,mixed> */
    private function calcRun(array $data, array $inputs): array
    {
        return $this->calc->compute($data, $inputs, $this->c);
    }

    /** @param list<array<string,mixed>> $lines */
    private static function lineValue(array $lines, int $n): float
    {
        foreach ($lines as $l) {
            if ($l['line'] === $n) {
                return (float) $l['value'];
            }
        }
        throw new \RuntimeException("Řádek $n nenalezen");
    }

    public function testComprehensiveScenario(): void
    {
        $r = $this->calcRun(
            [
                'vh' => 500000,
                'non_deductible_costs' => 20000,
                'disposal_nondeductible_residual' => 10000,
                'depreciation' => ['tax' => 40000, 'accounting' => 100000],
            ],
            [
                'manual_increase_items' => [['text' => 'x', 'amount' => 5000]],
                'manual_decrease_items' => [['text' => 'y', 'amount' => 15000]],
                'loss_carryforward' => 100000,
                'donations' => 200000,
                'disabled_employees_avg' => 1,
                'tax_paid_advances' => 20000,
            ]
        );
        $L = $r['lines'];
        self::assertSame(30000.0, self::lineValue($L, 40));   // 20000 + 10000
        self::assertSame(60000.0, self::lineValue($L, 50));   // účetní 100k − daňové 40k
        self::assertSame(0.0, self::lineValue($L, 150));
        self::assertSame(580000.0, self::lineValue($L, 200)); // 500+30+60+5−15 tis
        self::assertSame(100000.0, self::lineValue($L, 230)); // ztráta plně
        self::assertSame(480000.0, self::lineValue($L, 250));
        self::assertSame(144000.0, self::lineValue($L, 260)); // cap 30 % z 480k
        self::assertSame(336000.0, self::lineValue($L, 270)); // 480−144, zaokr. tis.
        self::assertSame(70560.0, self::lineValue($L, 290));  // 336000 × 0.21
        self::assertSame(18000.0, self::lineValue($L, 300));  // 1 zaměstnanec se ZP
        self::assertSame(52560.0, self::lineValue($L, 340));  // 70560 − 18000
        self::assertSame(52560.0, self::lineValue($L, 360));  // poslední známá daň pro §38a
        self::assertSame(32560.0, $r['balance_due']);
        self::assertSame(52560.0, $r['tax']);
        // Zálohy §38a: 52 560 v pásmu 30–150 tis → 2 pololetní po 40 % = 21 100 (↑100).
        self::assertSame('semiannual', $r['next_advances']['regime']);
        self::assertSame(21100.0, $r['next_advances']['amount']);
        self::assertNotEmpty($r['warnings']); // dary nadlimit
    }

    public function testNegativeVhProducesLossWarningAndZeroTax(): void
    {
        $r = $this->calcRun(['vh' => -50000], ['loss_carryforward' => 100000]);
        self::assertSame(-50000.0, self::lineValue($r['lines'], 200));
        self::assertSame(0.0, self::lineValue($r['lines'], 230), 'Ztráta se na zápornou základnu neuplatní.');
        self::assertSame(0.0, self::lineValue($r['lines'], 270));
        self::assertSame(0.0, $r['tax']);
        self::assertSame('none', $r['next_advances']['regime']);
        self::assertNotEmpty($r['warnings']);
    }

    public function testDepreciationDecreaseLowersBase(): void
    {
        $r = $this->calcRun(['vh' => 200000, 'depreciation' => ['tax' => 100000, 'accounting' => 40000]], []);
        self::assertSame(60000.0, self::lineValue($r['lines'], 150)); // daňové > účetní
        self::assertSame(0.0, self::lineValue($r['lines'], 50));
        self::assertSame(140000.0, self::lineValue($r['lines'], 200));
        self::assertSame(29400.0, $r['tax']); // 140000 × 0.21
        self::assertSame('none', $r['next_advances']['regime']); // 29 400 ≤ 30 000
    }

    public function testRoundingDownToThousands(): void
    {
        $r = $this->calcRun(['vh' => 123456], []);
        self::assertSame(123000.0, self::lineValue($r['lines'], 270));
        self::assertSame(25830.0, $r['tax']); // 123000 × 0.21
    }

    public function testLossCappedToBase(): void
    {
        $r = $this->calcRun(['vh' => 30000], ['loss_carryforward' => 100000]);
        self::assertSame(30000.0, self::lineValue($r['lines'], 230), 'Ztráta jen do výše základu.');
        self::assertSame(0.0, self::lineValue($r['lines'], 250));
        self::assertSame(0.0, $r['tax']);
        // Zbytek ztráty k převodu → warning.
        self::assertNotEmpty($r['warnings']);
    }

    public function testAdvancesQuarterlyAboveHighThreshold(): void
    {
        $r = $this->calcRun(['vh' => 2000000], []);
        self::assertSame(420000.0, $r['tax']); // 2M × 0.21
        self::assertSame('quarterly', $r['next_advances']['regime']);
        self::assertSame(105000.0, $r['next_advances']['amount']); // 25 % z 420k
        self::assertSame(420000.0, $r['next_advances']['total']);
    }

    public function testSevereDisabledCredit(): void
    {
        $r = $this->calcRun(['vh' => 1000000], ['disabled_employees_avg' => 2, 'disabled_employees_severe_avg' => 1]);
        // 2×18000 + 1×60000 = 96000
        self::assertSame(96000.0, self::lineValue($r['lines'], 300));
        self::assertSame(210000.0 - 96000.0, $r['tax']);
    }

    public function testCreditsCannotMakeTaxNegative(): void
    {
        $r = $this->calcRun(['vh' => 50000], ['disabled_employees_avg' => 100]);
        self::assertSame(0.0, $r['tax'], 'Slevy §35 nemohou daň stlačit pod nulu.');
        self::assertSame(10500.0, self::lineValue($r['lines'], 300), 'Ř. 300 je omezen daní z ř. 290.');
        self::assertSame(1800000.0, $r['summary']['credits_entitlement']);
    }

    public function testLossYearKeepsLine250NonNegative(): void
    {
        $r = $this->calcRun(['vh' => -50000], []);
        self::assertSame(-50000.0, self::lineValue($r['lines'], 200));
        self::assertSame(0.0, self::lineValue($r['lines'], 250));
    }

    public function testDisposalResidualBridgeAdjustsBaseBothWays(): void
    {
        $increase = $this->calcRun(['vh' => 100000, 'disposal_tax_increase' => 20000], []);
        self::assertSame(20000.0, self::lineValue($increase['lines'], 62));
        self::assertSame(120000.0, self::lineValue($increase['lines'], 200));

        $decrease = $this->calcRun(['vh' => 100000, 'disposal_tax_decrease' => 30000], []);
        self::assertSame(30000.0, self::lineValue($decrease['lines'], 162));
        self::assertSame(70000.0, self::lineValue($decrease['lines'], 200));
    }

    public function testDonationItemsExcludeBelow2000(): void
    {
        // E7 (audit 2026-07): §20/8 — PO odečte jen dary v hodnotě ≥ 2 000 Kč.
        // Položky: 5 000 + 2 000 (uznatelné) + 1 500 + 500 (pod limitem → vyloučit).
        $r = $this->calcRun(
            ['vh' => 1000000],
            ['donation_items' => [
                ['text' => 'Nadace A', 'amount' => 5000],
                ['text' => 'Spolek B', 'amount' => 2000],
                ['text' => 'Malý dar C', 'amount' => 1500],
                ['text' => 'Drobnost D', 'amount' => 500],
            ]],
        );
        self::assertSame(7000.0, self::lineValue($r['lines'], 260), 'Odečet jen 5000 + 2000.');
        self::assertNotEmpty($r['warnings']);
        $joined = implode("\n", $r['warnings']);
        self::assertStringContainsString('2 000 Kč', $joined);
    }

    public function testAggregateDonationsEmitsMinimumWarning(): void
    {
        $r = $this->calcRun(['vh' => 1000000], ['donations' => 10000]);
        self::assertSame(10000.0, self::lineValue($r['lines'], 260), 'Agregát se použije beze změny.');
        $joined = implode("\n", $r['warnings']);
        self::assertStringContainsString('2 000 Kč', $joined, 'Varování k ověření minima daru.');
    }

    // ── §38a splatnosti záloh — kotví se na ZAČÁTEK zálohového období (ends_on + 1) ──

    public function testAdvanceDueDatesCalendarYear(): void
    {
        // Kalendářní rok → čtvrtletní zálohy 15. 3./6./9./12.
        $r = $this->calcRun(['vh' => 2000000, 'period' => ['starts_on' => '2024-01-01', 'ends_on' => '2024-12-31']], []);
        self::assertSame('quarterly', $r['next_advances']['regime']);
        self::assertStringContainsString('15. 3., 15. 6., 15. 9. a 15. 12.', $r['next_advances']['note']);
    }

    public function testAdvanceDueDatesFiscalYearShifted(): void
    {
        // Hospodářský rok 1. 7. 2024 – 30. 6. 2025 → zálohové období začíná 1. 7.,
        // čtvrtletní splatnosti 3./6./9./12. měsíce období = 15. 9./12./3./6.
        $r = $this->calcRun(['vh' => 2000000, 'period' => ['starts_on' => '2024-07-01', 'ends_on' => '2025-06-30']], []);
        self::assertSame('quarterly', $r['next_advances']['regime']);
        self::assertStringContainsString('15. 9., 15. 12., 15. 3. a 15. 6.', $r['next_advances']['note']);
    }

    public function testAdvanceDueDatesShortenedPeriodFollowsNextCalendarYear(): void
    {
        // Zkrácené první období 15. 3. – 31. 12. 2024: zálohy NEsmí být posunuté podle
        // března (starts_on), ale navazovat na následující řádný kalendářní rok (ends_on+1
        // = 1. 1.) → 15. 3./6./9./12. Regrese na starý bug „odvozeno z počátku období".
        $r = $this->calcRun(['vh' => 2000000, 'period' => ['starts_on' => '2024-03-15', 'ends_on' => '2024-12-31']], []);
        self::assertStringContainsString('15. 3., 15. 6., 15. 9. a 15. 12.', $r['next_advances']['note']);
    }

    public function testAdvanceDatesSemiannualCalendar(): void
    {
        // Pásmo 30–150 tis → 2 pololetní zálohy, kalendářní rok = 15. 6. a 15. 12.
        $r = $this->calcRun(['vh' => 300000, 'period' => ['starts_on' => '2024-01-01', 'ends_on' => '2024-12-31']], []);
        self::assertSame('semiannual', $r['next_advances']['regime']);
        self::assertStringContainsString('15. 6. a 15. 12.', $r['next_advances']['note']);
    }

    public function testMissingPeriodWarnsAboutAssumedCalendarAdvances(): void
    {
        // Bez období se předpokládá kalendářní rok → warning, ať to není tiché.
        $r = $this->calcRun(['vh' => 2000000], []);
        self::assertSame('quarterly', $r['next_advances']['regime']);
        self::assertNotEmpty(array_filter($r['warnings'], static fn ($w) => str_contains($w, '§38a')));
    }

    /**
     * Feature 1 — projekce: z podkladů s closing_projection se dopočte PROJEKTOVANÁ daň z
     * projektovaného VH, aniž se změní posted řádky. Základ jen z VH (žádné jiné úpravy):
     * posted 500 000 → daň 105 000 (× 21 %); projektovaný 600 000 → daň 126 000.
     */
    public function testProjectionDerivesProjectedTaxFromProjectedVh(): void
    {
        $r = $this->calcRun(
            [
                'vh' => 500000,
                'closing_projection' => [
                    'is_projection' => true,
                    'vh_posted' => 500000.0,
                    'vh_projected' => 600000.0,
                    'items' => [
                        ['key' => 'small_asset_accrual', 'label_key' => 'x', 'amount' => 90000.0, 'sign' => 1, 'optional' => false],
                    ],
                ],
            ],
            []
        );
        // Posted daň beze změny.
        self::assertSame(105000.0, $r['tax']);
        self::assertNotNull($r['projection']);
        self::assertTrue($r['projection']['is_projection']);
        self::assertSame(600000.0, $r['projection']['vh_projected']);
        self::assertSame(600000.0, $r['projection']['projected_base']);
        self::assertSame(126000.0, $r['projection']['projected_tax']);
    }

    /** Bez projekce (nebo is_projection=false) je result['projection'] null a posted daň beze změny. */
    public function testNoProjectionKeyWhenNotProjecting(): void
    {
        $r = $this->calcRun(['vh' => 500000], []);
        self::assertNull($r['projection']);
        self::assertSame(105000.0, $r['tax']);
    }

    // ── Úkol #41 — paušální výdaj na dopravu (§24/2/zt): řádkový rozpis ──────────
    //
    // Rekonciliace proti skutečně podanému přiznání ukázala, že základ i daň SEDÍ, ale
    // rozpis na řádky se lišil: účetní dala paušál dopravy (45 000) na ř.170/112 (ne
    // obecné ř.162) a odpovídající add-back PHM CELÝ na ř.40 spolu s ostatními
    // nedaňovými náklady, ne rozdělený mezi ř.40/62. Ruční položky nemají
    // typovaný kód (jen text) — rozpoznávají se podle klíčových slov „paušál"+„doprav".

    public function testFlatRateTravelExpenseMapsToLine170And112NotLine62Or162(): void
    {
        $r = $this->calcRun(
            ['vh' => 3010500, 'non_deductible_costs' => 12000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            [
                'manual_increase_items' => [['text' => 'PHM přičtené zpět (uplatněn paušál na dopravu)', 'amount' => 14000]],
                'manual_decrease_items' => [['text' => 'Paušální výdaj na dopravu §24/2 zt (9 měsíců × 5 000)', 'amount' => 45000]],
            ]
        );
        $L = $r['lines'];
        self::assertSame(26000.0, self::lineValue($L, 40), 'ř.40 = celý add-back (12 000 nedaňové + 14 000 PHM), stejně jako v podaném přiznání.');
        self::assertSame(0.0, self::lineValue($L, 62), 'ř.62 už paušál dopravy (add-back PHM) neobsahuje.');
        self::assertSame(45000.0, self::lineValue($L, 112), 'ř.112 doplňková info §23/3c) — paušální výdaj na dopravu.');
        self::assertSame(0.0, self::lineValue($L, 162), 'ř.162 už paušál dopravy neobsahuje.');
        self::assertSame(45000.0, self::lineValue($L, 170), 'ř.170 souhrn snižujících = paušál dopravy.');
        self::assertSame(2991500.0, self::lineValue($L, 200), 'Základ daně beze změny.');
        self::assertSame(628110.0, $r['tax'], 'Daň beze změny.');
        $joined = implode("\n", $r['warnings']);
        self::assertStringContainsString('Paušální výdaj na dopravu', $joined);
    }

    /** Beze změny textu (obecná §23 položka) zůstává mapování na ř.62/162, jako dřív. */
    public function testNonTravelManualItemsStillMapToLine62And162(): void
    {
        $r = $this->calcRun([], [
            'manual_increase_items' => [['text' => 'Pokuta od finančního úřadu', 'amount' => 3000]],
            'manual_decrease_items' => [['text' => 'Jiná ruční položka §23', 'amount' => 4000]],
        ]);
        $L = $r['lines'];
        self::assertSame(0.0, self::lineValue($L, 40));
        self::assertSame(3000.0, self::lineValue($L, 62));
        self::assertSame(0.0, self::lineValue($L, 112));
        self::assertSame(4000.0, self::lineValue($L, 162));
        self::assertSame(4000.0, self::lineValue($L, 170), 'ř.170 souhrn i pro obecné §23 položky mimo paušál dopravy.');
    }

    /**
     * Rekonciliace ŘÁDEK PO ŘÁDKU proti skutečně podanému EPO XML (viz test výše) —
     * dřív 13/16 (ř.40/62/162 neseděly), teď musí sedět VŠECHNY tracked řádky (0 mismatch).
     */
    public function testFlatRateTravelExpenseReconcilesAllLinesAgainstFiledXml(): void
    {
        $filedXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Pisemnost nazevSW="EPO MF ČR" verzeSW="45.9.2">
<DPPDP9 verzePis="04.01">
<VetaD c_nace="620900" typ_dapdpp="M" typ_zo="A" typ_popldpp="1" c_ufo_cil="451" zdobd_od="01.01.2024" dokument="DP9" kc_v_4="-628110" dapdpp_forma="B" k_uladis="DPP" zdobd_do="31.12.2024" />
<VetaP psc="11000" zkrobchjm="Ukázková firma s.r.o." dic="12345678" naz_obce="Praha 1" rod_c="12345678" />
<VetaO kc_ii_360="628110" kc_ii_340="628110" kc_ii230_250="2991500" kc_ii270_280="21" kc_ii260_270="2991000" kc_ii300_310="628110" kc_ii50_40="26000" kc_ii190_170="45000" kc_ii_112="45000" kc_ii200_200="2991500" kc_ii320_330="628110" kc_ii280_290="628110" kc_ii10_10="3010500" kc_ii80_70="26000" kc_ii_220="2991500" d_hospvysl="31.12.2024" />
</DPPDP9>
</Pisemnost>
XML;
        $parsed = (new DppoEpoXmlParser())->parse($filedXml);

        $r = $this->calcRun(
            ['vh' => 3010500, 'non_deductible_costs' => 12000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            [
                'manual_increase_items' => [['text' => 'PHM přičtené zpět (uplatněn paušál na dopravu)', 'amount' => 14000]],
                'manual_decrease_items' => [['text' => 'Paušální výdaj na dopravu §24/2 zt (9 měsíců × 5 000)', 'amount' => 45000]],
            ]
        );

        $diff = (new DppoReconciliationDiffBuilder())->build((array) $r['lines'], $parsed['lines']);

        $mismatchDetail = implode("\n", array_map(
            static fn (array $row): string => sprintf('ř.%d: our=%s filed=%s', $row['line'], $row['our_value'], $row['filed_value']),
            array_values(array_filter($diff['rows'], static fn (array $row): bool => !$row['match']))
        ));
        self::assertSame(0, $diff['mismatched'], "Řádky, které nesedí:\n" . $mismatchDetail);
        self::assertSame(count($r['lines']), $diff['matched']);
    }
}
