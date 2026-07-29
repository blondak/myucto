<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

/**
 * Sdílené výpočetní jádro daně z příjmů FO (§6–§10, §15, §35ba/§35c ZDP) — Epic DP
 * (issue #18). Extrahováno z {@see TaxOptimizer::computeRegular()} tak, aby optimalizátor
 * i {@see \MyInvoice\Service\Tax\Return\DpfoReturnCalculator} sdílely JEDEN zdroj pravdy
 * (chování optimalizátoru 1:1 — hlídají regresní testy).
 *
 * Čisté statické funkce, žádná DB závislost. `$c` = roční konstanty (TaxConstants /
 * TaxConstantsRepository).
 */
final class DpfoCalculator
{
    /**
     * Výdaje: skutečné (daňová evidence) NEBO výdajový paušál % se stropem dle sazby.
     */
    public static function expenses(int $rate, bool $useActual, float $actualExpenses, float $income, array $c): float
    {
        return $useActual
            ? max(0.0, $actualExpenses)
            : min($income * $rate / 100, (float) ($c['expense_caps'][$rate] ?? PHP_INT_MAX));
    }

    /**
     * Nezdanitelné části základu §15 se ZÁKONNÝMI stropy — JEDEN zdroj pravdy
     * s ostrým přiznáním {@see \MyInvoice\Service\Tax\Return\DpfoReturnCalculator}
     * (§15 blok). Aplikuje: úroky (hypotéka, cap 150k/300k), společný strop §15a
     * 48 000 Kč pro penzijko + životko dohromady, dary §15/1 (spodní limit
     * 1 000 Kč / 2 % ZD, horní 30 % ZD). Parita s přiznáním hlídá regresní test
     * (DpfoCalculatorParityTest).
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $c
     * @param float $baseForDonations základ pro % limit darů (§7 ZD před §15 =
     *              příjmy − výdaje); v přiznání odpovídá ř. 45 (baseAfterLoss).
     */
    public static function deductions15(array $profile, array $c, float $baseForDonations = 0.0): float
    {
        // §15/3-4 ZDP: strop úroků 300 000 Kč pro bytovou potřebu obstaranou do 31. 12. 2020,
        // jinak 150 000 Kč (od 2021).
        $mortgageCap = !empty($profile['mortgage_pre_2021'])
            ? (float) ($c['mortgage_cap_pre2021'] ?? 300000)
            : (float) ($c['mortgage_cap'] ?? 150000);
        $mortgageMonths = max(0, min(12, (int) ($profile['mortgage_months'] ?? 12)));
        $uroky = min((float) ($profile['mortgage_interest'] ?? 0), $mortgageCap * $mortgageMonths / 12);

        // §15a (od 2024): penzijní produkty + soukromé životní pojištění (+ DIP) sdílejí
        // JEDEN společný roční strop 48 000 Kč. Alokujeme nejdřív penzijko, zbytek životko.
        $retirementCap = (float) ($c['pension_cap'] ?? 48000);
        $penzijko = min((float) ($profile['pension_contrib'] ?? 0), $retirementCap);
        $zivotko = min((float) ($profile['life_insurance'] ?? 0), max(0.0, $retirementCap - $penzijko));
        $dip = min((float) ($profile['dip_contrib'] ?? 0), max(0.0, $retirementCap - $penzijko - $zivotko));
        $care = min((float) ($profile['long_term_care'] ?? 0), max(0.0, $retirementCap - $penzijko - $zivotko - $dip));

        // Dary §15/1: odečet jen když ≥ spodní limit (1 000 Kč nebo 2 % ZD), max % ZD.
        $donations = (float) ($profile['donations'] ?? 0);
        $donationCapPct = (float) ($c['donation_cap_fo_pct'] ?? 0.30);
        $donationMin = min((float) ($c['donation_min_fo'] ?? 1000), (float) ($c['donation_min_fo_pct'] ?? 0.02) * $baseForDonations);
        $donationCap = $donationCapPct * $baseForDonations;
        $daryDeduct = ($donations > 0 && $donations >= $donationMin)
            ? min($donations, $donationCap)
            : 0.0;

        return $uroky + $penzijko + $zivotko + $dip + $care + $daryDeduct;
    }

    /** Progresivní daň: 15 % do tax_high_threshold, 23 % nad. */
    public static function progressiveTax(float $base, array $c): float
    {
        $thr = (float) $c['tax_high_threshold'];
        return $base <= $thr
            ? $base * (float) $c['tax_rate_low']
            : $thr * (float) $c['tax_rate_low'] + ($base - $thr) * (float) $c['tax_rate_high'];
    }

    /**
     * Nevratné slevy §35ba: poplatník + (volitelně) manželka.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $c
     */
    public static function nonRefundableCredits(array $profile, array $c): float
    {
        return (float) $c['credit_taxpayer']
            + (!empty($profile['spouse_credit']) ? (float) $c['credit_spouse'] : 0.0);
    }

