<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax;

use MyInvoice\Service\Tax\DpfoCalculator;
use MyInvoice\Service\Tax\Return\DpfoReturnCalculator;
use MyInvoice\Service\Tax\Return\SocialInsuranceCalculator;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Regresní parita optimalizátoru ({@see DpfoCalculator}) s ostrým přiznáním /
 * přehledy — audit 2026-07, Fáze E4 „jeden zdroj pravdy". Chytá budoucí drift
 * limitů §15 a pravidel pojistného vedlejší činnosti mezi poradním nástrojem a
 * finálním výpočtem, i když kód není fyzicky sdílený.
 */
final class DpfoCalculatorParityTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $c;

    protected function setUp(): void
    {
        $this->c = TaxConstants::forYear(2025);
    }

    /**
     * §15 odpočty: profil s vysokým životním pojištěním + penzijkem (společný strop §15a
     * 48 000) + úroky (cap 150k) + dary → optimalizátor (deductions15) a ostré přiznání
     * (DpfoReturnCalculator ř. 54) dají IDENTICKÝ §15 odpočet.
     */
    public function testSection15DeductionsMatchReturnCalculator(): void
    {
        $profile = [
            'mortgage_interest' => 200_000,   // → cap 150 000
            'pension_contrib'   => 40_000,    // → 40 000 (do stropu 48k)
            'life_insurance'    => 30_000,    // → jen 8 000 (společný strop 48k − 40k penzijko)
            'donations'         => 100_000,   // ≥ min 1 000 / 2 % a ≤ 30 % ZD
            'spouse_credit'     => false,
            'children_count'    => 0,
        ];

        // Stejný §7 základ na obou stranách: příjmy − výdaje = 1 000 000 (= ř. 45 v přiznání).
        $section7Base = 1_000_000.0;
        $optDeduction = DpfoCalculator::deductions15($profile, $this->c, $section7Base);

        $return = (new DpfoReturnCalculator())->compute(
            ['s7_income' => 1_600_000, 's7_expenses' => 600_000, 's7_base' => 1_000_000, 'expense_mode' => 'actual'],
            [],
            $profile,
            $this->c,
        );

        // ř. 54 (kc_odcelk) = úroky 150 000 + penzijko 40 000 + životko 8 000 + dary 100 000.
        self::assertSame(298_000.0, $optDeduction);
        self::assertSame(298_000.0, $return['fields']['kc_odcelk']);
        self::assertSame($return['fields']['kc_odcelk'], $optDeduction);
    }

    /**
     * Pojistné vedlejší činnosti pod rozhodnou částkou: optimalizátor (insurance) i přehled
     * (SocialInsuranceCalculator) dají STEJNÉ (nulové) sociální pojištění; zdravotní bez min. VZ.
     */
    public function testSecondaryBelowThresholdSocialIsZeroBothSides(): void
    {
        $profit = 50_000.0; // < rozhodná částka 111 736 (2025)

        $ins = DpfoCalculator::insurance($profit, true, $this->c);
        $social = (new SocialInsuranceCalculator())->compute($profit, true, 0.0, false, null, $this->c);

        self::assertSame(0.0, $ins['social']);
        self::assertSame(0.0, $social['insurance']);
        self::assertSame($social['insurance'], $ins['social']);

        // Zdravotní u vedlejší bez spodní hranice: 25 000 (50 %) × 13,5 % = 3 375.
        self::assertSame(3_375.0, $ins['health']);
    }
}
