<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

/**
 * Co nesedící rekonciliace roku znamená pro protokol převodu (Money S3, POHODA, PREMIER):
 *
 *  - K2 (obraty MD = D, předvaha = deník, vyrovnané počáteční stavy, žádné koncepty) a vlastní
 *    kontroly zdroje mimo K1–K4 (PREMIER: bankovní pohyby bez zápisu) jsou chyba - účetnictví
 *    by bylo vnitřně rozbité;
 *  - K1 s rozdíly všech účtů do {@see ReconciliationTolerance::ROUNDING_DIFFERENCE} včetně je
 *    haléřové zaokrouhlení: upozornění `rounding_difference` a kontrola se počítá jako splněná;
 *  - K1 nad tuto mez, K3 a K4 jsou rozdíl k přijetí ({@see ImportProtocol::difference()}).
 */
final class ReconciliationVerdict
{
    /**
     * Zapíše výsledek roku do protokolu a vrátí rok rekonciliace; kontroly K1 v mezích
     * zaokrouhlení v něm označí jako splněné (`rounding: true`) a přepočte `ok`.
     *
     * @param array<string,mixed> $year rok rekonciliace (`year`, `checks`, `journal_diffs`,
     *        volitelně `money_report`, `documents`, `unmapped_accounts`)
     * @return array<string,mixed>
     */
    public static function apply(ImportProtocol $p, string $step, array $year): array
    {
        $label = (int) ($year['year'] ?? 0);
        $failed = [];
        foreach ((array) ($year['checks'] ?? []) as $i => $check) {
            if (!($check['ok'] ?? false)) {
                $failed[$i] = (string) ($check['key'] ?? '');
            }
        }
        if ($failed === []) {
            return $year;
        }
        $hard = array_filter($failed, static fn (string $key): bool
            => !in_array(ReconciliationCriteria::criterion($key), [ReconciliationCriteria::K1, ReconciliationCriteria::K3, ReconciliationCriteria::K4], true));
        if ($hard !== []) {
            $p->error($step, 'reconciliation_failed', "Rok {$label}: převod nesedí, podrobnosti v rekonciliaci.", ['year' => $label]);
            return $year;
        }

        $k1 = array_filter($failed, static fn (string $key): bool => ReconciliationCriteria::criterion($key) === ReconciliationCriteria::K1);
        $accounts = [];
        $rounding = $k1 !== [];
        foreach ($k1 as $key) {
            $diffs = self::k1Diffs($year, $key);
            $rounding = $rounding && $diffs !== [];
            foreach ($diffs as $d) {
                $accounts[] = $d;
                $rounding = $rounding && ReconciliationTolerance::isRounding($d['difference']);
            }
        }
        if ($rounding) {
            foreach (array_keys($k1) as $i) {
                $year['checks'][$i]['ok'] = true;
                $year['checks'][$i]['rounding'] = true;
            }
            $year['ok'] = TrialBalanceReconciliation::allOk($year['checks']);
            $p->warn($step, 'rounding_difference', sprintf(
                'Rok %d: obratová předvaha se od zdroje liší jen o zaokrouhlení (nejvýše %s Kč na účtu): %s.',
                $label,
                self::money(ReconciliationTolerance::ROUNDING_DIFFERENCE),
                implode('; ', array_map(static fn (array $a): string => $a['account'] . ' ' . self::money($a['difference']) . ' Kč', self::unique($accounts))),
            ), ['year' => $label, 'accounts' => self::unique($accounts)]);
            $failed = array_diff_key($failed, $k1);
            $accounts = [];
            if ($failed === []) {
                return $year;
            }
        }

        $criteria = array_values(array_unique(array_map(static fn (string $key): string => (string) ReconciliationCriteria::criterion($key), $failed)));
        sort($criteria);
        $documents = [];
        foreach ((array) ($year['documents'] ?? []) as $d) {
            if (!($d['ok'] ?? true)) {
                $documents[] = [
                    'check' => (string) $d['key'],
                    'documents' => round((float) $d['documents'], 2),
                    'journal' => round((float) $d['journal'], 2),
                    'difference' => round((float) $d['documents'] - (float) $d['journal'], 2),
                ];
            }
        }
        $context = ['year' => $label, 'criteria' => $criteria];
        if ($accounts !== []) {
            $context['accounts'] = array_slice(self::unique($accounts), 0, ImportProtocol::LIST_LIMIT);
        }
        if ($documents !== []) {
            $context['documents'] = $documents;
        }
        if (in_array(ReconciliationCriteria::K3, $criteria, true) && ($year['unmapped_accounts'] ?? []) !== []) {
            $context['unmapped_accounts'] = array_slice((array) $year['unmapped_accounts'], 0, ImportProtocol::LIST_LIMIT);
        }
        $p->difference($step, 'reconciliation_failed', "Rok {$label}: převod nesedí (" . implode(', ', $criteria) . '), podrobnosti v rekonciliaci.', $context);
        return $year;
    }

    /**
     * Rozdíly účtů kontroly K1: u každého účtu největší z rozdílů PS, obratu a KS (MyÚčto − zdroj).
     *
     * @param array<string,mixed> $year
     * @return list<array{account:string,difference:float}>
     */
    private static function k1Diffs(array $year, string $key): array
    {
        if ($key === 'money_report') {
            $report = (array) ($year['money_report'] ?? []);
            if ((int) ($report['accounts'] ?? 0) === 0) {
                return [];
            }
            $diffs = (array) ($report['diffs'] ?? []);
        } elseif (str_ends_with($key, '_journal')) {
            $diffs = (array) ($year['journal_diffs'] ?? []);
        } else {
            return [];
        }
        $out = [];
        foreach ($diffs as $d) {
            $largest = 0.0;
            foreach ([0, 1, 2] as $i) {
                $diff = round((float) ($d['myucto'][$i] ?? 0) - (float) ($d['money'][$i] ?? 0), 2);
                if (abs($diff) > abs($largest)) {
                    $largest = $diff;
                }
            }
            $out[] = ['account' => (string) $d['account'], 'difference' => $largest];
        }
        return $out;
    }

    /**
     * @param list<array{account:string,difference:float}> $accounts
     * @return list<array{account:string,difference:float}>
     */
    private static function unique(array $accounts): array
    {
        $out = [];
        foreach ($accounts as $a) {
            if (!isset($out[$a['account']]) || abs($a['difference']) > abs($out[$a['account']]['difference'])) {
                $out[$a['account']] = $a;
            }
        }
        return array_values($out);
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }
}
