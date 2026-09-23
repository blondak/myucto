<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Souhrn rekonciliace převodu po kritériích K1–K4, jak je čte účetní u dávky firem:
 *
 *  - K1 obratová předvaha: každý účet (PS, obrat, KS) proti deníku zdroje a proti
 *    předvaze vyexportované ze zdroje, když ji převod dostal,
 *  - K2 vyrovnanost: obraty MD = D, předvaha = deník, počáteční stavy vyrovnané,
 *    žádný koncept,
 *  - K3 úplnost mapování: rozvaha vychází a žádný účet ve výkazech nechybí,
 *  - K4 doklady proti deníku: součty dokladů po knihách proti zápisům.
 *
 * Kritérium bez jediné kontroly (např. převod bez dokladů) je `null`, ne „sedí".
 * Vstupem jsou roky rekonciliace z protokolu převodu (klíč `reconciliation`), které
 * staví {@see TrialBalanceReconciliation}; zdroj se pozná podle klíče kontroly
 * `<zdroj>_journal`.
 */
final class ReconciliationCriteria
{
    public const K1 = 'K1';
    public const K2 = 'K2';
    public const K3 = 'K3';
    public const K4 = 'K4';

    private const K2_CHECKS = ['turnover_balanced', 'matches_journal', 'opening_balanced', 'no_drafts'];

    /**
     * Kritéria jednoho roku.
     *
     * @param array{checks?:list<array{key:string,ok:bool}>} $year rok rekonciliace
     * @return array{K1:?bool,K2:?bool,K3:?bool,K4:?bool}
     */
    public static function forYear(array $year): array
    {
        $out = [self::K1 => null, self::K2 => null, self::K3 => null, self::K4 => null];
        foreach ((array) ($year['checks'] ?? []) as $check) {
            $key = (string) ($check['key'] ?? '');
            $criterion = match (true) {
                str_ends_with($key, '_journal'), str_ends_with($key, '_report') => self::K1,
                in_array($key, self::K2_CHECKS, true) => self::K2,
                $key === 'balance_sheet_balanced' => self::K3,
                str_starts_with($key, 'documents_') => self::K4,
                default => null,
            };
            if ($criterion !== null) {
                $out[$criterion] = ($out[$criterion] ?? true) && (bool) ($check['ok'] ?? false);
            }
        }
        return $out;
    }

    /**
     * Kritéria po letech a souhrn přes všechny roky (sedí jen tehdy, když sedí v každém roce).
     *
     * @param list<array<string,mixed>> $years rekonciliace z protokolu převodu
     * @return array{years:array<int,array{K1:?bool,K2:?bool,K3:?bool,K4:?bool}>,total:array{K1:?bool,K2:?bool,K3:?bool,K4:?bool}}
     */
    public static function summarize(array $years): array
    {
        $total = [self::K1 => null, self::K2 => null, self::K3 => null, self::K4 => null];
        $byYear = [];
        foreach ($years as $year) {
            $criteria = self::forYear($year);
            $byYear[(int) ($year['year'] ?? 0)] = $criteria;
            foreach ($criteria as $k => $ok) {
                if ($ok !== null) {
                    $total[$k] = ($total[$k] ?? true) && $ok;
                }
            }
        }
        ksort($byYear);
        return ['years' => $byYear, 'total' => $total];
    }
}
