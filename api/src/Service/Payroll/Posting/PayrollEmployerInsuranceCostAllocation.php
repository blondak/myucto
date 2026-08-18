<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Posting;

use MyInvoice\Service\Payroll\Insurance\EmployerSocialInsuranceAllocation;

/**
 * Rozdělení zaměstnavatelského pojistného na PRACOVNÍ VZTAHY kvůli nákladovým
 * střediskům účtu 524.
 *
 * ── Co to je a co to NENÍ ───────────────────────────────────────────────────
 * Zákonná částka pojistného zaměstnavatele je firemní: § 5a odst. 1 zákona
 * č. 589/1992 Sb. staví vyměřovací základ zaměstnavatele z ÚHRNU vyměřovacích
 * základů zaměstnanců a § 7 odst. 3 zaokrouhluje pojistné až z něj. Cokoli, co
 * z toho čísla připadne jedné osobě nebo jednomu vztahu, je proto ALOKACE, ne
 * osobní zákonná částka — nesmí se tvářit jako podklad podání, přehledu ani
 * pásky. V účetnictví je alokace legitimní: nákladové středisko potřebuje vědět,
 * kolik zákonných odvodů na něj připadá.
 *
 * Z toho plyne jediná tvrdá podmínka: **součet alokací se musí na korunu rovnat
 * částce závazku**, jinak by se účetní můstek rozešel s platbou vůči ČSSZ,
 * resp. zdravotní pojišťovně. Zaokrouhlení se proto neřeší násobením sazbou, ale
 * největším zbytkem nad celočíselným podílem — stejně jako
 * {@see EmployerSocialInsuranceAllocation}, jehož je tahle třída jen jiným
 * zrnem (vztah místo osoby).
 *
 * ── Proč se sociální dělí po kategoriích ────────────────────────────────────
 * Úhrny podle § 5a odst. 1 jsou tři (písmena a, b, c) a § 7 odst. 1 na každý
 * pouští jinou sazbu (24,8 / 29,8 / 27,8 % v roce 2026). Rozpustit firemní
 * součet poměrem VŠECH základů by středisku se sazbou 24,8 % přisoudilo kus
 * pojistného, které vzniklo sazbou 29,8 %. Dělí se proto uvnitř kategorie
 * a teprve podíly se sčítají; sleva § 7a se odečítá až ze součtu, protože tak ji
 * odečítá i § 7c odst. 1.
 *
 * ── Proč zdravotní jinak ────────────────────────────────────────────────────
 * Zdravotní pojistné JE osobní veličina (§ 2 z. č. 592/1992 Sb. počítá z
 * vyměřovacího základu zaměstnance), takže po osobách se nic nealokuje — bere se
 * částka osoby. Alokace vzniká až UVNITŘ osoby, mezi jejími souběžnými vztahy,
 * a to poměrem vyměřovacích základů. Doplatek do minimálního základu
 * (§ 3 odst. 10) žádný vlastní základ nemá, takže se rozpustí stejným poměrem;
 * u osoby, které vyšel nulový základ ve všech vztazích, se dělí rovným dílem,
 * ať alokace vůbec vznikne a součet sedí.
 *
 * ── Fail-soft, ne fail-closed ───────────────────────────────────────────────
 * Metody vracejí `null`, když zákonný výsledek rozpad neunese (revize zmrazené
 * dřív, než se rozpad ukládal). Účetní můstek pak zaúčtuje 524 jednou částkou
 * bez střediska — přesně jak účtoval dosud. Zastavit kvůli tomu schválení
 * mzdového běhu by byla regrese: středisko je analytika navíc, ne podmínka
 * zaúčtování.
 */