    /**
     * Daňové zvýhodnění na děti §35c (kumulace 1./2./3.+ dítě; 3.+ se opakuje).
     * Smí jít do mínusu (daňový bonus).
     *
     * @param array<int,int> $credits
     */
    public static function childCreditTotal(int $n, array $credits): float
    {
        $sum = 0.0;
        for ($i = 1; $i <= $n; $i++) {
            $idx = min($i, count($credits)) - 1;
            $sum += $credits[$idx];
        }
        return $sum;
    }

    /**
     * Položkové zvýhodnění na děti po měsících, pořadí a ZTP/P.
     * @param list<array<string,mixed>> $children
     * @param array<int,int|float> $credits
     */
    public static function childCreditFromClaims(array $children, array $credits): float
    {
        $sum = 0.0;
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            foreach ((array) ($child['months'] ?? []) as $month) {
                if (!is_array($month) || empty($month['claimed'])) {
                    continue;
                }
                $order = max(1, min(3, (int) ($month['order'] ?? 1)));
                $annual = (float) ($credits[min($order, count($credits)) - 1] ?? 0);
                $sum += $annual / 12 * (!empty($month['ztpp']) ? 2 : 1);
            }
        }
        return round($sum, 2);
    }

    /** @param array<string,mixed>|null $claim */
    public static function spouseCreditFromClaim(?array $claim, array $c): float
    {
        if ($claim === null || (int) ($claim['eligible_months'] ?? 0) <= 0) {
            return 0.0;
        }
        $months = max(0, min(12, (int) $claim['eligible_months']));
        $credit = (float) ($c['credit_spouse'] ?? 0) * $months / 12;
        return round($credit * (!empty($claim['ztpp']) ? 2 : 1), 2);
    }

    /**
     * Výdaje více činností §7. Paušální strop se uplatní jednou za sazbu, aby dvě
     * činnosti stejné kategorie nevytvořily dvojnásobný zákonný strop.
     * @param list<array<string,mixed>> $activities
     * @return array{income:float,expenses:float,items:list<array<string,mixed>>}
     */
    public static function section7Activities(array $activities, array $c): array
    {
        $groups = [];
        $items = [];
        foreach ($activities as $idx => $activity) {
            if (!is_array($activity)) {
                continue;
            }
            $income = max(0.0, round((float) ($activity['income'] ?? 0), 2));
            $rate = in_array((int) ($activity['expense_rate'] ?? 60), [30, 40, 60, 80], true)
                ? (int) $activity['expense_rate'] : 60;
            $mode = ($activity['expense_mode'] ?? 'pausal') === 'actual' ? 'actual' : 'pausal';
            if ($mode === 'pausal') {
                $groups[$rate] = round(($groups[$rate] ?? 0.0) + $income, 2);
                $expenses = 0.0;
            } else {
                $expenses = max(0.0, round((float) ($activity['expenses'] ?? 0), 2));
            }
            $items[] = $activity + ['index' => $idx, 'income' => $income, 'expenses' => $expenses, 'expense_rate' => $rate, 'expense_mode' => $mode];
        }
        foreach ($groups as $rate => $income) {
            $groupExpense = min($income * (int) $rate / 100, (float) ($c['expense_caps'][(int) $rate] ?? PHP_INT_MAX));
            $remainingIncome = $income;
            foreach ($items as &$item) {
                if ($item['expense_mode'] !== 'pausal' || (int) $item['expense_rate'] !== (int) $rate) {
                    continue;
                }
                $share = $remainingIncome > 0 ? (float) $item['income'] / $income : 0.0;
                $item['expenses'] = round($groupExpense * $share, 2);
                $remainingIncome -= (float) $item['income'];
            }
            unset($item);
        }
        return [
            'income' => round(array_sum(array_column($items, 'income')), 2),
            'expenses' => round(array_sum(array_column($items, 'expenses')), 2),
            'items' => $items,
        ];
    }

    /**
     * Pojistné OSVČ z vyměřovacího základu (% zisku), s ročními minimy. Od 2024:
     * sociální 55 % zisku, zdravotní 50 % zisku. Slevy na dani pojistné neovlivní.
     *
     * STEJNÁ pravidla vedlejší činnosti jako Return kalkulátory (jeden zdroj pravdy):
     *  - sociální: vedlejší SVČ pod rozhodnou částkou (social_secondary_participation_threshold)
     *    → bez povinné účasti, pojistné 0 (viz {@see \MyInvoice\Service\Tax\Return\SocialInsuranceCalculator});
     *  - zdravotní: u vedlejší činnosti (souběh se zaměstnáním) se minimální VZ NEUPLATNÍ
     *    (viz {@see \MyInvoice\Service\Tax\Return\HealthInsuranceCalculator}).
     * Optimalizátor nezaokrouhluje na celé Kč nahoru (poradní odhad); rozhodné částky
     * a minima jsou ale identická — parita nulového případu hlídá regresní test.
     *
     * @param array<string,mixed> $c
     * @return array{social_base:float,health_base:float,social:float,health:float}
     */
    public static function insurance(float $profit, bool $isSecondary, array $c): array
    {
        // Sociální: vedlejší SVČ pod rozhodnou částkou → bez povinné účasti (pojistné 0).
        $threshold = (float) ($c['social_secondary_participation_threshold'] ?? 0);
        if ($isSecondary && $profit < $threshold) {
            $socialBase = 0.0;
            $social = 0.0;
        } else {
            $socMin = $isSecondary ? (float) $c['social_min_base_secondary'] : (float) $c['social_min_base_main'];
            $socialBase = min(
                max($profit * (float) $c['social_assessment_pct'], $socMin),
                (float) ($c['social_max_base'] ?? PHP_INT_MAX)
            );
            $social = $socialBase * (float) $c['social_rate'];
        }

        // Zdravotní: u vedlejší činnosti se minimální VZ neuplatní (VZ = 50 % zisku bez spodní hranice).
        $healthMin = $isSecondary ? 0.0 : (float) $c['health_min_base'];
        $healthBase = max($profit * (float) $c['health_assessment_pct'], $healthMin);

        return [
            'social_base' => $socialBase,
            'health_base' => $healthBase,
            'social' => $social,
            'health' => $healthBase * (float) $c['health_rate'],
        ];
    }

    /**
     * Kompletní výpočet „skutečného" režimu jen z §7 (OSVČ) — 1:1 náhrada za
     * původní TaxOptimizer::computeRegular. Struktura výstupu zachována kvůli
     * regresním testům optimalizátoru.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    public static function computeSection7(array $profile, float $income, array $c): array
    {
        $activities = (array) ($profile['activities'] ?? []);
        $activityCalc = $activities !== [] ? self::section7Activities($activities, $c) : null;
        $rate = (int) ($profile['activity_rate'] ?? 40);
        $useActual = !empty($profile['use_actual_expenses']);
        $expenses = $activityCalc !== null
            ? (float) $activityCalc['expenses']
            : self::expenses($rate, $useActual, (float) ($profile['actual_expenses'] ?? 0), $income, $c);
        if ($activityCalc !== null) {
            $income = (float) $activityCalc['income'];
        }

        // §7 základ před §15 (příjmy − výdaje) = základ pro % limit darů (odpovídá ř. 45 v přiznání).
        $section7Base = max(0.0, $income - $expenses);
        $deductions = self::deductions15($profile, $c, $section7Base);
        $base = max(0.0, $income - $expenses - $deductions);

        $tax = self::progressiveTax($base, $c);

        $spouseClaim = is_array($profile['spouse_claim'] ?? null) ? $profile['spouse_claim'] : null;
        $spouseCredit = $spouseClaim !== null
            ? self::spouseCreditFromClaim($spouseClaim, $c)
            : (!empty($profile['spouse_credit']) ? (float) ($c['credit_spouse'] ?? 0) : 0.0);
        $nonRefundable = (float) ($c['credit_taxpayer'] ?? 0) + $spouseCredit
            + (float) ($c['credit_disability_12'] ?? 2520) * max(0, min(12, (int) ($profile['disability_12_months'] ?? 0))) / 12
            + (float) ($c['credit_disability_3'] ?? 5040) * max(0, min(12, (int) ($profile['disability_3_months'] ?? 0))) / 12
            + (float) ($c['credit_ztpp'] ?? 16140) * max(0, min(12, (int) ($profile['ztpp_months'] ?? 0))) / 12;
        $taxAfterCredits = max(0.0, $tax - $nonRefundable);

        $children = (array) ($profile['children'] ?? []);
        $childTotal = $children !== []
            ? self::childCreditFromClaims($children, $c['child_credits'])
            : self::childCreditTotal((int) ($profile['children_count'] ?? 0), $c['child_credits']);
        $incomeTax = $taxAfterCredits - $childTotal;
        if ($incomeTax < 0 && $income < (float) ($c['child_bonus_min_income'] ?? 0)) {
            $incomeTax = 0.0;
        }

        $profit = $income - $expenses;
        $ins = self::insurance($profit, !empty($profile['is_secondary']), $c);

        $total = $incomeTax + $ins['social'] + $ins['health'];

        return [
            'applicable'   => true,
            'expense_rate' => $rate,
            'use_actual'   => $useActual,
            'expenses'     => round($expenses, 0),
            'deductions'   => round($deductions, 0),
            'tax_base'     => round($base, 0),
            'tax_gross'    => round($tax, 0),
            'credit_taxpayer' => (float) $c['credit_taxpayer'],
            'credit_spouse'   => round($spouseCredit, 0),
            'child_credit'    => round($childTotal, 0),
            'income_tax'   => round($incomeTax, 0), // záporné = daňový bonus
            'is_bonus'     => $incomeTax < 0,
            'social'       => round($ins['social'], 0),
            'health'       => round($ins['health'], 0),
            'total'        => round($total, 0),
            'net_income'     => round($income - $total, 0),
            'effective_rate' => $income > 0 ? round($total / $income, 4) : 0.0,
        ];
    }
}
