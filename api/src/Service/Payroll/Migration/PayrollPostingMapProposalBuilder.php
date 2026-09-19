<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Service\Payroll\PayrollAccountingDefaults;

/**
 * Z převzatého zaúčtování odvodí NÁVRH mzdových předkontací firmy.
 *
 * ⚠ **Nic to neúčtuje a účtovat nesmí.** Mzdy se do účetnictví dostanou až
 * vlastním zaúčtováním MyÚčta ({@see \MyInvoice\Service\Payroll\Posting\PayrollPostingLineBuilder}).
 * Převzaté zápisy původního programu by proti převedeným dokladům vyrobily
 * duplicitu, takže se z nich bere JEN podklad pro nastavení: který účet stál
 * u kterého mzdového významu.
 *
 * ── Jak se význam odvozuje ──────────────────────────────────────────────────
 * Zdrojová čtečka přeloží svůj řádek na význam
 * ({@see PayrollLegacyPostingConcepts}); ten ukazuje na předkontaci strany MD
 * a strany D. Za každou předkontaci se pak sečtou VŠECHNY účty, které se u ní
 * v převzatých datech objevily, i s počtem řádků a objemem peněz.
 *
 * ── Rozpor se nezakrývá ─────────────────────────────────────────────────────
 * Vyjdou-li na jeden význam dva účty, návrh NEVYBERE většinový. Většina je tady
 * ten nejhorší možný rozhodčí: firma s jednou pojišťovnou na 336.002 a druhou
 * na 336.003 má obě správně a „vyhraje" ta s víc lidmi. Stav je `conflict`,
 * doporučení žádné a rozhodne účetní.
 *
 * ── Účet mimo osnovu ────────────────────────────────────────────────────────
 * Jednoznačný účet, který v osnově firmy není, se NENABÍDNE jako hotová volba
 * (`outside_chart`). Uložit by ho stejně nešlo - `PayrollEmployerSettingsValidator`
 * neznámý účet odmítne - a nabídnout ho jako „návrh k potvrzení" by znamenalo
 * poslat účetní do slepé uličky.
 */
final class PayrollPostingMapProposalBuilder
{
    /** Právě jeden účet, existuje v osnově; jediný stav s doporučením. */
    public const STATUS_UNAMBIGUOUS = 'unambiguous';
    /** Víc účtů na jeden význam. Rozhoduje účetní, návrh nevybírá. */
    public const STATUS_CONFLICT = 'conflict';
    /** Jediný účet, ale v osnově firmy chybí. */
    public const STATUS_OUTSIDE_CHART = 'outside_chart';
    /** Z převzatých dat se nedá odvodit nic; zůstává výchozí hodnota. */
    public const STATUS_MISSING = 'missing';
    /** Výčet stavů; párováno s klientským unionem v `PayrollEnumContractTest`. */
    public const STATUSES = [
        self::STATUS_UNAMBIGUOUS,
        self::STATUS_CONFLICT,
        self::STATUS_OUTSIDE_CHART,
        self::STATUS_MISSING,
    ];

    /**
     * @param list<PayrollLegacyPostingRow> $rows převzaté zaúčtování
     * @param list<string> $chartCodes aktivní účty osnovy firmy
     * @param array<string,string> $currentAccounts předkontace, které firma má teď
     * @return array{
     *   source:string,
     *   keys:list<array<string,mixed>>,
     *   unmapped:list<array<string,mixed>>,
     *   summary:array{row_count:int,line_count:int,unambiguous:int,conflict:int,outside_chart:int,missing:int}
     * }
     */
    public static function build(
        string $source,
        array $rows,
        array $chartCodes,
        array $currentAccounts,
    ): array {
        $chart = [];
        foreach ($chartCodes as $code) {
            $chart[strtoupper(trim($code))] = true;
        }

        /** @var array<string,array<string,array<string,mixed>>> $tally předkontace => účet => doklad */
        $tally = [];
        $unmapped = [];
        $lineCount = 0;

        foreach ($rows as $row) {
            $lineCount += $row->lineCount;
            if ($row->concept === null) {
                $unmapped[] = self::unmappedRow($row);
                continue;
            }
            foreach (['debit', 'credit'] as $side) {
                $key = $side === 'debit'
                    ? PayrollLegacyPostingConcepts::debitKey($row->concept)
                    : PayrollLegacyPostingConcepts::creditKey($row->concept);
                $account = $row->account($side);
                if ($key === null || $account === null) {
                    continue;
                }
                self::addEvidence($tally, $key, $account, $side, $row);
            }
        }

        $keys = [];
        $summary = [
            'row_count' => count($rows),
            'line_count' => $lineCount,
            self::STATUS_UNAMBIGUOUS => 0,
            self::STATUS_CONFLICT => 0,
            self::STATUS_OUTSIDE_CHART => 0,
            self::STATUS_MISSING => 0,
        ];

        foreach (PayrollLegacyPostingConcepts::settingsKeys() as $key) {
            $candidates = array_values($tally[$key] ?? []);
            foreach ($candidates as $index => $candidate) {
                $candidates[$index] = self::finishCandidate($candidate, $chart);
            }
            usort($candidates, static fn (array $a, array $b): int =>
                [$b['line_count'], $b['amount_minor'], $a['code']]
                <=> [$a['line_count'], $a['amount_minor'], $b['code']]);

            $status = self::status($candidates);
            $summary[$status]++;
            $default = (string) PayrollAccountingDefaults::defaultCode($key);
            $current = trim((string) ($currentAccounts[$key] ?? ''));

            $keys[] = [
                'key' => $key,
                'status' => $status,
                'derivable' => PayrollLegacyPostingConcepts::isDerivable($key),
                'default_code' => $default,
                'current_code' => $current === '' ? $default : $current,
                'suggested_code' => $status === self::STATUS_UNAMBIGUOUS ? $candidates[0]['code'] : null,
                'candidates' => $candidates,
            ];
        }

        return [
            'source' => $source,
            'keys' => $keys,
            'unmapped' => $unmapped,
            'summary' => $summary,
        ];
    }

