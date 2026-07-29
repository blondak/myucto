<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Odečty podle § 34 odst. 4 ZDP — výzkum a vývoj (§ 34a–34e, ř. 242) a odborné vzdělávání
 * (§ 34f–34h, ř. 243).
 *
 * Matice daní vedla obojí jako CHYBÍ a byl to nález s vysokým rizikem: „obojí znamená
 * zaplacenou daň navíc, kterou systém neumí ani navrhnout". Atributy `kc_ii_242`
 * a `kc_ii_243` byly v XSD připravené, ale nemapované — odpočet tedy nešlo do přiznání
 * vůbec dostat a parser ho z reálně podaného přiznání tiše odkládal do `extra`.
 *
 * Výši odpočtu systém spočítat nemůže (plyne z projektu VaV, ne z účetnictví), ale
 * POŘADÍ a STROPY ověřit umí — a právě ty jsou zdrojem chyb: ř. 243 se odečítá až od
 * základu sníženého o ř. 230 a 242 (anotace XSD), a limit darů § 20/8 se počítá ze
 * základu sníženého podle § 34, tedy až po obou odečtech.
 */
final class DppoSection34DeductionsTest extends TestCase
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

    /** @param array<string,mixed> $inputs @return array<string,mixed> */
    private function calc(array $inputs, float $vh = 1000000.0): array
    {
        return $this->calc->compute(['vh' => $vh], $inputs, $this->c);
    }

    /** @param list<array<string,mixed>> $lines */
    private static function line(array $lines, int $n): float
    {
        foreach ($lines as $l) {
            if ((int) $l['line'] === $n) {
                return (float) $l['value'];
            }
        }
        self::fail('Řádek ' . $n . ' ve výpočtu chybí.');
    }

    /** Odpočet na VaV sníží základ — do doplnění nešel zadat vůbec. */
    public function testRndDeductionReducesBase(): void
    {
        $r = $this->calc(['rnd_deduction' => 300000]);

        self::assertSame(300000.0, self::line($r['lines'], 242));
        self::assertSame(700000.0, self::line($r['lines'], 250));
        self::assertSame(147000.0, self::line($r['lines'], 290), '700 000 × 21 %.');
    }

    /** Nad výši základu se odečte jen do jeho výše a zbytek se hlásí (§ 34 odst. 5). */
    public function testRndCappedToBaseWarnsAboutCarryforward(): void
    {
        $r = $this->calc(['rnd_deduction' => 1500000]);

        self::assertSame(1000000.0, self::line($r['lines'], 242));
        self::assertSame(0.0, self::line($r['lines'], 250));
        self::assertStringContainsString('3 obdobích', implode("\n", $r['warnings']),
            'Zbytek lze uplatnit 3 roky — systém přenos needviduje a musí to říct.');
    }

    /**
     * Odborné vzdělávání se odečítá až od základu sníženého o ztrátu A o VaV
     * (anotace XSD u `kc_ii_243`). Pořadí není kosmetika: při jiném pořadí by prošel
     * odečet, na který už není základ.
     */
    public function testEducationDeductionComesAfterRnd(): void
    {
        $r = $this->calc([
            'loss_carryforward'   => 200000,
            'rnd_deduction'       => 500000,
            'education_deduction' => 400000,
        ]);

        self::assertSame(200000.0, self::line($r['lines'], 230));
        self::assertSame(500000.0, self::line($r['lines'], 242));
        // 1 000 000 − 200 000 − 500 000 = 300 000 → z 400 000 projde jen 300 000.
        self::assertSame(300000.0, self::line($r['lines'], 243));
        self::assertSame(0.0, self::line($r['lines'], 250));
    }

    /**
     * Limit darů § 20/8 se počítá ze základu sníženého PODLE § 34, tedy i o nové odečty.
     * Dokud odečty neexistovaly, byl základ po ztrátě totéž — s nimi už ne, a počítat
     * limit ze starého základu by dary nadhodnotilo.
     */
    public function testDonationCapUsesBaseAfterSection34Deductions(): void
    {
        $r = $this->calc(['rnd_deduction' => 500000, 'donations' => 300000]);

        // Základ po VaV je 500 000 → cap 30 % = 150 000 (ne 300 000 z původního milionu).
        self::assertSame(150000.0, self::line($r['lines'], 260));
        self::assertSame(350000.0, self::line($r['lines'], 270));
    }

    /** Na záporný základ se odečty neuplatní — není z čeho odčítat. */
    public function testNoDeductionAgainstNegativeBase(): void
    {
        $r = $this->calc(['rnd_deduction' => 300000, 'education_deduction' => 100000], vh: -50000.0);

        self::assertSame(0.0, self::line($r['lines'], 242));
        self::assertSame(0.0, self::line($r['lines'], 243));
        self::assertSame(0.0, $r['tax']);
    }

    /** Souhrn nese uplatněné částky, ať je odchylka od nároku dohledatelná. */
    public function testSummaryCarriesAppliedAmounts(): void
    {
        $r = $this->calc(['rnd_deduction' => 300000, 'education_deduction' => 100000]);

        self::assertSame(300000.0, $r['summary']['rnd_applied']);
        self::assertSame(100000.0, $r['summary']['education_applied']);
    }

    /**
     * Řádky musí mít kotvu v mapě atributů, jinak by se do XML nedostaly — přesně to byl
     * stav před opravou.
     */
    public function testLinesAreMappedToXsdAttributes(): void
    {
        self::assertSame('kc_ii_242', DppoXmlBuilder::LINE_ATTR[242] ?? null);
        self::assertSame('kc_ii_243', DppoXmlBuilder::LINE_ATTR[243] ?? null);
    }
}
