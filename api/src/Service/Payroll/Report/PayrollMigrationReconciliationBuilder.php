<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Report;

/**
 * Porovnání „naše přepočtená mzda vs. mzda převzatá z původního systému".
 *
 * ── Proč to existuje ────────────────────────────────────────────────────────
 * Převzatý měsíc jde v MyÚčtu přepočítat vlastní legislativní sadou, jenže
 * původní systém tatáž čísla už podal do JMHZ, na zdravotní pojišťovny a na
 * finanční úřad. Přepočet historického měsíce je bez téhle sestavy hazard:
 * účetní nemá jak zjistit, že se výsledek s podaným rozešel.
 *
 * ── Granularita ─────────────────────────────────────────────────────────────
 * Řádek je OSOBA × MĚSÍC, ne pracovní vztah × měsíc, a je to záměr:
 *
 *  - Převzatá strana má granularitu vztahu (`MZ.RefPomer`) a sčítá se na osobu.
 *  - Naše strana ji mít NEMŮŽE. Vyměřovací základ i pojistné jsou veličiny osoby
 *    (§ 5 z. č. 589/1992 Sb., § 3 z. č. 592/1992 Sb.), záloha na daň a bonus jsou
 *    veličiny poplatníka (§ 38h ZDP). Na vztah je v `payroll_statutory_*_results`
 *    rozepsaný jen vyměřovací základ; pojistné, daň ani čistá mzda ne. Rozpočítat
 *    je na vztahy by znamenalo vymyslet si alokační klíč a porovnávat proti němu —
 *    tedy vyrobit rozdíl, který v datech není, nebo naopak schovat ten, který tam je.
 *
 * Který vztah do řádku přispěl, sestava ukazuje v `relationships` — rozdíl
 * v počtu vztahů je tím pádem vidět, i když se čísla porovnávají za osobu.
 * U osoby s jedním vztahem (naprostá většina) obě granularity splývají.
 *
 * ── Chybějící protějšek ─────────────────────────────────────────────────────
 * Měsíc, který MyÚčto nepočítalo, ani vztah, který původní systém nemá, se NESMÍ
 * tvářit jako nulový rozdíl. Proto je `difference_minor` v takovém případě `null`
 * (ne 0) a stav je `reference_missing` / `calculated_missing`. V seznamu odchylek
 * stojí takové položky první: neznámý rozdíl je horší než známý.
 *
 * ── Peníze ──────────────────────────────────────────────────────────────────
 * Všechno jsou haléře v celých číslech, porovnává se rovností. Žádný float.
 *
 * Třída je čistá funkce nad dvěma seznamy; data načítá
 * {@see \MyInvoice\Repository\Payroll\PayrollMigrationReconciliationRepository}.
 */
final class PayrollMigrationReconciliationBuilder
{
    /**
     * Metriky porovnávané na řádku osoby, v pořadí sloupců sestavy.
     *
     * `employer_social` tady schválně NENÍ: pojistné zaměstnavatele na sociální
     * zabezpečení osobní veličina není (§ 5a odst. 1 z. č. 589/1992 Sb.), počítá se
     * z úhrnu vyměřovacích základů firmy. Porovnává se proto jen v součtech za měsíc
     * a za rok, kde je to částka téhož druhu na obou stranách.
     *
     * @var list<string>
     */
    public const ROW_METRICS = [
        'gross', 'net', 'social_base', 'health_base',
        'employee_social', 'employee_health', 'employer_health',
        'advance_tax', 'withholding_tax', 'tax_bonus',
    ];

    /** Metriky, které dávají smysl jen v součtu za měsíc a rok. @var list<string> */
    public const TOTAL_ONLY_METRICS = ['employer_social'];

    /** @var list<string> */
    public const STATUSES = ['match', 'differs', 'reference_missing', 'calculated_missing'];