    /**
     * Sada předkontací po POTVRZENÍ účetní.
     *
     * Bere se výhradně `$confirmations` - doporučení, které nikdo nepotvrdil, se
     * neprojeví. Prázdné potvrzení proto vrací dosavadní nastavení beze změny;
     * to je celá záruka „nic se neuloží bez potvrzení" a je na ní test.
     *
     * @param array<string,string> $currentAccounts
     * @param array<string,string> $confirmations předkontace => účet, který účetní vybrala
     * @return array<string,string>
     */
    public static function confirmedAccounts(array $currentAccounts, array $confirmations): array
    {
        $allowed = array_flip(PayrollLegacyPostingConcepts::settingsKeys());
        $accounts = $currentAccounts;
        foreach ($confirmations as $key => $code) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new \InvalidArgumentException("Neznámá mzdová předkontace: {$key}.");
            }
            $value = strtoupper(trim((string) $code));
            if ($value === '') {
                throw new \InvalidArgumentException("Potvrzená předkontace {$key} nesmí být prázdná.");
            }
            $accounts[$key] = $value;
        }

        return $accounts;
    }

    /**
     * @param array<string,array<string,array<string,mixed>>> $tally
     */
    private static function addEvidence(
        array &$tally,
        string $key,
        string $account,
        string $side,
        PayrollLegacyPostingRow $row,
    ): void {
        $bucket = $tally[$key][$account] ?? [
            'code' => $account,
            'source_codes' => [],
            'line_count' => 0,
            'amount_minor' => 0,
            'concepts' => [],
            'labels' => [],
            'cost_centers' => [],
        ];
        $bucket['line_count'] += $row->lineCount;
        $bucket['amount_minor'] += $row->amountMinor;
        $bucket['concepts'][(string) $row->concept] = true;
        $bucket['labels'][$row->label] = true;
        $sourceCode = $row->sourceCode($side);
        if ($sourceCode !== null && $sourceCode !== '') {
            $bucket['source_codes'][$sourceCode] = true;
        }
        foreach ($row->costCenters as $costCenter) {
            $bucket['cost_centers'][$costCenter] = true;
        }
        $tally[$key][$account] = $bucket;
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,true> $chart
     * @return array<string,mixed>
     */
    private static function finishCandidate(array $candidate, array $chart): array
    {
        /** @var string $code */
        $code = $candidate['code'];
        $synthetic = PayrollLegacyAccountCode::synthetic($code);

        return [
            'code' => $code,
            'source_codes' => self::sortedKeys($candidate['source_codes']),
            'line_count' => $candidate['line_count'],
            'amount_minor' => $candidate['amount_minor'],
            'concepts' => self::sortedKeys($candidate['concepts']),
            'labels' => self::sortedKeys($candidate['labels']),
            'cost_centers' => self::sortedKeys($candidate['cost_centers']),
            'in_chart' => isset($chart[$code]),
            // Syntetika se nabízí jen jako INFORMACE pro účet mimo osnovu:
            // „336.001 nemáte, 336 ano". Návrhem se nestává ani tehdy -
            // sloučit sociální a zdravotní na jednu 336 je věcné rozhodnutí.
            'synthetic_code' => $synthetic !== null && $synthetic !== $code ? $synthetic : null,
            'synthetic_in_chart' => $synthetic !== null && $synthetic !== $code && isset($chart[$synthetic]),
        ];
    }

    /** @param list<array<string,mixed>> $candidates */
    private static function status(array $candidates): string
    {
        if ($candidates === []) {
            return self::STATUS_MISSING;
        }
        if (count($candidates) > 1) {
            return self::STATUS_CONFLICT;
        }

        return $candidates[0]['in_chart'] === true
            ? self::STATUS_UNAMBIGUOUS
            : self::STATUS_OUTSIDE_CHART;
    }

    /** @return array<string,mixed> */
    private static function unmappedRow(PayrollLegacyPostingRow $row): array
    {
        return [
            'label' => $row->label,
            'reference' => $row->sourceReference,
            'debit_code' => $row->debitAccount,
            'credit_code' => $row->creditAccount,
            'debit_source_code' => $row->debitSourceCode,
            'credit_source_code' => $row->creditSourceCode,
            'line_count' => $row->lineCount,
            'amount_minor' => $row->amountMinor,
        ];
    }

    /**
     * @param array<string,true> $set
     * @return list<string>
     */
    private static function sortedKeys(array $set): array
    {
        // Číselný klíč pole je v PHP int; bez přetypování by se `521000`
        // v odpovědi objevilo jako číslo a klient by ho porovnával s řetězcem.
        $values = array_map(static fn (int|string $value): string => (string) $value, array_keys($set));
        sort($values, SORT_STRING);

        return array_values($values);
    }
}
