<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Component\PayrollComponentDefaults;
use MyInvoice\Service\Payroll\Component\PayrollComponentKind;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use PHPUnit\Framework\TestCase;

/**
 * MZ-08-W08 b — roční limit osvobození benefitů se u výchozích složek nikdy
 * nezaložil, takže `annual_limit_minor` zůstal NULL a
 * `PayrollInputRepository::approve()` (který strop hlídá jen u nenulového limitu)
 * neudělal nic. Tenhle test drží doložené částky u konkrétních složek a stejně
 * důrazně i to, u kterých složek limit VĚDOMĚ chybí.
 */
final class PayrollComponentDefaultsTest extends TestCase
{
    /**
     * Kód složky => očekávaný roční limit v haléřích.
     *
     * Zdroj hodnot: § 6 odst. 9 ZDP, průměrná mzda za zdaňovací období 2026
     * 48 967 Kč (§ 21g ZDP, nařízení vlády o výši všeobecného vyměřovacího
     * základu). Limity samotné bydlí v rulesetu; tady se jen kontroluje, že
     * doputovaly ke správné mzdové složce.
     */
    private const EXPECTED_LIMITS = [
        // § 6 odst. 9 písm. d) bod 1 — nepeněžní zdravotnická plnění, průměrná mzda.
        'ZDRAVOTNI_BENEFIT' => 4_896_700,
        // § 6 odst. 9 písm. d) bod 2 — volnočasová plnění, polovina průměrné mzdy.
        'REKREACE_VOLNY_CAS' => 2_448_350,
        // § 6 odst. 9 písm. p) — produkty spoření na stáří, 50 000 Kč ze zákona.
        'PRISPEVEK_PENZE_ZIVOTNI' => 5_000_000,
    ];

    /**
     * Benefitní složky, u kterých roční limit záměrně NENÍ, a proč. Kdyby sem
     * někdo limit doplnil naslepo, blokoval by schválení plnění, které zákon
     * v takové výši připouští.
     *
     * @var array<string,string>
     */
    private const DELIBERATELY_WITHOUT_LIMIT = [
        'PRISPEVEK_STRAVOVANI' => '§ 6 odst. 9 písm. b) — limit je za směnu, ne za rok.',
        'SOUKROME_VOZIDLO' => '§ 6 odst. 6 — ocenění příjmu, žádné osvobození s ročním stropem.',
        'VZDELAVANI' => '§ 6 odst. 9 písm. a) — odborný rozvoj je osvobozený bez limitu.',
        'PRISPEVEK_DLOUHODOBA_PECE' => 'Zařazení pod § 6 odst. 9 písm. p) určí až účetní.',
    ];

    public function testBenefitLimitsComeFromTheRulesetAndLandOnTheRightComponents(): void
    {
        $rows = $this->rowsByCode('2026-01-01');

        foreach (self::EXPECTED_LIMITS as $code => $expected) {
            self::assertArrayHasKey($code, $rows, "Výchozí číselník ztratil složku {$code}.");
            self::assertSame(
                $expected,
                $rows[$code]['annual_limit_minor'],
                "Roční limit složky {$code} neodpovídá zákonné částce.",
            );
        }
    }

    public function testComponentsWithoutADocumentedLimitStayEmpty(): void
    {
        $rows = $this->rowsByCode('2026-01-01');

        foreach (self::DELIBERATELY_WITHOUT_LIMIT as $code => $why) {
            self::assertArrayHasKey($code, $rows, "Výchozí číselník ztratil složku {$code}.");
            self::assertNull($rows[$code]['annual_limit_minor'], $why);
        }
    }

    public function testOnlyBenefitComponentsCarryAnAnnualLimit(): void
    {
        foreach ($this->rowsByCode('2026-01-01') as $code => $row) {
            if ($row['annual_limit_minor'] === null) {
                continue;
            }
            self::assertTrue(
                PayrollComponentKind::from($row['component_kind'])->isBenefit(),
                "Složka {$code} má roční limit, ale není benefitní — "
                . 'PayrollComponentDefinition takovou kombinaci odmítne.',
            );
            self::assertGreaterThan(0, $row['annual_limit_minor']);
        }
    }

    public function testEveryDefaultCodeIsKnownToTheDeletionGuard(): void
    {
        $codes = PayrollComponentDefaults::codes();

        self::assertSame($codes, array_values(array_unique($codes)));
        self::assertContains('MZDA_MESICNI', $codes);
        self::assertContains('ZDRAVOTNI_BENEFIT', $codes);
    }

    public function testClassificationVersionsAreOrderedByEffectiveDate(): void
    {
        $defaults = new PayrollComponentDefaults(
            CzechPayrollRulesets2026::provider(),
            [
                ['valid_from' => '2026-06-01', 'rows' => [self::syntheticRow('2026-06-01')]],
                ['valid_from' => '2026-01-01', 'rows' => [self::syntheticRow('2026-01-01')]],
            ],
        );

        self::assertSame(
            ['2026-01-01', '2026-06-01'],
            array_column($defaults->versions(), 'valid_from'),
        );
    }

    /**
     * Verze, ke které není účinný ruleset daně z příjmů, se nezaloží vůbec.
     * Založit ji s prázdným limitem by tiše vypnulo přesně to hlídání, kvůli
     * kterému tahle třída vznikla.
     */
    public function testVersionWithoutAnEffectiveIncomeTaxRulesetIsSkipped(): void
    {
        $defaults = new PayrollComponentDefaults(
            CzechPayrollRulesets2026::provider(),
            [
                ['valid_from' => '2026-01-01', 'rows' => [self::syntheticRow('2026-01-01')]],
                ['valid_from' => '2031-01-01', 'rows' => [self::syntheticRow('2031-01-01')]],
            ],
        );

        self::assertSame(['2026-01-01'], array_column($defaults->versions(), 'valid_from'));
    }

    /** @return array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string,9:string,10:string,11:string,12:?string} */
    private static function syntheticRow(string $label): array
    {
        return [
            'SYN_' . str_replace('-', '', $label),
            'Syntetická složka',
            'benefit_recreation',
            'non_monetary',
            'one_off',
            'manual_review',
            'manual_review',
            'manual_review',
            'excluded',
            'manual_review',
            'manual_review',
            'included',
            'benefit_exemption.non_cash_leisure.yearly',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function rowsByCode(string $validFrom): array
    {
        $defaults = new PayrollComponentDefaults(CzechPayrollRulesets2026::provider());
        foreach ($defaults->versions() as $version) {
            if ($version['valid_from'] !== $validFrom) {
                continue;
            }
            $rows = [];
            foreach ($version['rows'] as $row) {
                $rows[$row['code']] = $row;
            }

            return $rows;
        }

        self::fail("Výchozí klasifikace nemá verzi účinnou od {$validFrom}.");
    }
}
