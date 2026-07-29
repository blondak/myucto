<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\LedgerReportRepository;

/**
 * Obratová předvaha (Epic F2): PS (R6) / obraty / KS per účet + kontrolní rovnice
 * (Σ obrat MD = Σ obrat D = obrat deníku; bilanční kontinuita PS). Zahrnuje
 * VŠECHNY účty vč. 7xx (R2), čte jen zaúčtované zápisy (R1), analytiky rolované
 * na syntetiku (R15). Kontroly rovnosti v HALÉŘÍCH.
 */
final class TrialBalanceService
{
    public function __construct(
        private readonly LedgerReportRepository $ledger,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    /**
     * @return array<string,mixed> struktura dle spec §2.5
     */
    public function build(int $supplierId, int $periodId, ?string $from, ?string $to, bool $analytics = false, bool $afterClosing = false): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $from = ($from === null || $from === '') ? (string) $period['starts_on'] : $from;
        $to   = ($to === null || $to === '') ? (string) $period['ends_on'] : $to;

        $raw = $this->ledger->trialBalanceRows($supplierId, $from, $to, (string) $period['starts_on'], $analytics, [], !$afterClosing);

        $rows = [];
        $totals = ['ps_md' => 0, 'ps_d' => 0, 'turnover_md' => 0, 'turnover_d' => 0, 'ks_md' => 0, 'ks_d' => 0];
        foreach ($raw as $r) {
            $psMd = self::cents($r['ps_md']);
            $psD  = self::cents($r['ps_d']);
            $tMd  = self::cents($r['to_md']);
            $tD   = self::cents($r['to_d']);
            if ($psMd === 0 && $psD === 0 && $tMd === 0 && $tD === 0) {
                continue;
            }
            $delta = $psMd - $psD + $tMd - $tD;
            $ksMd = $delta > 0 ? $delta : 0;
            $ksD  = $delta > 0 ? 0 : -$delta;

            $rows[] = [
                'account_id'   => (int) $r['id'],
                'account_code' => (string) $r['account_code'],
                'name'         => (string) $r['name'],
                'account_type' => (string) $r['account_type'],
                'ps_md'        => $psMd / 100,
                'ps_d'         => $psD / 100,
                'turnover_md'  => $tMd / 100,
                'turnover_d'   => $tD / 100,
                'ks_md'        => $ksMd / 100,
                'ks_d'         => $ksD / 100,
            ];

            $totals['ps_md']       += $psMd;
            $totals['ps_d']        += $psD;
            $totals['turnover_md'] += $tMd;
            $totals['turnover_d']  += $tD;
            $totals['ks_md']       += $ksMd;
            $totals['ks_d']        += $ksD;
        }

        $journal = $this->ledger->journalTotals($supplierId, $from, $to);

        return [
            'period' => [
                'id'          => (int) $period['id'],
                'fiscal_year' => (int) $period['fiscal_year'],
                'starts_on'   => (string) $period['starts_on'],
                'ends_on'     => (string) $period['ends_on'],
            ],
            'from'        => $from,
            'to'          => $to,
            'draft_count' => $this->ledger->draftCount($supplierId, $from, $to),
            'rows'        => $rows,
            'totals'      => array_map(static fn (int $c): float => $c / 100, $totals),
            'checks'      => [
                'turnover_balanced'   => $totals['turnover_md'] === $totals['turnover_d'],
                'journal_turnover_md' => $journal['md'],
                'journal_turnover_d'  => $journal['d'],
                'matches_journal'     => $totals['turnover_md'] === self::cents($journal['md'])
                                      && $totals['turnover_d'] === self::cents($journal['d']),
                'opening_balanced'    => $totals['ps_md'] === $totals['ps_d'],
            ],
        ];
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
