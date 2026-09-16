<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Reconciler;

/**
 * Rekonciliace převodu - důkaz, že MyÚčto po převodu ukazuje totéž co Pohoda:
 *   1. obratová předvaha MyÚčta ({@see TrialBalanceService}) proti předvaze spočtené
 *      přímo z deníku Pohody - po syntetických účtech, PS / obrat / KS, na haléř;
 *   2. vnitřní kontroly předvahy (obraty MD = D, předvaha = deník, vyrovnané PS);
 *   3. doklady proti deníku: přijaté faktury × 321, vydané × 311, pokladna × 211,
 *      banka × 221 - jen převedené doklady a zápisy, na které jsou navázané;
 *   4. vyrovnaná rozvaha bez účtů, které mapa výkazů nezná.
 */
final class PohodaReconciler
{
    public const STEP = 'reconciliation';

    public function __construct(
        private readonly Connection $db,
        private readonly TrialBalanceService $trialBalance,
        private readonly FinancialStatementService $statements,
    ) {}

    public function run(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($ctx->period === null) {
            $p->finish(self::STEP);
            return;
        }
        $year = $ctx->year();
        $periodId = $ctx->period['id'];
        $pohoda = PohodaJournal::trialBalance($ctx->export);

        $tb = $this->trialBalance->build($ctx->supplierId, $periodId, null, null, false, false);
        $mine = [];
        foreach ($tb['rows'] as $row) {
            $syn = substr((string) $row['account_code'], 0, 3);
            $mine[$syn] ??= [0.0, 0.0, 0.0];
            $mine[$syn][0] += (float) $row['ps_md'] - (float) $row['ps_d'];
            $mine[$syn][1] += (float) $row['turnover_md'] - (float) $row['turnover_d'];
            $mine[$syn][2] += (float) $row['ks_md'] - (float) $row['ks_d'];
        }
        foreach ($mine as $syn => $v) {
            $mine[$syn] = [round($v[0], 2), round($v[1], 2), round($v[2], 2)];
        }
        $journalDiffs = MoneyS3Reconciler::compare($mine, $pohoda);
        $checks = [
            ['key' => 'turnover_balanced', 'ok' => (bool) $tb['checks']['turnover_balanced']],
            ['key' => 'matches_journal', 'ok' => (bool) $tb['checks']['matches_journal']],
            ['key' => 'opening_balanced', 'ok' => (bool) $tb['checks']['opening_balanced']],
            ['key' => 'no_drafts', 'ok' => (int) $tb['draft_count'] === 0],
            ['key' => 'pohoda_journal', 'ok' => $journalDiffs === [], 'accounts' => count($pohoda)],
        ];
        $documents = $this->documentsAgainstJournal($ctx->supplierId, $periodId);
        foreach ($documents as $d) {
            $checks[] = ['key' => 'documents_' . $d['key'], 'ok' => $d['ok']];
        }
        $balanceSheet = $this->statements->balanceSheet($ctx->supplierId, $periodId, null, 'full');
        $unmapped = array_map(
            static fn (array $u): array => ['account' => (string) $u['account_code'], 'name' => (string) $u['name'], 'balance' => round((float) $u['balance'], 2)],
            (array) ($balanceSheet['checks']['unmapped_accounts'] ?? [])
        );
        $checks[] = ['key' => 'balance_sheet_balanced', 'ok' => (bool) ($balanceSheet['checks']['balanced'] ?? false) && $unmapped === []];

        $ok = true;
        foreach ($checks as $c) {
            $ok = $ok && $c['ok'];
        }
        $p->set('reconciliation', [[
            'year' => $year,
            'period_id' => $periodId,
            'ok' => $ok,
            'checks' => $checks,
            'totals' => $tb['totals'],
            'journal_diffs' => $journalDiffs,
            'documents' => $documents,
            'unmapped_accounts' => $unmapped,
        ]]);
        if (!$ok) {
            $p->error(self::STEP, 'reconciliation_failed', "Rok {$year}: převod nesedí, podrobnosti v rekonciliaci.", ['year' => $year]);
        }
        $p->finish(self::STEP);
    }

