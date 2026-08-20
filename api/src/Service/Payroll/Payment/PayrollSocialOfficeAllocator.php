<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

/**
 * Rozpad sociálního odvodu jednoho mzdového běhu na mzdové účtárny.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč vůbec
 * ─────────────────────────────────────────────────────────────────────────────
 * Variabilní symbol zaměstnavatele pro sociální pojistné je na
 * `payroll_offices.social_security_variable_symbol`, tedy PER ÚČTÁRNU. Odvod se
 * proto nedá poslat jednou platbou za celou firmu, která má účtáren víc — každá
 * má vlastní registraci u OSSZ. Účtárna se bere z PRACOVNÍHO VZTAHU
 * (`people[].employments[].employment.office_id`), ne z `office_id` v kořeni
 * zmrazeného vstupu: ten je jen FILTR ROZSAHU běhu a u celofiremního běhu je
 * legitimně `null`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč ALOKACE kořenových částek, a ne přepočet po účtárnách
 * ─────────────────────────────────────────────────────────────────────────────
 * Nabízelo by se spočítat pojistné zaměstnavatele znovu po účtárnách (základ
 * účtárny × sazba, zaokrouhlit nahoru podle § 7 odst. 3). Jenže součet takových
 * částek je obecně VYŠŠÍ než kořenový výsledek — každá účtárna by si nesla
 * vlastní zaokrouhlení nahoru. A na rovnost „součet závazků = kořenový výsledek"
 * stojí dvě existující brány:
 *
 *   * {@see \MyInvoice\Service\Payroll\ControlTotals\PayrollControlTotalsCalculator}
 *     staví `liabilities['social_insurance']` jako employee + employer z KOŘENE,
 *   * {@see \MyInvoice\Service\Payroll\Posting\PayrollPostingReconciliationService}
 *     tenhle kontrolní součet porovnává se SOUČTEM zmaterializovaných závazků
 *     a rozdíl vykáže jako nesoulad účtárna ↔ účetnictví ↔ platby.
 *
 * Kořenový výsledek je tedy SSOT částky; tahle třída ji jen rozděluje. Rozdělení
 * je celočíselné metodou největších zbytků (largest remainder), takže součet
 * sedí na haléř a rozdělení je deterministické (při shodném zbytku vyhrává nižší
 * `office_id`).
 *
 * Váhou je vyměřovací základ po ročním maximu (`capped_assessment_base_minor_units`)
 * vztahu — tentýž základ, kterým se pojistné počítalo. U jediné účtárny je proto
 * výsledek shodný s dosavadním chováním (všechno padne na ni).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč i rozpad po sazbových kategoriích § 5a
 * ─────────────────────────────────────────────────────────────────────────────
 * Přehled o výši pojistného (PVPOJ) se podává ZA REGISTRACI U OSSZ, tedy za
 * účtárnu, a uvnitř se vykazuje po blocích A/B/C (§ 5a odst. 1 písm. a/b/c).
 * Kdyby si přehled dělil částky vlastním pravidlem, vznikla by druhá
 * implementace rozdělení a s ní trvalý rozdíl mezi podáním, závazkem a
 * účetnictvím. Rozdělení proto vzniká JEN TADY a platí obojí najednou:
 *
 *   * součet bloků A+B+C jedné účtárny = její zmaterializovaný závazek,
 *   * součet přes účtárny = kořenový výsledek, blok po bloku.
 *
 * Docílí se toho tím, že se rozděluje po BUŇKÁCH (účtárna × kategorie):
 * kořenové pojistné kategorie se rozdělí mezi účtárny podle dílčích základů té
 * kategorie, a teprve součet buněk dá pojistné účtárny. Sloupcové součty tedy
 * sedí konstrukcí a řádkové jsou z nich odvozené.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč se dělí v celých korunách
 * ─────────────────────────────────────────────────────────────────────────────
 * Pojistné je podle § 7 odst. 3 (zaměstnavatel) i § 8 odst. 1 (zaměstnanec)
 * vždy v celých korunách a PVPOJ jiné než celé koruny vykázat neumí. Dělení po
 * haléřích by proto účtárně vyrobilo částku, kterou nelze podat. Je-li dělená
 * částka násobkem 100 haléřů (v reálných datech vždy), dělí se v korunách;
 * historická data s haléři se dělí po haléřích, ať se nikde nezastaví běh,
 * který dnes prochází.
 */
