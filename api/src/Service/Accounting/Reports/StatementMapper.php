<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

/**
 * Čistá mapovací logika výkazů (Epic F2) — sdílí ji FinancialStatementService
 * i EntityCategoryService (žádná kruhová závislost, §2.8).
 *
 * Match účtu: syntetický kód na prefix (code LIKE prefix%), při víc zásazích
 * vyhrává NEJDELŠÍ prefix (§1.1 sémantika). Saldové účty přes balance_condition
 * (R8): debit → jen při debetním zůstatku, credit → jen při kreditním.
 *
 * Hodnota do řádku (§2.7 krok 4), balance = md − d (kladné = debetní):
 *   aktiva gross = +balance; aktiva correction = −balance (korekce mají kreditní
 *   zůstatek → vyjde kladná a odečítá se: netto = gross − correction);
 *   pasiva = −balance; VZZ dle typu účtu (výnos = −balance, náklad = +balance);
 *   vše × sign.
 */
final class StatementMapper
{
    /**
     * @param list<array<string,mixed>> $rows     statement_rows verze (kvůli section řádků)
     * @param list<array<string,mixed>> $map      statement_account_map verze
     * @param list<array{account_id:int, code:string, name:string, account_type:string, md:float, d:float}> $balances
     * @return array<string, array{gross: float, correction: float, accounts: list<array{account_id:int, account_code:string, name:string, amount:float, target:string}>}>
     *         klíčované row_code — jen přímo namapované příspěvky (mezisoučty skládá service)
     */
    public function map(array $rows, array $map, array $balances): array
    {
        $sectionByRow = [];
        foreach ($rows as $r) {
            $sectionByRow[(string) $r['row_code']] = (string) $r['section'];
        }

        $result = [];
        foreach ($balances as $b) {
            $code = (string) $b['code'];
            $balance = round($b['md'] - $b['d'], 2);
            $balCents = (int) round($balance * 100);

            $maxLen = 0;
            foreach ($map as $m) {
                $prefix = (string) $m['account_prefix'];
                if ($prefix !== '' && str_starts_with($code, $prefix) && strlen($prefix) > $maxLen) {
                    $maxLen = strlen($prefix);
                }
            }
            if ($maxLen === 0) {
                continue;
            }

            foreach ($map as $m) {
                $prefix = (string) $m['account_prefix'];
                if (strlen($prefix) !== $maxLen || !str_starts_with($code, $prefix)) {
                    continue;
                }
                $condition = (string) $m['balance_condition'];
                if (($condition === 'debit' && $balCents <= 0) || ($condition === 'credit' && $balCents >= 0)) {
                    continue;
                }
                $rowCode = (string) $m['row_code'];
                $section = $sectionByRow[$rowCode] ?? null;
                if ($section === null) {
                    continue;
                }
                $target = (string) $m['target'];
                $value = match (true) {
                    $section === 'assets' && $target === 'correction' => -$balance,
                    $section === 'assets'                             => $balance,
                    $section === 'liabilities'                        => -$balance,
                    default => ((string) $b['account_type']) === 'revenue' ? -$balance : $balance,
                };
                $value = round($value * (int) $m['sign'], 2);
                if ((int) round($value * 100) === 0) {
                    continue;
                }
                if (!isset($result[$rowCode])) {
                    $result[$rowCode] = ['gross' => 0.0, 'correction' => 0.0, 'accounts' => []];
                }
                $result[$rowCode][$target] = round($result[$rowCode][$target] + $value, 2);
                $result[$rowCode]['accounts'][] = [
                    'account_id'   => (int) $b['account_id'],
                    'account_code' => $code,
                    'name'         => (string) $b['name'],
                    'amount'       => $value,
                    'target'       => $target,
                ];
            }
        }
        return $result;
    }