    /**
     * @param list<array<string,mixed>> $reference převzaté úhrny, granularita vztah × měsíc
     * @param list<array<string,mixed>> $calculated náš výsledek, granularita osoba × měsíc
     * @param array<string,int|null> $calculatedEmployerSocial období => pojistné zaměstnavatele SP za firmu
     * @return array<string,mixed>
     */
    public function build(
        int $year,
        array $reference,
        array $calculated,
        array $calculatedEmployerSocial = [],
    ): array {
        if ($year < 2000 || $year > 2200) {
            throw new \InvalidArgumentException('Mzdový rok musí být v rozsahu 2000 až 2200.');
        }

        $referenceByPerson = $this->groupReference($year, $reference);
        $calculatedByPerson = $this->indexCalculated($year, $calculated);

        /** @var array<string,array<string,array<string,mixed>>> $rowsByPeriod */
        $rowsByPeriod = [];
        foreach ([...array_keys($referenceByPerson), ...array_keys($calculatedByPerson)] as $key) {
            if (isset($rowsByPeriod[substr($key, 0, 7)][$key])) {
                continue;
            }
            $referenceSide = $referenceByPerson[$key] ?? null;
            $calculatedSide = $calculatedByPerson[$key] ?? null;
            $rowsByPeriod[substr($key, 0, 7)][$key] = $this->row($referenceSide, $calculatedSide);
        }

        $months = [];
        $deviations = [];
        /** @var array<string,list<array{?int,?int}>> $yearCells */
        $yearCells = [];
        $rowCount = 0;
        $missingCounterpartCount = 0;
        foreach ($this->periods($year) as $period) {
            $rows = array_values($rowsByPeriod[$period] ?? []);
            if ($rows === [] && !array_key_exists($period, $calculatedEmployerSocial)) {
                continue;
            }
            usort($rows, $this->rowOrder(...));

            /** @var array<string,list<array{?int,?int}>> $monthCells */
            $monthCells = [];
            foreach ($rows as $row) {
                $rowCount++;
                if ($row['presence'] !== 'both') {
                    $missingCounterpartCount++;
                }
                foreach (self::ROW_METRICS as $metric) {
                    /** @var array<string,mixed> $cell */
                    $cell = $row['metrics'][$metric];
                    $pair = [self::nullableInt($cell['reference_minor']), self::nullableInt($cell['calculated_minor'])];
                    $monthCells[$metric][] = $pair;
                    $yearCells[$metric][] = $pair;
                    if ($cell['status'] !== 'match') {
                        $deviations[] = [
                            'period' => $period,
                            'employee_id' => $row['employee_id'],
                            'full_name' => $row['full_name'],
                            'external_person_ref' => $row['external_person_ref'],
                            'metric' => $metric,
                            'reference_minor' => $pair[0],
                            'calculated_minor' => $pair[1],
                            'difference_minor' => self::nullableInt($cell['difference_minor']),
                            'status' => $cell['status'],
                        ];
                    }
                }
            }

            $employerSocial = [
                $this->sumReferenceEmployerSocial($referenceByPerson, $period),
                $calculatedEmployerSocial[$period] ?? null,
            ];
            $monthCells['employer_social'][] = $employerSocial;
            $yearCells['employer_social'][] = $employerSocial;

            $totals = $this->totals($monthCells);
            $months[] = [
                'period' => $period,
                'rows' => $rows,
                'totals' => $totals,
                'row_count' => count($rows),
                'deviation_count' => $this->deviationCount($rows),
                'missing_counterpart_count' => count(array_filter(
                    $rows,
                    static fn (array $row): bool => $row['presence'] !== 'both',
                )),
            ];
        }

        usort($deviations, $this->deviationOrder(...));
        $maxAbs = null;
        foreach ($deviations as $deviation) {
            $difference = $deviation['difference_minor'];
            if ($difference !== null && ($maxAbs === null || abs($difference) > $maxAbs)) {
                $maxAbs = abs($difference);
            }
        }

        return [
            'year' => $year,
            'row_metrics' => self::ROW_METRICS,
            'total_metrics' => [...self::ROW_METRICS, ...self::TOTAL_ONLY_METRICS],
            'months' => $months,
            'totals' => $this->totals($yearCells),
            'deviations' => $deviations,
            'summary' => [
                'row_count' => $rowCount,
                'deviation_count' => count($deviations),
                'missing_counterpart_count' => $missingCounterpartCount,
                'max_abs_difference_minor' => $maxAbs,
            ],
        ];
    }

    /**
     * Převzaté řádky (vztah × měsíc) sečtené na osobu a měsíc.
     *
     * Klíč je `období|osoba`, kde osoba je buď napárované `employee_id`, nebo — když
     * se vztah do MyÚčta nepřevedl — identifikátor osoby z původního systému. Nenapárovaná
     * osoba tak nespadne do cizího řádku a v sestavě se ukáže jako „nemá protějšek".
     *
     * @param list<array<string,mixed>> $reference
     * @return array<string,array<string,mixed>>
     */
    private function groupReference(int $year, array $reference): array
    {
        $grouped = [];
        foreach ($reference as $row) {
            $period = self::period($row['period'] ?? null, $year);
            if ($period === null) {
                continue;
            }
            $employeeId = self::nullablePositiveInt($row['employee_id'] ?? null);
            $personRef = trim((string) ($row['external_person_ref'] ?? ''));
            $key = $period . '|' . ($employeeId !== null ? 'e' . $employeeId : 'x' . $personRef);
            $grouped[$key] ??= [
                'period' => $period,
                'employee_id' => $employeeId,
                'external_person_ref' => $personRef !== '' ? $personRef : null,
                'relationships' => [],
                'metrics' => array_fill_keys(
                    [...self::ROW_METRICS, ...self::TOTAL_ONLY_METRICS],
                    0,
                ),
            ];
            $relationshipRef = trim((string) ($row['external_relationship_ref'] ?? ''));
            if ($relationshipRef !== '') {
                $grouped[$key]['relationships'][] = [
                    'external_relationship_ref' => $relationshipRef,
                    'employment_id' => self::nullablePositiveInt($row['employment_id'] ?? null),
                    'gross_minor' => self::integer($row['gross_minor'] ?? null) ?? 0,
                ];
            }
            foreach ([...self::ROW_METRICS, ...self::TOTAL_ONLY_METRICS] as $metric) {
                $grouped[$key]['metrics'][$metric] += self::integer($row[$metric . '_minor'] ?? null) ?? 0;
            }
        }

        return $grouped;
    }

