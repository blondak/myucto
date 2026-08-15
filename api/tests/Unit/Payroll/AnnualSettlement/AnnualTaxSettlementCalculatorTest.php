<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementBlocker;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementChildMonths;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementCreditMonths;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementInput;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementOutcome;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementStatute;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxRates;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxSettlementCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\ExternalEmployerTaxCertificate;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use PHPUnit\Framework\TestCase;

/**
 * Roční zúčtování — § 38ch ZDP.
 *
 * Klíčový test je `testOverpaymentMatchesSumOfMonthlyAdvancesToTheHaler`: nepočítá
 * očekávanou částku ručně z literálu, ale prožene stejná měsíční data SKUTEČNÝM
 * měsíčním kalkulátorem a porovná. Kdyby se roční a měsíční větev rozešly
 * v zaokrouhlení, sazbě nebo pásmu, sedne to na haléř jen náhodou.
 */
final class AnnualTaxSettlementCalculatorTest extends TestCase
{
    private const YEAR = 2026;

    /**
     * Zaměstnanec s jedním zaměstnavatelem a podepsaným prohlášením.
     *
     * Přeplatek vzniká z toho, že měsíční základ se podle § 38h odst. 1
     * zaokrouhluje na celé stokoruny NAHORU, kdežto roční základ podle
     * § 16 odst. 2 na celá sta DOLŮ. Rozdíl je reálný a musí sedět na haléř.
     */
    public function testOverpaymentMatchesSumOfMonthlyAdvancesToTheHaler(): void
    {
        $rates = $this->rates();
        $monthly = new MonthlyAdvanceTaxCalculator($this->rulesets());
        $taxpayerCredit = intdiv(
            $rates->annualCreditMinorUnits[TaxCreditKind::Taxpayer->value],
            AnnualTaxRates::MONTHS_IN_YEAR,
        );

        // Záměrně nekulaté měsíční mzdy — na kulatých by se zaokrouhlovací
        // rozdíl neprojevil a test by neověřoval nic.
        $grossByMonth = [
            4_213_700, 4_213_700, 4_213_700, 4_589_150, 4_213_700, 4_213_700,
            4_213_700, 4_213_700, 4_777_733, 4_213_700, 4_213_700, 4_213_700,
        ];

        $advanceBase = 0;
        $advanceTax = 0;
        $appliedCredits = 0;
        foreach ($grossByMonth as $gross) {
            $result = $monthly->calculate(
                sprintf('%04d-06-01', self::YEAR),
                new MonthlyAdvanceTaxInput(
                    taxableIncomeMinorUnits: $gross,
                    signedDeclaration: true,
                    claimTaxpayerCredit: true,
                    otherNonRefundableCreditsMinorUnits: 0,
                    childCreditMinorUnits: 0,
                ),
            );
            $advanceBase += $gross;
            $advanceTax += $result->taxAfterCreditsMinorUnits;
            $appliedCredits += min(
                $result->taxBeforeCreditsMinorUnits,
                $taxpayerCredit,
            );
        }

        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: $advanceBase,
                advanceTax: $advanceTax,
                appliedCredits: $appliedCredits,
                bonusQualifyingIncome: $advanceBase,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
            ),
            $rates,
        );

        self::assertTrue($result->performed);
        self::assertSame([], $result->blockerCodes());

        // Roční daň spočítaná nezávisle na kalkulátoru: § 16 odst. 2 zaokrouhlí
        // základ na celá sta dolů, § 16 odst. 1 aplikuje 15 % (celý základ je
        // pod 36násobkem průměrné mzdy), daň se zaokrouhlí na koruny nahoru.
        $expectedBase = intdiv($advanceBase, 10_000) * 10_000;
        self::assertSame($expectedBase, $result->roundedTaxBaseMinorUnits);
        $expectedTax = (int) (ceil($expectedBase * 15 / 100 / 100) * 100);
        self::assertSame($expectedTax, $result->taxBeforeCreditsMinorUnits);

        $annualCredit = $rates->annualCreditMinorUnits[TaxCreditKind::Taxpayer->value];
        self::assertSame($annualCredit, $result->annualCreditsMinorUnits);
        self::assertSame(
            $expectedTax - $annualCredit,
            $result->taxAfterAllCreditsMinorUnits,
        );

        // Tohle je ta věta ze zadání: přeplatek = úhrn měsíčních záloh minus
        // roční daň po slevách, na haléř.
        self::assertSame(
            $advanceTax - $result->taxAfterAllCreditsMinorUnits,
            $result->taxDifferenceMinorUnits,
        );
        self::assertSame(0, $result->bonusDifferenceMinorUnits);
        self::assertSame(
            $result->taxDifferenceMinorUnits,
            $result->settlementDifferenceMinorUnits,
        );
        self::assertGreaterThan(
            AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
            $result->settlementDifferenceMinorUnits,
        );
        self::assertSame(AnnualSettlementOutcome::Overpayment, $result->outcome);
        self::assertSame(
            $result->settlementDifferenceMinorUnits,
            $result->payableMinorUnits,
        );
    }

    /** Roční slevy a odpočty se uplatní právě jednou, ne dvanáctkrát. */
    public function testAnnualCreditsAreAppliedOnceNotPerMonth(): void
    {
        $rates = $this->rates();
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 60_000_000,
                advanceTax: 5_000_000,
                appliedCredits: 3_084_000,
                bonusQualifyingIncome: 60_000_000,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
            ),
            $rates,
        );

        self::assertTrue($result->performed);
        self::assertSame(
            $rates->annualCreditMinorUnits[TaxCreditKind::Taxpayer->value],
            $result->annualCreditsMinorUnits,
        );
        // 12× měsíční částka je právě roční částka — ne 12× roční.
        self::assertSame(
            intdiv(
                $rates->annualCreditMinorUnits[TaxCreditKind::Taxpayer->value],
                AnnualTaxRates::MONTHS_IN_YEAR,
            ) * 12,
            $result->annualCreditsMinorUnits,
        );
    }

    /**
     * § 35ba odst. 3 dvanáctinovou úpravu vztahuje jen na písm. b) až e).
     * Základní sleva na poplatníka náleží celá i tomu, kdo pracoval tři měsíce.
     */
    public function testTaxpayerCreditIsNotProratedButDisabilityCreditIs(): void
    {
        $rates = $this->rates();
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 30_000_000,
                advanceTax: 1_000_000,
                appliedCredits: 771_000,
                bonusQualifyingIncome: 30_000_000,
                completedMonths: 3,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 3),
                    new AnnualSettlementCreditMonths(TaxCreditKind::DisabilityBasic, 3),
                ],
            ),
            $rates,
        );

        $taxpayer = $rates->annualCreditMinorUnits[TaxCreditKind::Taxpayer->value];
        $disabilityMonthly = intdiv(
            $rates->annualCreditMinorUnits[TaxCreditKind::DisabilityBasic->value],
            AnnualTaxRates::MONTHS_IN_YEAR,
        );

        self::assertTrue($result->performed);
        self::assertSame(
            $taxpayer + $disabilityMonthly * 3,
            $result->annualCreditsMinorUnits,
        );
        self::assertSame(
            $taxpayer,
            $result->trace['credits'][TaxCreditKind::Taxpayer->value]['amount_minor_units'],
        );
        self::assertFalse(
            $result->trace['credits'][TaxCreditKind::Taxpayer->value]['prorated'],
        );
        self::assertTrue(
            $result->trace['credits'][TaxCreditKind::DisabilityBasic->value]['prorated'],
        );
    }

    /** § 35ba odst. 1: slevy nesmí vytvořit přeplatek samy o sobě. */
    public function testCreditsNeverExceedTheTax(): void
    {
        $rates = $this->rates();
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 5_000_000,
                advanceTax: 0,
                appliedCredits: 0,
                bonusQualifyingIncome: 5_000_000,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
            ),
            $rates,
        );

        self::assertTrue($result->performed);
        self::assertSame(
            $result->taxBeforeCreditsMinorUnits,
            $result->appliedCreditsMinorUnits,
        );
        self::assertSame(0, $result->taxAfterAllCreditsMinorUnits);
        self::assertSame(0, $result->settlementDifferenceMinorUnits);
        self::assertSame(AnnualSettlementOutcome::NoDifference, $result->outcome);
    }

    /** § 38ch odst. 5 věta poslední: nedoplatek se poplatníkovi NESRÁŽÍ. */
    public function testUnderpaymentIsNeverWithheld(): void
    {
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 60_000_000,
                advanceTax: 100_000,
                appliedCredits: 0,
                bonusQualifyingIncome: 60_000_000,
                creditMonths: [],
            ),
            $this->rates(),
        );

        self::assertTrue($result->performed);
        self::assertLessThan(0, $result->settlementDifferenceMinorUnits);
        self::assertSame(0, $result->payableMinorUnits);
        self::assertSame(
            AnnualSettlementOutcome::UnderpaymentNotWithheld,
            $result->outcome,
        );
    }

    /**
     * § 38ch odst. 5: vrací se přeplatek VÍCE než 50 Kč. Přesně 50 Kč se
     * nevyplácí, ale zúčtování proběhlo — to jsou dva různé stavy.
     */
    public function testOverpaymentOfExactlyFiftyCrownsIsNotPaidOut(): void
    {
        $rates = $this->rates();
        $base = 60_000_000;
        $roundedBase = intdiv($base, 10_000) * 10_000;
        $tax = (int) (ceil($roundedBase * 15 / 100 / 100) * 100);
        $annualCredit = $rates->annualCreditMinorUnits[TaxCreditKind::Taxpayer->value];
        $taxAfterCredits = $tax - $annualCredit;

        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: $base,
                advanceTax: $taxAfterCredits
                    + AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
                appliedCredits: $annualCredit,
                bonusQualifyingIncome: $base,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
            ),
            $rates,
        );

        self::assertSame(
            AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
            $result->settlementDifferenceMinorUnits,
        );
        self::assertSame(0, $result->payableMinorUnits);
        self::assertSame(
            AnnualSettlementOutcome::OverpaymentBelowThreshold,
            $result->outcome,
        );
        self::assertTrue($result->performed);
    }

    /**
     * § 35c odst. 10 a odst. 7: zvýhodnění po měsících, u dítěte s průkazem
     * ZTP/P dvojnásobek jen za měsíce, kdy průkaz měl.
     */
    public function testChildBenefitIsProratedAndDoubledForZtpPMonths(): void
    {
        $rates = $this->rates();
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 60_000_000,
                advanceTax: 500_000,
                appliedCredits: 3_084_000,
                bonusQualifyingIncome: 60_000_000,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
                childMonths: [
                    new AnnualSettlementChildMonths('child-a', 1, 12, 6),
                ],
            ),
            $rates,
        );

        $monthly = intdiv(
            $rates->annualChildCreditMinorUnits[1],
            AnnualTaxRates::MONTHS_IN_YEAR,
        );
        self::assertSame(
            $monthly * 6 + $monthly * 6 * 2,
            $result->childEntitlementMinorUnits,
        );
    }

    /**
     * § 35d odst. 6 věta třetí: nedosáhl-li roční příjem šestinásobku minimální
     * mzdy, poplatník už NEZTRÁCÍ nárok na vyplacené měsíční bonusy. Rozdíl na
     * bonusu je proto nula, ne záporná částka.
     */
    public function testMonthlyBonusesAreNotClawedBackBelowTheAnnualThreshold(): void
    {
        $rates = $this->rates();
        $paidBonuses = 400_000;
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 4_000_000,
                advanceTax: 0,
                appliedCredits: 0,
                monthlyTaxBonus: $paidBonuses,
                bonusQualifyingIncome: 4_000_000,
                completedMonths: 3,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 3),
                ],
                childMonths: [
                    new AnnualSettlementChildMonths('child-a', 1, 3, 0),
                ],
            ),
            $rates,
        );

        self::assertLessThan(
            $rates->bonusMinimumIncomeMinorUnits,
            4_000_000,
        );
        self::assertFalse($result->annualBonusThresholdMet);
        self::assertSame(0, $result->annualTaxBonusMinorUnits);
        self::assertSame(0, $result->bonusDifferenceMinorUnits);
        self::assertSame(0, $result->payableMinorUnits);
    }

    /** Rok bez jediného uzavřeného měsíce se nedopočítává „aspoň částečně". */
    public function testYearWithoutApprovedMonthsIsRefused(): void
    {
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 0,
                advanceTax: 0,
                appliedCredits: 0,
                bonusQualifyingIncome: 0,
                completedMonths: 0,
                creditMonths: [],
            ),
            $this->rates(),
        );

        self::assertFalse($result->performed);
        self::assertContains(
            AnnualSettlementBlocker::NoApprovedMonths->value,
            $result->blockerCodes(),
        );
        self::assertSame(0, $result->settlementDifferenceMinorUnits);
        self::assertNull($result->outcome);
    }

    /**
     * § 38ch odst. 3 chce od předchozího plátce i poskytnuté měsíční slevy
     * a vyplacené bonusy. `ExternalEmployerTaxCertificate` je nenese, takže se
     * s ním nepočítá vůbec — ne špatně.
     */
    public function testExternalCertificateStopsTheSettlement(): void
    {
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 60_000_000,
                advanceTax: 5_000_000,
                appliedCredits: 3_084_000,
                bonusQualifyingIncome: 60_000_000,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
                externalCertificates: [
                    new ExternalEmployerTaxCertificate(
                        'synthetic-certificate',
                        10_000_000,
                        1_500_000,
                        TaxEvidenceStatus::Verified,
                        'synthetic-evidence',
                    ),
                ],
            ),
            $this->rates(),
        );

        self::assertFalse($result->performed);
        self::assertContains(
            AnnualSettlementBlocker::ExternalCertificateIncomplete->value,
            $result->blockerCodes(),
        );
    }

    /** Překážka zvenčí se přenese a nic se nedopočítá. */
    public function testExternalBlockerRefusesWithoutComputing(): void
    {
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: 60_000_000,
                advanceTax: 5_000_000,
                appliedCredits: 3_084_000,
                bonusQualifyingIncome: 60_000_000,
                creditMonths: [
                    new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12),
                ],
            ),
            $this->rates(),
            [AnnualSettlementBlocker::DeclarationNotSigned],
        );

        self::assertFalse($result->performed);
        self::assertSame(
            [AnnualSettlementBlocker::DeclarationNotSigned->value],
            $result->blockerCodes(),
        );
        self::assertSame(0, $result->taxBeforeCreditsMinorUnits);
        self::assertSame(0, $result->payableMinorUnits);
    }

    /**
     * § 16 odst. 1 písm. b): 23 % nad 36násobkem průměrné mzdy. Roční hranice
     * musí být dvanáctinásobkem měsíční — jinak by se roční a měsíční větev
     * rozešly u vysokých příjmů.
     */
    public function testHighRateBandStartsAtTwelveTimesTheMonthlyThreshold(): void
    {
        $rates = $this->rates();
        $monthly = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::IncomeTax, sprintf('%04d-06-01', self::YEAR))
            ->parameter('advance.high_threshold.monthly');

        self::assertSame(
            ((int) $monthly->value) * AnnualTaxRates::MONTHS_IN_YEAR,
            $rates->highRateThresholdMinorUnits,
        );

        $base = $rates->highRateThresholdMinorUnits + 100_000_00;
        $result = (new AnnualTaxSettlementCalculator())->calculate(
            $this->input(
                advanceBase: $base,
                advanceTax: 0,
                appliedCredits: 0,
                bonusQualifyingIncome: $base,
                creditMonths: [],
            ),
            $rates,
        );

        // Základ se nejdřív zaokrouhlí podle § 16 odst. 2 dolů na celá sta Kč
        // a teprve pak se dělí do pásem — v opačném pořadí by vyšlo o něco víc.
        $roundedBase = intdiv($base, 10_000) * 10_000;
        self::assertSame($roundedBase, $result->roundedTaxBaseMinorUnits);
        self::assertGreaterThan(
            $rates->highRateThresholdMinorUnits,
            $roundedBase,
        );
        $low = $rates->highRateThresholdMinorUnits * 15;
        $high = ($roundedBase - $rates->highRateThresholdMinorUnits) * 23;
        self::assertSame(
            (int) (ceil(($low + $high) / 100 / 100) * 100),
            $result->taxBeforeCreditsMinorUnits,
        );
    }

    /**
     * @param list<AnnualSettlementCreditMonths> $creditMonths
     * @param list<AnnualSettlementChildMonths> $childMonths
     * @param list<ExternalEmployerTaxCertificate> $externalCertificates
     */
    private function input(
        int $advanceBase,
        int $advanceTax,
        int $appliedCredits,
        int $bonusQualifyingIncome,
        array $creditMonths,
        int $monthlyTaxBonus = 0,
        int $completedMonths = 12,
        array $childMonths = [],
        array $externalCertificates = [],
    ): AnnualSettlementInput {
        return new AnnualSettlementInput(
            self::YEAR,
            $completedMonths,
            $advanceBase,
            $advanceTax,
            $appliedCredits,
            0,
            $monthlyTaxBonus,
            $bonusQualifyingIncome,
            0,
            0,
            $creditMonths,
            $childMonths,
            $externalCertificates,
        );
    }

    private function rates(): AnnualTaxRates
    {
        return AnnualTaxRates::forRuleset(
            CzechPayrollRulesets2026::provider()
                ->forDate(PayrollRulesetDomain::IncomeTax, sprintf('%04d-12-01', self::YEAR)),
        );
    }

    private function rulesets(): PayrollRulesetProvider
    {
        return new PayrollRulesetProvider([
            CzechPayrollRulesets2026::provider()
                ->forDate(PayrollRulesetDomain::IncomeTax, sprintf('%04d-06-01', self::YEAR)),
        ]);
    }
}