    /**
     * Nenamapované účty s nenulovým zůstatkem. Slouží jako kontrola
     * úplnosti výkazů pro uživatelské syntetiky mimo standardní mapu.
     *
     * @return list<array{account_id:int,account_code:string,name:string,balance:float}>
     */
    public function unmappedBalances(array $map, array $balances, array $accountTypes = []): array
    {
        $out = [];
        foreach ($balances as $b) {
            if ($accountTypes !== [] && !in_array((string) $b['account_type'], $accountTypes, true)) {
                continue;
            }
            $code = (string) $b['code'];
            $mapped = false;
            foreach ($map as $m) {
                $prefix = (string) $m['account_prefix'];
                if ($prefix !== '' && str_starts_with($code, $prefix)) {
                    $mapped = true;
                    break;
                }
            }
            $balance = round((float) $b['md'] - (float) $b['d'], 2);
            if (!$mapped && (int) round($balance * 100) !== 0) {
                $out[] = [
                    'account_id' => (int) $b['account_id'],
                    'account_code' => $code,
                    'name' => (string) $b['name'],
                    'balance' => $balance,
                ];
            }
        }
        return $out;
    }

    /**
     * Σ aktiv NETTO (gross − correction) přes všechny přímo namapované příspěvky
     * sekce assets — každý účet přispívá právě jednou, mezisoučty netřeba skládat.
     * Používá EntityCategoryService (kritérium aktiva netto, §2.8).
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string, array{gross: float, correction: float, accounts: list<array<string,mixed>>}> $mapped výstup map()
     */
    public function assetsNet(array $rows, array $mapped): float
    {
        $net = 0.0;
        foreach ($rows as $r) {
            if ((string) $r['section'] !== 'assets') {
                continue;
            }
            $v = $mapped[(string) $r['row_code']] ?? null;
            if ($v === null) {
                continue;
            }
            $net += $v['gross'] - $v['correction'];
        }
        return round($net, 2);
    }

    /**
     * D2 (audit 2026-07, H9) — syntetické prefixy se saldovou podmínkou pro no-compensation
     * split v LedgerReportRepository::syntheticBalances (§58 zákaz kompenzace, per-analytika
     * saldo místo znettování na syntetiku). Sdílené mezi FinancialStatementService a
     * EntityCategoryService (§2.8, žádná kruhová závislost).
     *
     * N1 guard (audit 2026-07 review): každý prefix s `balance_condition='debit'` MUSÍ mít
     * v mapě protistranu se stejným `account_prefix` a `balance_condition='credit'` (a naopak).
     * Bez páru by split repository vyrobil stranu, kterou map() nikdy nenamapuje na žádný
     * řádek výkazu (chybí odpovídající mapový záznam pro tu stranu) — rozvaha by se tiše
     * rozvážila (checks.balanced=false) bez jasné příčiny. Fail-loud při detekci místo
     * tichého selhání v produkci.
     *
     * @param list<array<string,mixed>> $map
     * @return list<string>
     */
    public function noCompensationPrefixes(array $map): array
    {
        $debit = [];
        $credit = [];
        foreach ($map as $m) {
            $condition = (string) $m['balance_condition'];
            $prefix = (string) $m['account_prefix'];
            if ($condition === 'debit') {
                $debit[$prefix] = true;
            } elseif ($condition === 'credit') {
                $credit[$prefix] = true;
            }
        }
        // array_keys() koerceuje číselně vypadající klíče ('221', '341'...) na int
        // (PHP číselné klíče pole) — přetypuj zpět na string (návratový typ list<string>,
        // konzistence s LedgerReportRepository::matchesSplitCode, stejná past).
        $unpaired = array_map('strval', array_keys(array_diff_key($debit, $credit) + array_diff_key($credit, $debit)));
        if ($unpaired !== []) {
            throw new ReportException(
                'unpaired_balance_condition_prefix',
                'Mapa výkazu obsahuje nepárový saldový prefix (D2, §58 zákaz kompenzace): '
                    . implode(', ', $unpaired)
                    . ' — prefix s balance_condition debit/credit musí mít protistranu se'
                    . ' stejným account_prefix na opačné straně.',
            );
        }
        return array_map('strval', array_keys($debit + $credit));
    }

    /** @return list<string> */
    public function analyticPrefixes(array $map): array
    {
        $prefixes = [];
        foreach ($map as $m) {
            $prefix = (string) $m['account_prefix'];
            if (strlen($prefix) > 3) {
                $prefixes[$prefix] = true;
            }
        }
        return array_map('strval', array_keys($prefixes));
    }
}