    /**
     * Náš výsledek indexovaný stejným klíčem jako převzatá strana.
     *
     * @param list<array<string,mixed>> $calculated
     * @return array<string,array<string,mixed>>
     */
    private function indexCalculated(int $year, array $calculated): array
    {
        $indexed = [];
        foreach ($calculated as $row) {
            $period = self::period($row['period'] ?? null, $year);
            $employeeId = self::nullablePositiveInt($row['employee_id'] ?? null);
            if ($period === null || $employeeId === null) {
                continue;
            }
            $metrics = [];
            foreach (self::ROW_METRICS as $metric) {
                $metrics[$metric] = self::integer($row[$metric . '_minor'] ?? null);
            }
            $key = $period . '|e' . $employeeId;
            if (isset($indexed[$key])) {
                // Osoba ve dvou bězích téhož měsíce (víc provozoven). Chybějící dílčí
                // výsledek nesmí zmizet v součtu ostatních, proto fail-closed na null.
                foreach (self::ROW_METRICS as $metric) {
                    $previous = $indexed[$key]['metrics'][$metric];
                    $indexed[$key]['metrics'][$metric] = $previous === null || $metrics[$metric] === null
                        ? null
                        : $previous + $metrics[$metric];
                }
                continue;
            }
            $indexed[$key] = [
                'period' => $period,
                'employee_id' => $employeeId,
                'full_name' => self::nullableText($row['full_name'] ?? null),
                'metrics' => $metrics,
            ];
        }

        return $indexed;
    }

    /**
     * @param array<string,mixed>|null $reference
     * @param array<string,mixed>|null $calculated
     * @return array<string,mixed>
     */
    private function row(?array $reference, ?array $calculated): array
    {
        $presence = match (true) {
            $reference !== null && $calculated !== null => 'both',
            $reference !== null => 'reference_only',
            default => 'calculated_only',
        };

        $metrics = [];
        foreach (self::ROW_METRICS as $metric) {
            $referenceValue = $reference === null ? null : self::nullableInt($reference['metrics'][$metric] ?? null);
            $calculatedValue = $calculated === null ? null : self::nullableInt($calculated['metrics'][$metric] ?? null);
            $metrics[$metric] = self::cell($referenceValue, $calculatedValue);
        }

        $maxAbs = null;
        foreach ($metrics as $cell) {
            $difference = $cell['difference_minor'];
            if ($difference !== null && ($maxAbs === null || abs($difference) > $maxAbs)) {
                $maxAbs = abs($difference);
            }
        }

        return [
            'period' => (string) ($reference['period'] ?? $calculated['period'] ?? ''),
            'employee_id' => $reference['employee_id'] ?? $calculated['employee_id'] ?? null,
            'full_name' => $calculated['full_name'] ?? null,
            'external_person_ref' => $reference['external_person_ref'] ?? null,
            'presence' => $presence,
            'relationships' => $reference['relationships'] ?? [],
            'metrics' => $metrics,
            'max_abs_difference_minor' => $maxAbs,
            'has_deviation' => $presence !== 'both' || array_filter(
                $metrics,
                static fn (array $cell): bool => $cell['status'] !== 'match',
            ) !== [],
        ];
    }

    /**
     * Jedna porovnaná dvojice. Chybějící strana dává `null`, NIKDY nulu — nula
     * by na obrazovce vypadala jako „sedí to".
     *
     * @return array{reference_minor:?int,calculated_minor:?int,difference_minor:?int,status:string}
     */
    private static function cell(?int $reference, ?int $calculated): array
    {
        $status = match (true) {
            $reference === null && $calculated === null => 'match',
            $reference === null => 'reference_missing',
            $calculated === null => 'calculated_missing',
            $reference === $calculated => 'match',
            default => 'differs',
        };

        return [
            'reference_minor' => $reference,
            'calculated_minor' => $calculated,
            'difference_minor' => $reference !== null && $calculated !== null ? $reference - $calculated : null,
            'status' => $status,
        ];
    }