final class PayrollEmployerInsuranceCostAllocation
{
    /**
     * Sociální pojistné zaměstnavatele rozdělené na pracovní vztahy.
     *
     * @param array<string,mixed> $root         firemní `result_snapshot` sociální sady
     * @param array<int,array<string,mixed>> $relationships employment_id → `result_snapshot` vztahu
     * @param int $total                        částka závazku, na kterou musí součet sednout
     * @return array<int,int>|null employment_id → částka, nebo null když rozpad nelze doložit
     */
    public static function social(array $root, array $relationships, int $total): ?array
    {
        if ($relationships === []) {
            return null;
        }
        $beforeDiscount = self::nonNegativeInt(
            $root,
            'employer_contribution_before_discount_minor_units',
        );
        $discount = self::nonNegativeInt($root, 'part_time_discount_minor_units');
        if ($beforeDiscount === null || $discount === null
            || $beforeDiscount - $discount !== $total
        ) {
            return null;
        }

        $categoryAmounts = self::categoryAmounts($root, $beforeDiscount);
        if ($categoryAmounts === null) {
            return null;
        }

        $cappedBases = [];
        $discountBases = [];
        $categoryBases = [];
        foreach ($relationships as $employmentId => $relationship) {
            $base = self::nonNegativeInt(
                $relationship,
                'capped_assessment_base_minor_units',
            );
            if ($base === null) {
                return null;
            }
            $cappedBases[$employmentId] = $base;
            $discountBases[$employmentId] =
                ($relationship['part_time_employer_discount'] ?? null) === 'verified'
                    ? $base
                    : 0;
            if ($categoryAmounts === []) {
                continue;
            }
            $category = $relationship['employer_rate_category'] ?? null;
            // Vztah, jehož kategorii firemní rozpad nezná, by dostal nulu a jeho
            // středisko by o svůj kus pojistného tiše přišlo. Radši nedělit.
            if (!is_string($category) || !array_key_exists($category, $categoryAmounts)) {
                return null;
            }
            $categoryBases[$category][$employmentId] = $base;
        }

        if ($categoryAmounts === []) {
            return self::guarded(
                static fn (): array => EmployerSocialInsuranceAllocation::allocate(
                    $cappedBases,
                    $discountBases,
                    $beforeDiscount,
                    $discount,
                ),
                $total,
            );
        }
        $weights = [];
        foreach ($categoryAmounts as $category => $_amount) {
            $weights[$category] = [];
            foreach (array_keys($cappedBases) as $employmentId) {
                $weights[$category][$employmentId] =
                    $categoryBases[$category][$employmentId] ?? 0;
            }
        }

        return self::guarded(
            static fn (): array => EmployerSocialInsuranceAllocation::allocateByCategory(
                $weights,
                $categoryAmounts,
                $discountBases,
                $discount,
            ),
            $total,
        );
    }

    /**
     * Zdravotní pojistné zaměstnavatele rozdělené na pracovní vztahy.
     *
     * @param array<int,int> $personTotals            employee_id → pojistné zaměstnavatele osoby
     * @param array<int,array<int,int>> $relationshipWeights employee_id → employment_id → základ
     * @param int $total                              částka závazku
     * @return array<int,int>|null employment_id → částka, nebo null když rozpad nelze doložit
     */
    public static function health(
        array $personTotals,
        array $relationshipWeights,
        int $total,
    ): ?array {
        if ($personTotals === [] || array_sum($personTotals) !== $total) {
            return null;
        }
        $allocations = [];
        foreach ($personTotals as $employeeId => $amount) {
            $weights = $relationshipWeights[$employeeId] ?? [];
            if ($weights === []) {
                return null;
            }
            if (array_sum($weights) === 0) {
                // Osoba bez vyměřovacího základu (typicky jen doplatek do
                // minima § 3 odst. 10). Poměr neexistuje, dělí se rovným dílem —
                // jinak by alokace nevznikla vůbec.
                $weights = array_fill_keys(array_keys($weights), 1);
            }
            $share = self::guarded(
                static fn (): array => EmployerSocialInsuranceAllocation::allocateByWeights(
                    $weights,
                    $amount,
                    'zdravotní pojistné zaměstnavatele',
                ),
                $amount,
            );
            if ($share === null) {
                return null;
            }
            foreach ($share as $employmentId => $value) {
                if (isset($allocations[$employmentId])) {
                    return null;
                }
                $allocations[$employmentId] = $value;
            }
        }
        ksort($allocations, SORT_NUMERIC);

        return array_sum($allocations) === $total ? $allocations : null;
    }

    /**
     * @param array<string,mixed> $root
     * @return array<string,int>|null prázdné pole = rozpad chybí (jedna kategorie)
     */
    private static function categoryAmounts(array $root, int $beforeDiscount): ?array
    {
        $rows = $root['employer_categories'] ?? [];
        if (!is_array($rows) || !array_is_list($rows)) {
            return null;
        }
        $amounts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return null;
            }
            $category = $row['category'] ?? null;
            $amount = self::nonNegativeInt($row, 'contribution_minor_units');
            if (!is_string($category) || $category === '' || $amount === null
                || isset($amounts[$category])
            ) {
                return null;
            }
            $amounts[$category] = $amount;
        }
        if ($amounts !== [] && array_sum($amounts) !== $beforeDiscount) {
            return null;
        }

        return $amounts;
    }

    /**
     * @param callable():array<int,int> $allocator
     * @return array<int,int>|null
     */
    private static function guarded(callable $allocator, int $total): ?array
    {
        try {
            $allocations = $allocator();
        } catch (\Throwable) {
            return null;
        }

        return array_sum($allocations) === $total ? $allocations : null;
    }

    /** @param array<string,mixed> $row */
    private static function nonNegativeInt(array $row, string $field): ?int
    {
        $value = $row[$field] ?? null;

        return is_int($value) && $value >= 0 ? $value : null;
    }
}
