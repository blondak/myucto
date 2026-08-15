<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\Calculation\CalculationStep;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\PayrollRounding;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxIntegerMath;

/**
 * Výpočet daně a ročního zúčtování záloh a daňového zvýhodnění.
 *
 * Čistá funkce nad `AnnualSettlementInput` — žádná databáze, žádný čas, žádná
 * evidence. Posouzení PODMÍNEK (kdo smí požádat, do kdy, co musí doložit) sem
 * nepatří; to dělá `AnnualSettlementEligibility` a výsledek sem přijde jako
 * hotový seznam překážek. Rozdělené je to proto, že podmínky se posuzují proti
 * kalendáři a evidenci, kdežto tenhle výpočet musí být přehratelný a testovatelný
 * bez obojího.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Postup podle § 35d odst. 7 — v tom pořadí, v jakém ho zákon uvádí
 * ─────────────────────────────────────────────────────────────────────────────
 *  1. Základ daně = úhrn základů pro výpočet záloh, zaokrouhlený podle
 *     § 16 odst. 2 na celá sta Kč DOLŮ. Sčítají se NEZAOKROUHLENÉ měsíční
 *     základy — měsíční zaokrouhlení nahoru (§ 38h odst. 1) je vlastnost zálohy,
 *     ne roční daně, a jeho dvanáctinásobné promítnutí do roku by daň nadhodnotilo.
 *  2. Daň podle § 16 odst. 1: 15 % do 36násobku průměrné mzdy, 23 % nad.
 *  3. „nejdříve daň sníží o slevy na dani pro poplatníky daně z příjmů fyzických
 *     osob" — § 35ba, maximálně do výše daně (sleva nikdy nevytvoří přeplatek).
 *  4. „pak vypočte částku daňového zvýhodnění … formou slevy na dani podle
 *     § 35c a daňového bonusu" — sleva do výše daně po § 35ba (§ 35c odst. 2),
 *     zbytek je bonus (§ 35c odst. 3).
 *  5. „takto sníženou daň porovná s úhrnem zálohově sražené daně a vypočte
 *     rozdíl na dani".
 *  6. „porovná daňový bonus s úhrnem již vyplacených měsíčních daňových bonusů
 *     a vypočte rozdíl na daňovém bonusu".
 *  7. Oba rozdíly se podle čtyř vět § 35d odst. 7 skládají dohromady. Ve všech
 *     čtyřech větách vychází totéž: doplatek = rozdíl na dani + rozdíl na bonusu.
 *     Přesto se drží odděleně — vykazují se samostatně (JMHZ 10322, 10323).
 *  8. § 38ch odst. 5 / § 35d odst. 8: vyplatí se, je-li úhrn VÍCE než 50 Kč.
 *     Nedoplatek se NESRÁŽÍ.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Co se sem ZÁMĚRNĚ nepočítá
 * ─────────────────────────────────────────────────────────────────────────────
 * Srážková daň podle § 6 odst. 4. Je to samostatný základ daně a do ročního
 * zúčtování nevstupuje — § 38k odst. 7 věta druhá umožňuje ji do zúčtování
 * zahrnout jen tehdy, prokáže-li poplatník rozhodné skutečnosti a učiní
 * prohlášení do 15. února. Modul takový úkon neeviduje, takže se srážková daň
 * v ročním zúčtování nechává stranou a nese se jen jako doložený údaj.
 */
final class AnnualTaxSettlementCalculator
{
    /**
     * § 16 odst. 2: „ze základu daně … zaokrouhleného na celá sta Kč dolů".
     * V minor units je sto korun 10 000 haléřů.
     */
    private const TAX_BASE_MULTIPLE_MINOR_UNITS = 10_000;

