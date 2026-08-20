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
 */
final class PayrollSocialOfficeAllocator
{
    /**
     * @param array<string,mixed> $input zmrazený vstup běhu (payroll-run-input.v2)
     * @param list<array<string,mixed>> $people osoby sociálního výsledku
     * @return list<array{
     *   office_id:int,
     *   employee_minor:int,
     *   employer_minor:int,
     *   amount_minor:int
     * }>
     */
    public function allocate(
        array $input,
        array $people,
        int $employeeTotal,
        int $employerTotal,
    ): array {
        if ($employeeTotal < 0 || $employerTotal < 0) {
            throw new \DomainException(
                'Sociální odvod k rozpadu nesmí být záporný.',
            );
        }
        $officeByEmployment = $this->officeByEmployment($input);
        if ($officeByEmployment === []) {
            throw new \DomainException(
                'Zmrazený vstup neobsahuje žádný pracovní vztah s účtárnou.',
            );
        }
        $offices = array_values(array_unique(array_values($officeByEmployment)));
        sort($offices, SORT_NUMERIC);

        $employeeByOffice = array_fill_keys($offices, 0);
        $weightByOffice = array_fill_keys($offices, 0);
        $seenEmployments = [];
        foreach ($people as $person) {
            $personWeights = array_fill_keys($offices, 0);
            foreach ($this->relationships($person) as $relationship) {
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
            }
            /*
             * Pojistné zaměstnance je § 8 odst. 1 spočítané NA OSOBU (jedno
             * zaokrouhlení z úhrnu jejích vztahů), takže rozpad na účtárny musí
             * proběhnout uvnitř osoby. Člověk se dvěma vztahy ve dvou účtárnách
             * je vzácný, ale legitimní — a tichý přesun celé částky pod jednu
             * registraci by druhé účtárně chyběl v přehledu.
             */
            $employeeContribution = $this->nonNegativeInt(
                $person['result_snapshot']['employee_contribution_minor_units'] ?? null,
                'odvod osoby sociálního pojištění',
            );
            foreach ($this->distribute(
                $employeeContribution,
                $personWeights,
            ) as $officeId => $share) {
                $employeeByOffice[$officeId] = $this->add(
                    $employeeByOffice[$officeId],
                    $share,
                );
            }
        }
        if (array_sum($employeeByOffice) !== $employeeTotal) {
            throw new \DomainException(
                'Rozpad odvodu zaměstnanců na účtárny nesouhlasí s kořenovým výsledkem.',
            );
        }
        $employerByOffice = $this->distribute($employerTotal, $weightByOffice);

        $result = [];
        foreach ($offices as $officeId) {
            $employee = $employeeByOffice[$officeId];
            $employer = $employerByOffice[$officeId];
            $result[] = [
                'office_id' => $officeId,
                'employee_minor' => $employee,
                'employer_minor' => $employer,
                'amount_minor' => $this->add($employee, $employer),
            ];
        }

        return $result;
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
     * @return list<array{employment_id:int,capped_assessment_base_minor_units:int}>
     */
    private function relationships(array $person): array
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
            $rows[] = [
                'employment_id' => $this->positiveInt(
                    $relationship['employment_id'] ?? null,
                    'identifikátor vztahu sociálního výsledku',
                ),
                'capped_assessment_base_minor_units' => $this->nonNegativeInt(
                    $snapshot['capped_assessment_base_minor_units'] ?? null,
                    'vyměřovací základ vztahu po ročním maximu',
                ),
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