final class PayrollSocialOfficeAllocator
{
    /**
     * @param array<string,mixed> $input zmrazený vstup běhu (payroll-run-input.v2)
     * @param list<array<string,mixed>> $people osoby sociálního výsledku
     * @param array<string,mixed> $root kořenový výsledek (payroll-social-result.v1)
     * @return list<array{
     *   office_id:int,
     *   capped_base_minor:int,
     *   category_base_minor:array<string,int>,
     *   category_contribution_minor:array<string,int>,
     *   employer_before_discount_minor:int,
     *   employer_discount_minor:int,
     *   employer_discount_base_minor:int,
     *   employer_discount_person_count:int,
     *   employee_before_discount_minor:int,
     *   employee_discount_minor:int,
     *   employee_discount_base_minor:int,
     *   employee_discount_person_count:int,
     *   employee_minor:int,
     *   employer_minor:int,
     *   amount_minor:int
     * }>
     */
    public function allocate(array $input, array $people, array $root): array
    {
        $employeeTotal = $this->nonNegativeInt(
            $root['employee_contribution_minor_units'] ?? null,
            'kořenový odvod zaměstnanců',
        );
        $employerTotal = $this->nonNegativeInt(
            $root['employer_contribution_minor_units'] ?? null,
            'kořenový odvod zaměstnavatele',
        );
        $employerBeforeDiscount = $this->nonNegativeInt(
            $root['employer_contribution_before_discount_minor_units'] ?? null,
            'kořenový odvod zaměstnavatele před slevou',
        );
        $employerDiscount = $this->nonNegativeInt(
            $root['part_time_discount_minor_units'] ?? null,
            'kořenová sleva zaměstnavatele',
        );
        if ($employerDiscount > $employerBeforeDiscount
            || $employerTotal !== $employerBeforeDiscount - $employerDiscount
        ) {
            throw new \DomainException(
                'Kořenová sleva zaměstnavatele neodpovídá odvodu před slevou.',
            );
        }
        $categories = $this->rootCategories($root, $employerBeforeDiscount);
        $officeByEmployment = $this->officeByEmployment($input);
        if ($officeByEmployment === []) {
            throw new \DomainException(
                'Zmrazený vstup neobsahuje žádný pracovní vztah s účtárnou.',
            );
        }
        $offices = array_values(array_unique(array_values($officeByEmployment)));
        sort($offices, SORT_NUMERIC);
        $zeroByOffice = array_fill_keys($offices, 0);

        $employeeBeforeByOffice = $zeroByOffice;
        $employeeDiscountByOffice = $zeroByOffice;
        $employeeDiscountBaseByOffice = $zeroByOffice;
        $employeeDiscountCountByOffice = $zeroByOffice;
        $employerDiscountBaseByOffice = $zeroByOffice;
        $employerDiscountCountByOffice = $zeroByOffice;
        $weightByOffice = $zeroByOffice;
        /** @var array<string,array<int,int>> $categoryWeights */
        $categoryWeights = [];
        foreach (array_keys($categories) as $category) {
            $categoryWeights[$category] = $zeroByOffice;
        }
        $seenEmployments = [];
        foreach ($people as $person) {
            $personWeights = $zeroByOffice;
            foreach ($this->relationships($person, $categories !== []) as $relationship) {
                $employmentId = $relationship['employment_id'];
                if (isset($seenEmployments[$employmentId])) {
                    throw new \DomainException(
                        "Sociální výsledek obsahuje vztah employment:{$employmentId} vícekrát.",
                    );
                }
                $seenEmployments[$employmentId] = true;
                $officeId = $officeByEmployment[$employmentId] ?? null;
                if ($officeId === null) {
                    throw new \DomainException(
                        "Vztah employment:{$employmentId} sociálního výsledku není ve zmrazeném vstupu.",
                    );
                }
                $base = $relationship['capped_assessment_base_minor_units'];
                $personWeights[$officeId] = $this->add(
                    $personWeights[$officeId],
                    $base,
                );
                $weightByOffice[$officeId] = $this->add(
                    $weightByOffice[$officeId],
                    $base,
                );
                $category = $relationship['employer_rate_category'];
                if ($category !== null) {
                    if (!isset($categoryWeights[$category])) {
                        throw new \DomainException(
                            "Vztah employment:{$employmentId} má sazbovou kategorii,"
                            . ' kterou kořenový výsledek nerozpadá.',
                        );
                    }
                    $categoryWeights[$category][$officeId] = $this->add(
                        $categoryWeights[$category][$officeId],
                        $base,
                    );
                }
                /*
                 * Sleva zaměstnavatele za kratší úvazek je podle § 7b VÁZANÁ NA
                 * VZTAH, takže patří celá do účtárny toho vztahu — dělí se jen
                 * kořenová částka slevy, a to podle úhrnů základů, ze kterých
                 * vznikla.
                 */
                if ($relationship['part_time_discount_claimed']) {
                    $employerDiscountBaseByOffice[$officeId] = $this->add(
                        $employerDiscountBaseByOffice[$officeId],
                        $base,
                    );
                    ++$employerDiscountCountByOffice[$officeId];
                }
            }
            /*
             * Pojistné zaměstnance je § 8 odst. 1 spočítané NA OSOBU (jedno
             * zaokrouhlení z úhrnu jejích vztahů), takže rozpad na účtárny musí
             * proběhnout uvnitř osoby. Člověk se dvěma vztahy ve dvou účtárnách
             * je vzácný, ale legitimní — a tichý přesun celé částky pod jednu
             * registraci by druhé účtárně chyběl v přehledu.
             */
            $personResult = $this->object(
                $person['result_snapshot'] ?? null,
                'výsledek osoby sociálního pojištění',
            );
            $employeeAfter = $this->nonNegativeInt(
                $personResult['employee_contribution_minor_units'] ?? null,
                'odvod osoby sociálního pojištění',
            );
            $personDiscount = $this->nonNegativeInt(
                $personResult['working_pensioner_discount_minor_units'] ?? 0,
                'sleva osoby sociálního pojištění',
            );
            $employeeBefore = $this->nonNegativeInt(
                $personResult['employee_contribution_before_discount_minor_units']
                    ?? $this->add($employeeAfter, $personDiscount),
                'odvod osoby sociálního pojištění před slevou',
            );
            if ($employeeBefore - $personDiscount !== $employeeAfter) {
                throw new \DomainException(
                    'Sleva osoby sociálního pojištění neodpovídá jejímu odvodu.',
                );
            }
            foreach ($this->distribute($employeeBefore, $personWeights) as $officeId => $share) {
                $employeeBeforeByOffice[$officeId] = $this->add(
                    $employeeBeforeByOffice[$officeId],
                    $share,
                );
            }
            if ($personDiscount > 0) {
                foreach ($this->distribute($personDiscount, $personWeights) as $officeId => $share) {
                    $employeeDiscountByOffice[$officeId] = $this->add(
                        $employeeDiscountByOffice[$officeId],
                        $share,
                    );
                }
                /*
                 * Sleva pracujícího důchodce je na OSOBĚ, ne na vztahu. Osoba
                 * se dvěma vztahy ve dvou účtárnách se proto do počtu započítá
                 * v obou — úhrn počtů přes účtárny pak může být vyšší než
                 * kořenový počet osob. Alternativa (přiřadit osobu jen účtárně
                 * s největším podílem) by druhé účtárně vykázala slevu s nulovým
                 * počtem osob, což XSD nepřipouští.
                 */
                foreach ($offices as $officeId) {
                    if ($personWeights[$officeId] > 0) {
                        ++$employeeDiscountCountByOffice[$officeId];
                        $employeeDiscountBaseByOffice[$officeId] = $this->add(
                            $employeeDiscountBaseByOffice[$officeId],
                            $personWeights[$officeId],
                        );
                    }
                }
            }
        }
        if (array_sum($employeeBeforeByOffice) - array_sum($employeeDiscountByOffice)
            !== $employeeTotal
        ) {
            throw new \DomainException(
                'Rozpad odvodu zaměstnanců na účtárny nesouhlasí s kořenovým výsledkem.',
            );
        }

        /** @var array<int,array<string,int>> $contributionByOffice */
        $contributionByOffice = array_fill_keys($offices, []);
        $employerBeforeByOffice = $zeroByOffice;
        foreach ($categories as $category => $rollup) {
            if (array_sum($categoryWeights[$category]) !== $rollup['base']) {
                throw new \DomainException(
                    "Dílčí základ sazbové kategorie {$category} neodpovídá součtu vztahů.",
                );
            }
            foreach ($this->distribute(
                $rollup['contribution'],
                $categoryWeights[$category],
            ) as $officeId => $share) {
                $contributionByOffice[$officeId][$category] = $share;
                $employerBeforeByOffice[$officeId] = $this->add(
                    $employerBeforeByOffice[$officeId],
                    $share,
                );
            }
        }
        if ($categories === []) {
            $employerBeforeByOffice = $this->distribute(
                $employerBeforeDiscount,
                $weightByOffice,
            );
        }
        $employerDiscountByOffice = $this->distribute(
            $employerDiscount,
            $employerDiscountBaseByOffice,
        );

        $result = [];
        foreach ($offices as $officeId) {
            $employerBefore = $employerBeforeByOffice[$officeId];
            $officeDiscount = $employerDiscountByOffice[$officeId];
            if ($officeDiscount > $employerBefore) {
                throw new \DomainException(
                    'Sleva zaměstnavatele připadající na účtárnu převyšuje její pojistné.',
                );
            }
            $employeeBefore = $employeeBeforeByOffice[$officeId];
            $employeeDiscount = $employeeDiscountByOffice[$officeId];
            if ($employeeDiscount > $employeeBefore) {
                throw new \DomainException(
                    'Sleva zaměstnanců připadající na účtárnu převyšuje jejich pojistné.',
                );
            }
            $employee = $employeeBefore - $employeeDiscount;
            $employer = $employerBefore - $officeDiscount;
            $categoryBase = [];
            foreach ($categories as $category => $rollup) {
                $categoryBase[$category] = $categoryWeights[$category][$officeId];
            }
            $result[] = [
                'office_id' => $officeId,
                'capped_base_minor' => $weightByOffice[$officeId],
                'category_base_minor' => $categoryBase,
                'category_contribution_minor' =>
                    $contributionByOffice[$officeId],
                'employer_before_discount_minor' => $employerBefore,
                'employer_discount_minor' => $officeDiscount,
                'employer_discount_base_minor' =>
                    $employerDiscountBaseByOffice[$officeId],
                'employer_discount_person_count' =>
                    $employerDiscountCountByOffice[$officeId],
                'employee_before_discount_minor' => $employeeBefore,
                'employee_discount_minor' => $employeeDiscount,
                'employee_discount_base_minor' =>
                    $employeeDiscountBaseByOffice[$officeId],
                'employee_discount_person_count' =>
                    $employeeDiscountCountByOffice[$officeId],
                'employee_minor' => $employee,
                'employer_minor' => $employer,
                'amount_minor' => $this->add($employee, $employer),
            ];
        }
        if (array_sum(array_column($result, 'employer_minor')) !== $employerTotal) {
            throw new \DomainException(
                'Rozpad odvodu zaměstnavatele na účtárny nesouhlasí s kořenovým výsledkem.',
            );
        }

        return $result;
    }

