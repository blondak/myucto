<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverMonth;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverYear;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Převzatý měsíc → zmrazený podklad běhu. NIC SE NEPOČÍTÁ.
 *
 * ── Co to smí a co ne ───────────────────────────────────────────────────────
 * Jediná operace nad čísly je SOUČET řádků téhož měsíce (pracovní vztahy jedné
 * osoby, osoby jedné firmy). Žádná sazba, žádný strop, žádné dopočítání
 * chybějící veličiny: převzatý měsíc je záznam o tom, co vydal jiný program,
 * a dopočítaný údaj by se od zbytku nedal odlišit. Nevyplněná veličina je proto
 * nula, protože tak ji drží zdrojová tabulka, a nula se nikam nedoplňuje jako
 * „spočítané".
 *
 * ── Poznat to musí jít na první pohled ──────────────────────────────────────
 * Oba snímky nesou `schema_reference` s `takeover` v názvu a `calculated: false`.
 * Nejsou to snímky ve schématu spočítaného běhu a ani se tak netváří: sdílené
 * jméno by dřív nebo později svedlo někoho k tomu, aby je poslal do cesty, která
 * čeká výsledek výpočtu.
 *
 * ── Doložení plateb ─────────────────────────────────────────────────────────
 * Rozlišují se dvě jistoty a to rozlišení je celé jádro věci:
 *
 *  - `reported` — zdroj sám zaznamenal DATUM výplaty (`payout_date`). Tehdy
 *    víme, že se platilo, a kdy.
 *  - `derived`  — částka je součet složek převzaté mzdy, ne doklad o odeslané
 *    platbě. Datum se u ní nevymýšlí (databáze ho u `derived` ani nepřijme).
 *
 * Odvod se ZÁMĚRNĚ nesčítá do jednoho „odvedeno na finanční úřad": co odešlo
 * na účet úřadu, je záloha na daň snížená o vyplacené bonusy, jenže převzatá
 * data nesou obě složky zvlášť a jejich rozdíl by byl náš dopočet, ne doložení.
 * Řádky proto zůstávají rozepsané a rozdíl si udělá ten, kdo se na ně dívá.
 */
final class PayrollTakeoverRunBuilder
{
    public const INPUT_SCHEMA = 'payroll-run-takeover-input.v1';
    public const RESULT_SCHEMA = 'payroll-run-takeover-result.v1';

    /** Peněžní veličiny převzatého měsíce, které se sčítají do souhrnu. */
    private const MONEY_FIELDS = [
        'gross_minor',
        'net_minor',
        'deductions_minor',
        'net_payable_minor',
        'social_base_minor',
        'health_base_minor',
        'employee_social_minor',
        'employee_health_minor',
        'employer_social_minor',
        'employer_health_minor',
        'advance_tax_minor',
        'withholding_tax_minor',
        'tax_bonus_minor',
    ];

    /**
     * Odvody a srážky za měsíc: druh doložení => veličiny, ze kterých se sčítá.
     *
     * Zdravotní pojištění je jeden řádek za všechny pojišťovny: převzatá data
     * nenesou, ke které pojišťovně osoba patří, a rozpočítat to podle dnešního
     * stavu by znamenalo vyrobit údaj, který zdroj nikdy nevydal.
     */
    private const LEVY_FIELDS = [
        'social_insurance' => ['employee_social_minor', 'employer_social_minor'],
        'health_insurance' => ['employee_health_minor', 'employer_health_minor'],
        'advance_tax' => ['advance_tax_minor'],
        'withholding_tax' => ['withholding_tax_minor'],
        'tax_bonus' => ['tax_bonus_minor'],
        'deduction' => ['deductions_minor'],
    ];

    /**
     * @throws \DomainException když za období nejsou převzatá data
     */
    public function build(PayrollTakeoverYear $year, string $period): PayrollTakeoverRunBuild
    {
        $months = $year->forPeriod($period);
        if ($months === []) {
            throw new \DomainException(
                'Za zvolený měsíc nejsou převzaté mzdy, ze kterých by šlo běh postavit.',
            );
        }

        $periodStart = $period . '-01';
        $people = $this->people($months);
        $totals = $this->totals($months);
        $sources = $this->sources($months);

        $inputSnapshot = [
            'schema_reference' => self::INPUT_SCHEMA,
            'run_kind' => PayrollRunKind::TAKEOVER->value,
            'calculated' => false,
            'period' => $period,
            'payroll_start_period' => $year->payrollStartPeriod,
            'sources' => $sources,
            'months' => array_map(
                static fn (PayrollTakeoverMonth $month): array => $month->toArray(),
                $months,
            ),
        ];
        $resultSnapshot = [
            'schema_reference' => self::RESULT_SCHEMA,
            'run_kind' => PayrollRunKind::TAKEOVER->value,
            'calculated' => false,
            'period' => $period,
            'sources' => $sources,
            'people' => $people,
            'totals' => $totals,
        ];

        return new PayrollTakeoverRunBuild(
            $periodStart,
            $this->paymentDate($months, $period),
            $inputSnapshot,
            hash('sha256', CanonicalJson::encode($inputSnapshot)),
            $resultSnapshot,
            hash('sha256', CanonicalJson::encode($resultSnapshot)),
            $this->paymentEvidence($people, $months),
            $sources,
            count($people),
            count($months),
        );
    }