    /** @param list<AnnualSettlementBlocker> $blockers */
    public function calculate(
        AnnualSettlementInput $input,
        AnnualTaxRates $rates,
        array $blockers = [],
    ): AnnualSettlementResult {
        $blockers = $this->withCalculationBlockers($input, $blockers);
        if ($blockers !== []) {
            return AnnualSettlementResult::refused(
                $input->taxYear,
                $blockers,
                ['rates' => $rates->toArray()],
            );
        }

        // 1. Základ daně (§ 16 odst. 2).
        $roundedBase = $this->floorToMultiple(
            $input->advanceBaseMinorUnits,
            self::TAX_BASE_MULTIPLE_MINOR_UNITS,
        );

        // 2. Daň (§ 16 odst. 1).
        $lowRateBase = min($roundedBase, $rates->highRateThresholdMinorUnits);
        $highRateBase = max(0, $roundedBase - $rates->highRateThresholdMinorUnits);
        $lowStep = CalculationStep::calculate(
            'annual-tax-low-rate',
            $lowRateBase,
            DecimalRate::fromString($rates->lowRate),
            RoundingMode::TowardZero,
        );
        $highStep = CalculationStep::calculate(
            'annual-tax-high-rate',
            $highRateBase,
            DecimalRate::fromString($rates->highRate),
            RoundingMode::TowardZero,
        );
        // Daň se zaokrouhluje na celé koruny nahoru (§ 146 odst. 1 daňového
        // řádu). Sčítají se PŘESNÉ zlomky, ne dvě samostatně zaokrouhlená
        // pásma — stejně jako u měsíční zálohy, jinak by se roční a měsíční
        // větev rozešly o korunu na každém pásmu.
        $taxBeforeCredits = PayrollRounding::ceilFractionSumToMultiple([
            [
                'numerator' => $lowStep->unroundedNumerator,
                'denominator' => $lowStep->unroundedDenominator,
            ],
            [
                'numerator' => $highStep->unroundedNumerator,
                'denominator' => $highStep->unroundedDenominator,
            ],
        ], 100);

        // 3. Slevy podle § 35ba.
        $creditBreakdown = [];
        $annualCredits = 0;
        foreach ($input->creditMonths as $claim) {
            $amount = $rates->creditForMonths($claim->kind, $claim->months);
            $creditBreakdown[$claim->kind->value] = [
                'months' => $claim->months,
                'amount_minor_units' => $amount,
                'prorated' => $claim->kind !== TaxCreditKind::Taxpayer,
            ];
            $annualCredits = TaxIntegerMath::add($annualCredits, $amount);
        }
        ksort($creditBreakdown);
        $appliedCredits = min($taxBeforeCredits, $annualCredits);
        $taxAfterCredits = $taxBeforeCredits - $appliedCredits;

        // 4. Daňové zvýhodnění podle § 35c.
        $childBreakdown = [];
        $childEntitlement = 0;
        foreach ($input->childMonths as $child) {
            $plain = $rates->childCreditForMonths(
                $child->order,
                $child->months - $child->ztpPMonths,
                false,
            );
            $ztpP = $rates->childCreditForMonths(
                $child->order,
                $child->ztpPMonths,
                true,
            );
            $amount = TaxIntegerMath::add($plain, $ztpP);
            $childBreakdown[] = [
                'child_reference' => $child->childReference,
                'order' => $child->order,
                'months' => $child->months,
                'ztp_p_months' => $child->ztpPMonths,
                'amount_minor_units' => $amount,
            ];
            $childEntitlement = TaxIntegerMath::add($childEntitlement, $amount);
        }
        $childCredit = min($taxAfterCredits, $childEntitlement);
        $bonusCandidate = $childEntitlement - $childCredit;
        $taxAfterAllCredits = $taxAfterCredits - $childCredit;

        // § 35d odst. 6 / § 35c odst. 4: bonus jen při ročním příjmu alespoň
        // v šestinásobku minimální mzdy, a podle § 35c odst. 3 nejméně 100 Kč.
        $thresholdMet = $input->bonusQualifyingIncomeMinorUnits
            >= $rates->bonusMinimumIncomeMinorUnits;
        $annualBonus = ($thresholdMet
            && $bonusCandidate >= AnnualSettlementStatute::ANNUAL_BONUS_MINIMUM_MINOR_UNITS)
            ? $bonusCandidate
            : 0;

        // 5. Rozdíl na dani.
        $taxDifference = $input->advanceTaxMinorUnits - $taxAfterAllCredits;

        // 6. Rozdíl na daňovém bonusu.
        //
        // Věta třetí § 35d odst. 6: nedosáhl-li roční úhrn příjmů šestinásobku
        // minimální mzdy, poplatník už NEZTRÁCÍ nárok na měsíční bonusy
        // vyplacené v měsících, kde jeho příjem dosáhl alespoň poloviny
        // minimální mzdy. Měsíční větev modulu bonus jinde než v takovém měsíci
        // nevyplatí (MonthlyAdvanceTaxCalculator testuje
        // `bonus.minimum_income.monthly`), takže každý už vyplacený bonus je
        // z kvalifikovaného měsíce a nevrací se. Rozdíl je proto nula, ne
        // záporná částka — jinak by se zaměstnanci strhávalo něco, na co mu
        // nárok zůstal.
        $bonusDifference = $thresholdMet
            ? $annualBonus - $input->monthlyTaxBonusMinorUnits
            : 0;

        // 7. Doplatek ze zúčtování (§ 35d odst. 7 věty třetí a čtvrtá).
        $difference = TaxIntegerMath::add($taxDifference, $bonusDifference);

        // 8. Výplata (§ 38ch odst. 5, § 35d odst. 8).
        $payable = AnnualSettlementStatute::isPayable($difference) ? $difference : 0;
        $outcome = match (true) {
            $difference < 0 => AnnualSettlementOutcome::UnderpaymentNotWithheld,
            $difference === 0 => AnnualSettlementOutcome::NoDifference,
            $payable > 0 => AnnualSettlementOutcome::Overpayment,
            default => AnnualSettlementOutcome::OverpaymentBelowThreshold,
        };

        return AnnualSettlementResult::performed(
            $input->taxYear,
            $outcome,
            $roundedBase,
            $taxBeforeCredits,
            $annualCredits,
            $appliedCredits,
            $childEntitlement,
            $childCredit,
            $annualBonus,
            $taxAfterAllCredits,
            $taxDifference,
            $bonusDifference,
            $difference,
            $payable,
            $thresholdMet,
            [
                'rates' => $rates->toArray(),
                'statute_source' => AnnualSettlementStatute::SOURCE,
                'completed_months' => $input->completedMonths,
                'advance_base_minor_units' => $input->advanceBaseMinorUnits,
                'advance_tax_minor_units' => $input->advanceTaxMinorUnits,
                'monthly_credits_minor_units' =>
                    $input->appliedNonRefundableCreditsMinorUnits,
                'monthly_child_credit_minor_units' =>
                    $input->appliedChildCreditMinorUnits,
                'monthly_tax_bonus_minor_units' => $input->monthlyTaxBonusMinorUnits,
                'bonus_qualifying_income_minor_units' =>
                    $input->bonusQualifyingIncomeMinorUnits,
                'withholding_base_minor_units' => $input->withholdingBaseMinorUnits,
                'withholding_tax_minor_units' => $input->withholdingTaxMinorUnits,
                'rate_steps' => [
                    $lowStep->jsonSerialize(),
                    $highStep->jsonSerialize(),
                ],
                'credits' => $creditBreakdown,
                'children' => $childBreakdown,
                'payout_threshold_minor_units' =>
                    AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
            ],
        );
    }

    /**
     * Překážky, které pozná až výpočet — na rozdíl od těch z evidence.
     *
     * @param list<AnnualSettlementBlocker> $blockers
     * @return list<AnnualSettlementBlocker>
     */
    private function withCalculationBlockers(
        AnnualSettlementInput $input,
        array $blockers,
    ): array {
        if ($input->completedMonths === 0) {
            $blockers[] = AnnualSettlementBlocker::NoApprovedMonths;
        }
        if ($input->externalCertificates !== []) {
            // Viz AnnualSettlementBlocker::ExternalCertificateIncomplete —
            // potvrzení nenese měsíční slevy ani vyplacené bonusy, které
            // § 38ch odst. 3 vyjmenovává.
            $blockers[] = AnnualSettlementBlocker::ExternalCertificateIncomplete;
        }

        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker->value] = $blocker;
        }
        ksort($unique);

        return array_values($unique);
    }

    /** § 16 odst. 2: zaokrouhlení základu daně na celá sta Kč dolů. */
    private function floorToMultiple(int $value, int $multiple): int
    {
        if ($value < 0) {
            throw new \InvalidArgumentException(
                'Roční základ daně nesmí být záporný.',
            );
        }

        return intdiv($value, $multiple) * $multiple;
    }
}
