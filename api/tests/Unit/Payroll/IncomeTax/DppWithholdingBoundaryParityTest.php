<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use MyInvoice\Service\Accounting\Payroll\WithholdingTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentIncomeTaxPolicy2026;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hranice srážkové daně z DPP musí být na OBOU cestách výpočtu stejná.
 *
 * ── Co se stalo ─────────────────────────────────────────────────────────────────
 * Hranice žila v systému dvakrát a pokaždé jinak:
 *   - `TaxConstants::forYear(2026)['dpp_withholding_limit']` = 12 000 Kč, testováno `<=`
 *   - ruleset `dpp.withholding.maximum` = 11 999 Kč, testováno také `<=`
 * Odměna PŘESNĚ 12 000 Kč tak dostala jiný daňový režim podle toho, kterou cestou
 * firma jela: moderní mzdový běh ({@see \MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator})
 * ji zdanil zálohou, legacy účetní zaúčtování
 * ({@see \MyInvoice\Service\Accounting\Payroll\PayrollPostingService}) srážkou. Obě
 * cesty jsou živé, takže to nebyl mrtvý kód — byla to jinak vypočtená daň, jiné
 * odvody a jiný řádek v podání.
 *
 * ── Co je správně ───────────────────────────────────────────────────────────────
 * § 6 odst. 4 písm. a) ZDP ve znění zák. č. 470/2024 Sb. (od 1. 1. 2025): srážkou se
 * daní příjem z DPP, jehož úhrnná výše u téhož plátce za kalendářní měsíc
 * „NEDOSÁHNE částky rozhodné pro účast zaměstnanců činných na základě dohody
 * o provedení práce na nemocenském pojištění". Rozhodná částka je podle § 7a
 * z. č. 187/2006 Sb. 25 % průměrné mzdy zaokrouhlených dolů na celých 500 Kč
 * (2026: 48 967 × 0,25 = 12 241,75 → 12 000 Kč) a účast na pojištění vzniká při
 * jejím DOSAŽENÍ („aspoň ve výši"). Hranice je tedy 12 000 Kč a test je OSTRÝ:
 * na 12 000 Kč se už sráží pojistné a daní se zálohou.
 *
 * Ani jedna z původních hodnot nebyla celá správně — 12 000 mělo špatný operátor,
 * 11 999 špatné číslo (a lámalo se na haléřích).
 *
 * ── Proč tenhle test ────────────────────────────────────────────────────────────
 * Staví obě cesty vedle sebe přesně na hranici a o korunu/haléř kolem ní. Rozejde-li
 * se kterákoli z nich, spadne to tady, ne až na výplatní pásce.
 */
final class DppWithholdingBoundaryParityTest extends TestCase
{
    private const YEAR = 2026;

    /** Rozhodná částka pro rok 2026 v haléřích — § 7a z. č. 187/2006 Sb. */
    private const THRESHOLD_MINOR = 1_200_000;

    /**
     * Jediný zdroj pravdy je ruleset; roční konstanty ho zrcadlí. Kdyby si
     * `TaxConstants` znovu pořídily vlastní číslo, pozná se to tady.
     */
    public function testBothPathsReadTheSameThreshold(): void
    {
        self::assertSame(
            self::THRESHOLD_MINOR,
            $this->rulesetThresholdMinor(),
            'Ruleset musí nést rozhodnou částku 12 000 Kč, ne o korunu nižší „maximum".',
        );
        self::assertSame(
            self::THRESHOLD_MINOR,
            $this->legacyThresholdMinor(),
            'Roční daňové konstanty musí zrcadlit ruleset, ne držet vlastní kopii.',
        );
    }

    /**
     * Vlastní jádro: pro každou částku kolem hranice musí obě cesty rozhodnout
     * STEJNĚ. Hodnoty jsou v haléřích, aby se otestovalo i to, na čem se stará
     * zkratka „limit je 11 999" lámala.
     *
     * @return iterable<string, array{0:int, 1:bool}>
     */
    public static function boundaryAmounts(): iterable
    {
        yield 'hluboko pod hranicí' => [900_000, true];
        yield 'koruna pod hranicí' => [1_199_900, true];
        yield 'haléř pod hranicí' => [1_199_999, true];
        yield 'přesně na rozhodné částce' => [self::THRESHOLD_MINOR, false];
        yield 'haléř nad hranicí' => [1_200_001, false];
        yield 'koruna nad hranicí' => [1_200_100, false];
    }

    #[DataProvider('boundaryAmounts')]
    public function testBoundaryVerdictsAgree(int $amountMinor, bool $expectWithholding): void
    {
        $modern = $this->modernAppliesWithholding($amountMinor);
        $legacy = $this->legacyAppliesWithholding($amountMinor);

        self::assertSame(
            $expectWithholding,
            $modern,
            sprintf('Mzdový běh u %s Kč (§ 6 odst. 4 písm. a) ZDP — „nedosáhne").', $this->czk($amountMinor)),
        );
        self::assertSame(
            $modern,
            $legacy,
            sprintf(
                'Obě cesty musí u %s Kč rozhodnout stejně — jinak tatáž odměna dostane '
                    . 'jinou daň podle toho, kudy se počítala.',
                $this->czk($amountMinor),
            ),
        );
    }

    /**
     * Odměna přesně na rozhodné částce je nejcitlivější bod: tady se dřív obě cesty
     * rozcházely. Ověřuje se navíc, že legacy cesta uživateli VYSVĚTLÍ, proč srážka
     * nejde — mlčení by vypadalo jako chyba systému.
     */
    public function testExactlyAtThresholdIsAdvanceTaxAndIsExplained(): void
    {
        $constants = TaxConstants::forYear(self::YEAR);
        $amount = self::THRESHOLD_MINOR / 100;

        self::assertFalse($this->modernAppliesWithholding(self::THRESHOLD_MINOR));
        self::assertFalse(WithholdingTaxCalculator::applies(
            WithholdingTaxCalculator::REASON_DPP,
            $amount,
            $constants,
        ));

        $reason = WithholdingTaxCalculator::overLimitReason(
            WithholdingTaxCalculator::REASON_DPP,
            $amount,
            $constants,
        );
        self::assertNotNull($reason);
        self::assertStringContainsString('rozhodné částky', $reason);
        self::assertStringContainsString('12 000 Kč', $reason);
    }

    /** Rozhodnutí moderní cesty — ruleset + politika daně ze závislé činnosti. */
    private function modernAppliesWithholding(int $amountMinor): bool
    {
        $policy = EmploymentIncomeTaxPolicy2026::forRuleset(
            CzechPayrollRulesets2026::provider()->forDate(
                PayrollRulesetDomain::IncomeTax,
                self::YEAR . '-01-01',
            ),
        );

        return $amountMinor < $policy->money('dpp.withholding.threshold');
    }

    /** Rozhodnutí legacy cesty — roční daňové konstanty. */
    private function legacyAppliesWithholding(int $amountMinor): bool
    {
        return WithholdingTaxCalculator::applies(
            WithholdingTaxCalculator::REASON_DPP,
            $amountMinor / 100,
            TaxConstants::forYear(self::YEAR),
        );
    }

    private function rulesetThresholdMinor(): int
    {
        $value = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::IncomeTax, self::YEAR . '-01-01')
            ->parameters['dpp.withholding.threshold'];
        self::assertIsInt($value->value);

        return $value->value;
    }

    private function legacyThresholdMinor(): int
    {
        return (int) round(((float) TaxConstants::forYear(self::YEAR)['dpp_withholding_limit']) * 100);
    }

    private function czk(int $minor): string
    {
        return number_format($minor / 100, 2, ',', ' ');
    }
}