    /**
     * Součet sloupce. `incomplete` říká, že do součtu nepřispěly všechny řádky —
     * jinak by součet chybějícího protějšku tiše spolkl.
     *
     * @param array<string,list<array{?int,?int}>> $cells
     * @return array<string,array<string,mixed>>
     */
    private function totals(array $cells): array
    {
        $totals = [];
        foreach ([...self::ROW_METRICS, ...self::TOTAL_ONLY_METRICS] as $metric) {
            $pairs = $cells[$metric] ?? [];
            $referenceSum = null;
            $calculatedSum = null;
            $referencePresent = 0;
            $calculatedPresent = 0;
            foreach ($pairs as [$reference, $calculated]) {
                if ($reference !== null) {
                    $referenceSum = ($referenceSum ?? 0) + $reference;
                    $referencePresent++;
                }
                if ($calculated !== null) {
                    $calculatedSum = ($calculatedSum ?? 0) + $calculated;
                    $calculatedPresent++;
                }
            }
            $totals[$metric] = [
                ...self::cell($referenceSum, $calculatedSum),
                'incomplete' => $referencePresent !== count($pairs) || $calculatedPresent !== count($pairs),
            ];
        }

        return $totals;
    }

    /**
     * Pojistné zaměstnavatele na sociální zabezpečení z převzaté strany za měsíc.
     * Naše strana ho dodává za firmu, takže i převzatá musí být součet přes všechny
     * osoby daného období, ne hodnota řádku.
     *
     * @param array<string,array<string,mixed>> $referenceByPerson
     */
    private function sumReferenceEmployerSocial(array $referenceByPerson, string $period): ?int
    {
        $sum = null;
        foreach ($referenceByPerson as $key => $person) {
            if (substr($key, 0, 7) !== $period) {
                continue;
            }
            $sum = ($sum ?? 0) + (self::integer($person['metrics']['employer_social'] ?? null) ?? 0);
        }

        return $sum;
    }

    /** @param list<array<string,mixed>> $rows */
    private function deviationCount(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            foreach ($row['metrics'] as $cell) {
                if ($cell['status'] !== 'match') {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Řádky měsíce: nejdřív ty s největší odchylkou, aby účetní nemusela scrollovat.
     * Chybějící protějšek (`max_abs_difference_minor === null` a `has_deviation`)
     * stojí úplně nahoře.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function rowOrder(array $left, array $right): int
    {
        $rank = static fn (array $row): int => $row['presence'] === 'both' ? 1 : 0;
        return $rank($left) <=> $rank($right)
            ?: ($right['max_abs_difference_minor'] ?? -1) <=> ($left['max_abs_difference_minor'] ?? -1)
            ?: ((string) ($left['full_name'] ?? '')) <=> ((string) ($right['full_name'] ?? ''))
            ?: ((int) ($left['employee_id'] ?? 0)) <=> ((int) ($right['employee_id'] ?? 0))
            ?: ((string) ($left['external_person_ref'] ?? '')) <=> ((string) ($right['external_person_ref'] ?? ''));
    }

    /**
     * Odchylky od největší. Položka bez protějšku nemá rozdíl čím změřit, takže jde
     * první — neznámý rozdíl je horší než známý.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function deviationOrder(array $left, array $right): int
    {
        $magnitude = static fn (array $item): int => $item['difference_minor'] === null
            ? PHP_INT_MAX
            : abs((int) $item['difference_minor']);

        return $magnitude($right) <=> $magnitude($left)
            ?: ((string) $left['period']) <=> ((string) $right['period'])
            ?: ((int) ($left['employee_id'] ?? 0)) <=> ((int) ($right['employee_id'] ?? 0))
            ?: array_search($left['metric'], self::ROW_METRICS, true)
                <=> array_search($right['metric'], self::ROW_METRICS, true);
    }

    /** @return list<string> */
    private function periods(int $year): array
    {
        $periods = [];
        for ($month = 1; $month <= 12; $month++) {
            $periods[] = sprintf('%04d-%02d', $year, $month);
        }

        return $periods;
    }

    private static function period(mixed $value, int $year): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $period = substr($value, 0, 7);

        return preg_match('/^' . $year . '-(0[1-9]|1[0-2])$/D', $period) === 1 ? $period : null;
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : self::integer($value);
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        $integer = self::integer($value);

        return $integer !== null && $integer > 0 ? $integer : null;
    }

    private static function nullableText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim($value);

        return $text === '' ? null : $text;
    }
}