    /**
     * Osoby měsíce se součty přes jejich pracovní vztahy.
     *
     * Klíčem je `employee_id`, a když osoba v MyÚčtu není, identita z původního
     * systému. Souběžné vztahy jedné osoby se sčítají, protože pojistné i daň
     * jsou ze zákona veličiny osoby — stejně to dělá
     * {@see PayrollTakeoverYear::personMonthTotals()}.
     *
     * @param list<PayrollTakeoverMonth> $months
     * @return list<array<string,mixed>>
     */
    private function people(array $months): array
    {
        $people = [];
        foreach ($months as $month) {
            $key = $month->employeeId !== null
                ? 'e' . $month->employeeId
                : 'x' . $month->externalPersonRef;
            if (!isset($people[$key])) {
                $people[$key] = [
                    'employee_id' => $month->employeeId,
                    'external_person_ref' => $month->externalPersonRef,
                    'relationship_count' => 0,
                    'payout_dates' => [],
                    'totals' => array_fill_keys(self::MONEY_FIELDS, 0),
                ];
            }
            $people[$key]['relationship_count']++;
            $row = $month->toArray();
            foreach (self::MONEY_FIELDS as $field) {
                $people[$key]['totals'][$field] += (int) $row[$field];
            }
            $people[$key]['payout_dates'][] = $month->payoutDate;
        }
        ksort($people, SORT_STRING);

        return array_values(array_map(
            static function (array $person): array {
                $dates = array_values(array_unique(array_filter(
                    $person['payout_dates'],
                    static fn (?string $date): bool => $date !== null,
                )));
                sort($dates, SORT_STRING);
                // Jedno jednoznačné datum, nebo žádné. Dva různé dny výplaty
                // u jedné osoby nejsou chyba (souběžné vztahy), ale doložit se
                // jimi jedna platba nedá.
                $person['payout_date'] = count($dates) === 1
                    && count($dates) === count($person['payout_dates'])
                        ? $dates[0]
                        : null;
                unset($person['payout_dates']);

                return $person;
            },
            $people,
        ));
    }

    /**
     * @param list<PayrollTakeoverMonth> $months
     * @return array<string,int>
     */
    private function totals(array $months): array
    {
        $totals = array_fill_keys(self::MONEY_FIELDS, 0);
        foreach ($months as $month) {
            $row = $month->toArray();
            foreach (self::MONEY_FIELDS as $field) {
                $totals[$field] += (int) $row[$field];
            }
        }

        return $totals;
    }

    /**
     * @param list<PayrollTakeoverMonth> $months
     * @return list<string>
     */
    private function sources(array $months): array
    {
        $sources = [];
        foreach ($months as $month) {
            $sources[$month->source] = true;
        }
        ksort($sources, SORT_STRING);

        return array_keys($sources);
    }

    /**
     * Datum výplaty běhu: nejpozdější doložený den, jinak poslední den měsíce.
     *
     * `payment_date` je u běhu povinné a náhradní hodnota je tu jen proto, aby
     * mělo co nést. Do doložení plateb se NEPROMÍTÁ — tam zůstane datum prázdné,
     * protože poslední den měsíce není den, kdy se platilo.
     *
     * @param list<PayrollTakeoverMonth> $months
     */
    private function paymentDate(array $months, string $period): string
    {
        $latest = null;
        foreach ($months as $month) {
            if ($month->payoutDate !== null
                && ($latest === null || $month->payoutDate > $latest)
            ) {
                $latest = $month->payoutDate;
            }
        }

        return $latest ?? (new \DateTimeImmutable($period . '-01'))
            ->modify('last day of this month')->format('Y-m-d');
    }

    /**
     * @param list<array<string,mixed>> $people
     * @param list<PayrollTakeoverMonth> $months
     * @return list<array{
     *   evidence_kind:string,
     *   certainty:string,
     *   employee_id:?int,
     *   external_person_ref:string,
     *   amount_minor:int,
     *   paid_on:?string
     * }>
     */
    private function paymentEvidence(array $people, array $months): array
    {
        $evidence = [];
        foreach ($people as $person) {
            /** @var array<string,int> $totals */
            $totals = $person['totals'];
            $amount = $totals['net_payable_minor'];
            if ($amount === 0) {
                continue;
            }
            $paidOn = is_string($person['payout_date'] ?? null)
                ? $person['payout_date']
                : null;
            $evidence[] = [
                'evidence_kind' => 'net_wage',
                // Datum výplaty je jediné, co ze zdroje dělá doklad o platbě.
                'certainty' => $paidOn === null ? 'derived' : 'reported',
                'employee_id' => $person['employee_id'],
                'external_person_ref' => (string) $person['external_person_ref'],
                'amount_minor' => $amount,
                'paid_on' => $paidOn,
            ];
        }

        $totals = $this->totals($months);
        foreach (self::LEVY_FIELDS as $kind => $fields) {
            $amount = 0;
            foreach ($fields as $field) {
                $amount += $totals[$field];
            }
            if ($amount === 0) {
                continue;
            }
            $evidence[] = [
                'evidence_kind' => $kind,
                // Odvod nemá v převzatých datech ani datum, ani příjemce —
                // je to složka mzdy, ne doklad o odeslané platbě.
                'certainty' => 'derived',
                'employee_id' => null,
                'external_person_ref' => '',
                'amount_minor' => $amount,
                'paid_on' => null,
            ];
        }

        return $evidence;
    }
}