    /**
     * Doklady proti zápisům, na které jsou navázané. Porovnávají se jen zápisy, které mají
     * účet dokladu na JEDNÉ straně (vznik závazku nebo pohledávky); doklad účtovaný jinak
     * (zápočet, úhrada v témže zápisu) se počítá zvlášť (`other_accounts`).
     *
     * @return list<array{key:string,documents:float,journal:float,ok:bool,other_accounts:int}>
     */
    private function documentsAgainstJournal(int $supplierId, int $periodId): array
    {
        $pdo = $this->db->pdo();
        $scalar = static function (string $sql, array $params) use ($pdo): float {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return round((float) $stmt->fetchColumn(), 2);
        };
        $oneSided = static fn (string $entry, string $prefix): string =>
            "(SELECT COUNT(DISTINCT l2.side) FROM journal_entry_lines l2
                JOIN chart_of_accounts a2 ON a2.id = l2.account_id AND a2.supplier_id = l2.supplier_id
               WHERE l2.supplier_id = l.supplier_id AND l2.entry_id = {$entry} AND a2.account_code LIKE '{$prefix}%') = 1";
        $ledger = static fn (string $kind, string $docType, string $prefix, string $sign): string =>
            "SELECT COALESCE(SUM({$sign}), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.period_id = ? AND a.account_code LIKE '{$prefix}%' AND e.source_type <> 'opening'
                AND " . $oneSided('e.id', $prefix) . "
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN pohoda_import_map m ON m.supplier_id = k.supplier_id AND m.target_id = k.doc_id AND m.kind = '{$kind}'
                            WHERE k.supplier_id = l.supplier_id AND k.entry_id = e.id AND k.doc_type = '{$docType}')";
        $docs = static fn (string $table, string $expr, string $kind, string $docType, string $prefix, bool $onAccount): string =>
            'SELECT ' . ($onAccount ? "COALESCE(SUM({$expr}), 0)" : 'COUNT(*)') . "
               FROM {$table} d
              WHERE d.supplier_id = ?
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN journal_entries e ON e.id = k.entry_id AND e.supplier_id = k.supplier_id
                             JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                            WHERE k.supplier_id = d.supplier_id AND k.doc_id = d.id AND e.period_id = ? AND k.doc_type = '{$docType}'
                              AND e.source_type <> 'opening'
                              AND " . ($onAccount ? '' : 'NOT ') . $oneSided('k.entry_id', $prefix) . ")
                AND EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = '{$kind}' AND m.target_id = d.id)";

        $spec = [
            ['purchase_invoices', 'purchase_invoices', 'd.total_with_vat', 'purchase_invoice', 'purchase_invoice', '321', "CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END"],
            ['issued_invoices', 'invoices', 'd.total_with_vat', 'invoice', 'invoice', '311', "CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END"],
            ['cash', 'cash_documents', "CASE WHEN d.doc_type = 'in' THEN d.total_amount ELSE -d.total_amount END", 'cash_document', 'cash', '211', "CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END"],
        ];
        $out = [];
        foreach ($spec as [$key, $table, $expr, $kind, $docType, $prefix, $sign]) {
            $documents = $scalar($docs($table, $expr, $kind, $docType, $prefix, true), [$supplierId, $periodId]);
            $journal = $scalar($ledger($kind, $docType, $prefix, $sign), [$supplierId, $periodId]);
            $out[] = [
                'key' => $key, 'documents' => $documents, 'journal' => $journal, 'ok' => abs($documents - $journal) < 0.005,
                'other_accounts' => (int) $scalar($docs($table, $expr, $kind, $docType, $prefix, false), [$supplierId, $periodId]),
            ];
        }
        $bankDocs = $scalar(
            "SELECT COALESCE(SUM(t.amount), 0) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ?
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k JOIN journal_entries e ON e.id = k.entry_id AND e.supplier_id = k.supplier_id
                             WHERE k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id AND e.period_id = ?)
                AND EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = s.supplier_id AND m.kind = 'bank_transaction' AND m.target_id = t.id)",
            [$supplierId, $periodId]
        );
        $bankJournal = $scalar(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.period_id = ? AND a.account_code LIKE '221%'
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN pohoda_import_map m ON m.supplier_id = k.supplier_id AND m.target_id = k.doc_id AND m.kind = 'bank_transaction'
                            WHERE k.supplier_id = l.supplier_id AND k.entry_id = e.id AND k.doc_type = 'bank')",
            [$supplierId, $periodId]
        );
        $out[] = ['key' => 'bank', 'documents' => $bankDocs, 'journal' => $bankJournal, 'ok' => abs($bankDocs - $bankJournal) < 0.005, 'other_accounts' => 0];
        return $out;
    }
}