    /**
     * Kořenový rozpad § 5a odst. 1 na sazbové kategorie.
     *
     * Revize zmrazená dřív, než výsledek kategorie nesl, rozpad nemá; pak se
     * vrátí prázdné pole a pojistné zaměstnavatele se dělí jediným úhrnem
     * základů — tedy přesně tak, jak se dělilo předtím.
     *
     * @param array<string,mixed> $root
     * @return array<string,array{base:int,contribution:int}>
     */
    private function rootCategories(array $root, int $employerBeforeDiscount): array
    {
        $rows = $root['employer_categories'] ?? [];
        if ($rows === [] || $rows === null) {
            return [];
        }
        $categories = [];
        $contributionTotal = 0;
        foreach ($this->rows($rows, 'rozpad sazbových kategorií') as $row) {
            $category = $row['category'] ?? null;
            if (!is_string($category) || $category === ''
                || isset($categories[$category])
            ) {
                throw new \DomainException(
                    'Rozpad sazbových kategorií má neznámou nebo zdvojenou kategorii.',
                );
            }
            $categories[$category] = [
                'base' => $this->nonNegativeInt(
                    $row['assessment_base_minor_units'] ?? null,
                    'dílčí vyměřovací základ sazbové kategorie',
                ),
                'contribution' => $this->nonNegativeInt(
                    $row['contribution_minor_units'] ?? null,
                    'dílčí pojistné sazbové kategorie',
                ),
            ];
            $contributionTotal = $this->add(
                $contributionTotal,
                $categories[$category]['contribution'],
            );
        }
        if ($contributionTotal !== $employerBeforeDiscount) {
            throw new \DomainException(
                'Rozpad sazbových kategorií nedává pojistné zaměstnavatele před slevou.',
            );
        }
        ksort($categories, SORT_STRING);

        return $categories;
    }

    /**
     * Účtárna pracovního vztahu ze zmrazeného vstupu.
     *
     * Vztah bez účtárny se tu ZASTAVÍ. Do běhu by se dostat neměl — snapshot
     * builder na něj má blocker `employment_without_office` a zápisová cesta ho
     * od migrace 1503 nezaloží — ale historická data vzniklá dřív ho mít mohou.
     * Tichý pád do společného balíku by přiřadil odvod k variabilnímu symbolu,
     * který nikdo nezvolil; vynechání by ho z odvodu ztratilo úplně. Obojí je
     * horší než hlasitá chyba v okamžiku, kdy se dá vztah opravit a běh
     * přepočítat.
     *
     * @param array<string,mixed> $input
     * @return array<int,int> employment_id => office_id
     */
    private function officeByEmployment(array $input): array
    {
        $map = [];
        foreach ($this->rows($input['people'] ?? null, 'osoby zmrazeného vstupu') as $person) {
            foreach ($this->rows(
                $person['employments'] ?? null,
                'vztahy zmrazeného vstupu',
            ) as $relationship) {
                $employment = $this->object(
                    $relationship['employment'] ?? null,
                    'vztah zmrazeného vstupu',
                );
                $employmentId = $this->positiveInt(
                    $employment['id'] ?? null,
                    'identifikátor vztahu zmrazeného vstupu',
                );
                $officeId = $employment['office_id'] ?? null;
                if ($officeId === null) {
                    throw new \DomainException(
                        "Pracovní vztah employment:{$employmentId} nemá mzdovou účtárnu."
                        . ' Bez ní nelze vykázat odvod sociálního pojistného —'
                        . ' doplňte účtárnu u vztahu a přepočítejte mzdový běh.',
                    );
                }
                if (isset($map[$employmentId])) {
                    throw new \DomainException(
                        "Zmrazený vstup obsahuje vztah employment:{$employmentId} vícekrát.",
                    );
                }
                $map[$employmentId] = $this->positiveInt(
                    $officeId,
                    'mzdovou účtárnu pracovního vztahu',
                );
            }
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $person
     * @return list<array{
     *   employment_id:int,
     *   capped_assessment_base_minor_units:int,
     *   employer_rate_category:?string,
     *   part_time_discount_claimed:bool
     * }>
     */
    private function relationships(array $person, bool $requireCategory): array
    {
        $rows = [];
        foreach ($this->rows(
            $person['relationships'] ?? null,
            'vztahy osoby sociálního výsledku',
        ) as $relationship) {
            $snapshot = $this->object(
                $relationship['result_snapshot'] ?? null,
                'výsledek vztahu sociálního pojištění',
            );
            $category = $snapshot['employer_rate_category'] ?? null;
            if ($requireCategory
                && (!is_string($category) || $category === '')
            ) {
                throw new \DomainException(
                    'Vztah sociálního výsledku nemá sazbovou kategorii,'
                    . ' přestože kořenový výsledek pojistné po kategoriích rozpadá.',
                );
            }
            /*
             * `part_time_employer_discount_outcome` je poslední slovo: nárok
             * může být ověřený a přesto neuplatněný (souběh, překročený limit).
             * Stejné pravidlo vyhodnocuje PVPOJ přehled — proto tady, aby
             * existovalo jen jednou.
             */
            $outcome = $snapshot['part_time_employer_discount_outcome'] ?? null;
            $claimed = ($snapshot['part_time_employer_discount'] ?? null) === 'verified'
                && ($outcome === null || $outcome === 'applied');
            $rows[] = [
                'employment_id' => $this->positiveInt(
                    $relationship['employment_id'] ?? null,
                    'identifikátor vztahu sociálního výsledku',
                ),
                'capped_assessment_base_minor_units' => $this->nonNegativeInt(
                    $snapshot['capped_assessment_base_minor_units'] ?? null,
                    'vyměřovací základ vztahu po ročním maximu',
                ),
                'employer_rate_category' => $requireCategory
                    ? (string) $category
                    : null,
                'part_time_discount_claimed' => $claimed,
            ];
        }

        return $rows;
    }

    /**
     * Celočíselné rozdělení částky podle vah metodou největších zbytků.
     *
     * Součet výstupu je VŽDY roven `$amount`. Při nulových vahách (celý běh má
     * nulový vyměřovací základ, ale nenulové pojistné existovat nemůže) padne
     * částka na nejnižší `office_id`, aby se nikdy neztratila.
     *
     * @param array<int,int> $weights office_id => váha
     * @return array<int,int> office_id => podíl
     */
    private function distribute(int $amount, array $weights): array
    {
        /*
         * Pojistné je vždy v celých korunách (§ 7 odst. 3, § 8 odst. 1) a PVPOJ
         * jinou částku vykázat neumí, takže se dělí v korunách a výsledek se
         * vrací zpět v haléřích. Historická data s haléři se dělí po haléřích.
         */
        if ($amount !== 0 && $amount % 100 === 0) {
            $shares = [];
            foreach ($this->distributeUnits(
                intdiv($amount, 100),
                $weights,
            ) as $officeId => $share) {
                $shares[$officeId] = $this->multiply($share, 100);
            }

            return $shares;
        }

        return $this->distributeUnits($amount, $weights);
    }

    /**
     * @param array<int,int> $weights office_id => váha
     * @return array<int,int> office_id => podíl
     */
    private function distributeUnits(int $amount, array $weights): array
    {
        $shares = array_fill_keys(array_keys($weights), 0);
        if ($amount === 0 || $shares === []) {
            return $shares;
        }
        $totalWeight = 0;
        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new \DomainException(
                    'Váha rozpadu sociálního odvodu nesmí být záporná.',
                );
            }
            $totalWeight = $this->add($totalWeight, $weight);
        }
        $officeIds = array_keys($weights);
        sort($officeIds, SORT_NUMERIC);
        if ($totalWeight === 0) {
            $shares[$officeIds[0]] = $amount;

            return $shares;
        }
        $assigned = 0;
        $remainders = [];
        foreach ($officeIds as $officeId) {
            $product = $this->multiply($amount, $weights[$officeId]);
            $share = intdiv($product, $totalWeight);
            $shares[$officeId] = $share;
            $assigned = $this->add($assigned, $share);
            $remainders[] = [
                'office_id' => $officeId,
                'remainder' => $product % $totalWeight,
            ];
        }
        usort(
            $remainders,
            static fn (array $left, array $right): int =>
                $right['remainder'] <=> $left['remainder']
                    ?: $left['office_id'] <=> $right['office_id'],
        );
        $leftover = $amount - $assigned;
        if ($leftover < 0 || $leftover > count($remainders)) {
            throw new \DomainException(
                'Rozpad sociálního odvodu na účtárny selhal.',
            );
        }
        for ($index = 0; $index < $leftover; $index++) {
            $officeId = $remainders[$index]['office_id'];
            $shares[$officeId] = $this->add($shares[$officeId], 1);
        }

        return $shares;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value, string $context): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException("{$context} musí být seznam.");
        }

        return array_map(
            fn (mixed $row): array => $this->object($row, $context),
            $value,
        );
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("{$context} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \DomainException("{$context} musí mít textové klíče.");
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function positiveInt(mixed $value, string $context): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \DomainException("{$context} musí být kladné celé číslo.");
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value, string $context): int
    {
        if (!is_int($value) || $value < 0) {
            throw new \DomainException("{$context} musí být nezáporné celé číslo.");
        }

        return $value;
    }

    private function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \OverflowException('Součet rozpadu sociálního odvodu přetekl.');
        }

        return $left + $right;
    }

    private function multiply(int $left, int $right): int
    {
        if ($left === 0 || $right === 0) {
            return 0;
        }
        if ($left < 0 || $right < 0) {
            throw new \DomainException(
                'Rozpad sociálního odvodu pracuje jen s nezápornými čísly.',
            );
        }
        if ($left > intdiv(PHP_INT_MAX, $right)) {
            throw new \OverflowException('Rozpad sociálního odvodu přetekl.');
        }

        return $left * $right;
    }
}
