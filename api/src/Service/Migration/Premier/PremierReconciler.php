<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Bank\BankTransactionPostingScope;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Reconciler;

/**
 * Rekonciliace převodu roku - důkaz, že MyÚčto po převodu ukazuje totéž co PREMIER:
 *   1. obratová předvaha MyÚčta ({@see TrialBalanceService}) proti předvaze spočtené přímo
 *      z deníku PREMIER (počáteční stavy dopočtené z minulých let) - po syntetických účtech,
 *      PS / obrat / KS, na haléř;
 *   2. vnitřní kontroly předvahy (obraty MD = D, předvaha = deník, vyrovnané PS);
 *   3. doklady proti deníku: přijaté faktury × 321, vydané × 311, pokladna × 211,
 *      banka × 221 - jen převedené doklady a zápisy, na které jsou navázané;
 *   4. vyrovnaná rozvaha bez účtů, které mapa výkazů nezná;
 *   5. každý bankovní pohyb převodu má vlastní zápis deníku (zdroj `bank`) - jinak by ho
 *      Doúčtování zaúčtovalo podruhé.
 */
final class PremierReconciler
{
    public const STEP = 'reconciliation';

    public function __construct(
        private readonly Connection $db,
        private readonly TrialBalanceService $trialBalance,
        private readonly FinancialStatementService $statements,
    ) {}

    public function run(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($ctx->period === null) {
            $p->finish(self::STEP);
            return;
        }
        $periodId = $ctx->period['id'];
        $opening = $ctx->opening;
        foreach ($ctx->journal->openingRows($ctx->year) as $r) {
            foreach ([[$r['md'], 1], [$r['dal'], -1]] as [$code, $sign]) {
                if (!str_starts_with((string) $code, PremierJournal::OPENING_ACCOUNT)) {
                    $opening[$code] = ($opening[$code] ?? 0.0) + $sign * $r['amount'];
                }
            }
        }
        $premier = $ctx->journal->trialBalance($ctx->year, $opening);

        $tb = $this->trialBalance->build($ctx->supplierId, $periodId, null, null, false, false);
        $mine = [];
        foreach ($tb['rows'] as $row) {
            $syn = substr((string) $row['account_code'], 0, 3);
            if (str_starts_with($syn, '7')) {
                continue; // 701 otevíracího zápisu PREMIER v deníku nevede
            }
            $mine[$syn] ??= [0.0, 0.0, 0.0];
            $mine[$syn][0] += (float) $row['ps_md'] - (float) $row['ps_d'];
            $mine[$syn][1] += (float) $row['turnover_md'] - (float) $row['turnover_d'];
            $mine[$syn][2] += (float) $row['ks_md'] - (float) $row['ks_d'];
        }
        foreach ($mine as $syn => $v) {
            $mine[$syn] = [round($v[0], 2), round($v[1], 2), round($v[2], 2)];
        }
        $journalDiffs = MoneyS3Reconciler::compare($mine, $premier);
        $checks = [
            ['key' => 'turnover_balanced', 'ok' => (bool) $tb['checks']['turnover_balanced']],
            ['key' => 'matches_journal', 'ok' => (bool) $tb['checks']['matches_journal']],
            ['key' => 'opening_balanced', 'ok' => (bool) $tb['checks']['opening_balanced']],
            ['key' => 'no_drafts', 'ok' => (int) $tb['draft_count'] === 0],
            ['key' => 'premier_journal', 'ok' => $journalDiffs === [], 'accounts' => count($premier)],
        ];
        $documents = $this->documentsAgainstJournal($ctx->supplierId, $periodId);
        foreach ($documents as $d) {
            $checks[] = ['key' => 'documents_' . $d['key'], 'ok' => $d['ok']];
        }
        $bank = $this->bankPosting($ctx->supplierId, $ctx->period['starts_on'], $ctx->period['ends_on']);
        $checks[] = ['key' => 'bank_transactions_posted', 'ok' => $bank['unposted'] === 0, 'transactions' => $bank['transactions'], 'unposted' => $bank['unposted']];
        if ($bank['unposted'] > 0) {
            $p->error(self::STEP, 'bank_transactions_unposted', sprintf('Rok %d: %d z %d bankovních pohybů převodu nemá vlastní zápis v deníku.', $ctx->year, $bank['unposted'], $bank['transactions']),
                ['year' => $ctx->year, 'transactions' => $bank['transactions'], 'unposted' => $bank['unposted'], 'ids' => $bank['ids']]);
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
            'year' => $ctx->year,
            'period_id' => $periodId,
            'ok' => $ok,
            'checks' => $checks,
            'totals' => $tb['totals'],
            'journal_diffs' => $journalDiffs,
            'documents' => $documents,
            'unmapped_accounts' => $unmapped,
        ]]);
        if (!$ok) {
            $p->error(self::STEP, 'reconciliation_failed', "Rok {$ctx->year}: převod nesedí, podrobnosti v rekonciliaci.", ['year' => $ctx->year]);
        }
        $p->finish(self::STEP);
    }

    /**
     * Bankovní pohyby převodu v období a kolik z nich nemá vlastní zápis deníku.
     *
     * @return array{transactions:int,unposted:int,ids:list<int>}
     */
    private function bankPosting(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.id, " . BankTransactionPostingScope::existsSql('s.supplier_id', 't.id') . " AS posted
               FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND t.posted_at BETWEEN ? AND ?
                AND EXISTS (SELECT 1 FROM premier_import_map m WHERE m.supplier_id = s.supplier_id AND m.kind = 'bank_transaction' AND m.target_id = t.id)"
        );
        $stmt->execute([$supplierId, $from, $to]);
        $total = 0;
        $ids = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$id, $posted]) {
            $total++;
            if (!(bool) $posted) {
                $ids[] = (int) $id;
            }
        }
        return ['transactions' => $total, 'unposted' => count($ids), 'ids' => array_slice($ids, 0, 50)];
    }

    /**
     * Doklady proti zápisům, na které jsou navázané (stejně jako u převodu z POHODY).
     * Porovnávají se jen zápisy, které mají účet dokladu na JEDNÉ straně (vznik závazku
     * nebo pohledávky); doklad účtovaný jinak se počítá zvlášť (`other_accounts`).
     *
     * @return list<array{key:string,documents:float,journal:float,ok:bool,other_accounts:int}>
     */
    private function documentsAgainstJournal(int $supplierId, int $periodId): array
    {
        $pdo = $this->db->pdo();
        $notPayment = 'AND (k.note IS NULL OR k.note <> ' . $pdo->quote(DocumentLinker::PAYMENT_NOTE) . ')';
        $scalar = static function (string $sql, array $params) use ($pdo): float {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return round((float) $stmt->fetchColumn(), 2);
        };
        $oneSided = static fn (string $entry, string $prefix): string =>
            "(SELECT COUNT(DISTINCT l2.side) FROM journal_entry_lines l2
                JOIN chart_of_accounts a2 ON a2.id = l2.account_id AND a2.supplier_id = l2.supplier_id
               WHERE l2.supplier_id = l.supplier_id AND l2.entry_id = {$entry} AND a2.account_code LIKE '{$prefix}%') = 1";
        $mapped = static fn (string $alias, string $kinds): string =>
            "EXISTS (SELECT 1 FROM premier_import_map m WHERE m.supplier_id = {$alias}.supplier_id AND m.kind IN ({$kinds}) AND m.target_id = {$alias}.id)";
        $ledger = static fn (string $kinds, string $docType, string $prefix, string $sign): string =>
            "SELECT COALESCE(SUM({$sign}), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.period_id = ? AND a.account_code LIKE '{$prefix}%' AND e.source_type <> 'opening'
                AND " . $oneSided('e.id', $prefix) . "
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN premier_import_map m ON m.supplier_id = k.supplier_id AND m.target_id = k.doc_id AND m.kind IN ({$kinds})
                            WHERE k.supplier_id = l.supplier_id AND k.entry_id = e.id AND k.doc_type = '{$docType}' {$notPayment})";
        $docs = static fn (string $table, string $expr, string $kinds, string $docType, string $prefix, bool $onAccount): string =>
            'SELECT ' . ($onAccount ? "COALESCE(SUM({$expr}), 0)" : 'COUNT(*)') . "
               FROM {$table} d
              WHERE d.supplier_id = ?
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN journal_entries e ON e.id = k.entry_id AND e.supplier_id = k.supplier_id
                            WHERE k.supplier_id = d.supplier_id AND k.doc_id = d.id AND e.period_id = ? AND k.doc_type = '{$docType}' {$notPayment}
                              AND e.source_type <> 'opening'
                              AND " . ($onAccount ? '' : 'NOT ') . str_replace('l.supplier_id', 'k.supplier_id', $oneSided('k.entry_id', $prefix)) . ')
                AND ' . $mapped('d', $kinds);

        $spec = [
            ['purchase_invoices', 'purchase_invoices', 'd.total_with_vat', "'purchase_invoice'", 'purchase_invoice', '321', "CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END"],
            ['issued_invoices', 'invoices', 'd.total_with_vat', "'invoice'", 'invoice', '311', "CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END"],
            ['cash', 'cash_documents', "CASE WHEN d.doc_type = 'in' THEN d.total_amount ELSE -d.total_amount END", "'cash_document'", 'cash', '211', "CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END"],
        ];
        $out = [];
        foreach ($spec as [$key, $table, $expr, $kinds, $docType, $prefix, $sign]) {
            $documents = $scalar($docs($table, $expr, $kinds, $docType, $prefix, true), [$supplierId, $periodId]);
            $journal = $scalar($ledger($kinds, $docType, $prefix, $sign), [$supplierId, $periodId]);
            $out[] = [
                'key' => $key, 'documents' => $documents, 'journal' => $journal, 'ok' => abs($documents - $journal) < 0.005,
                'other_accounts' => (int) $scalar($docs($table, $expr, $kinds, $docType, $prefix, false), [$supplierId, $periodId]),
            ];
        }
        // Banka jen u účtů v Kč - pohyb účtu v cizí měně je v měně účtu, deník v Kč.
        $bankDocs = $scalar(
            "SELECT COALESCE(SUM(t.amount), 0) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND t.currency = 'CZK'
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k JOIN journal_entries e ON e.id = k.entry_id AND e.supplier_id = k.supplier_id
                             WHERE k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id AND e.period_id = ?)
                AND EXISTS (SELECT 1 FROM premier_import_map m WHERE m.supplier_id = s.supplier_id AND m.kind = 'bank_transaction' AND m.target_id = t.id)",
            [$supplierId, $periodId]
        );
        $bankJournal = $scalar(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.period_id = ? AND a.account_code LIKE '221%' AND l.currency_code IS NULL
                AND EXISTS (SELECT 1 FROM journal_entry_document_links k
                             JOIN bank_transactions t ON t.id = k.doc_id
                             JOIN premier_import_map m ON m.supplier_id = k.supplier_id AND m.target_id = t.id AND m.kind = 'bank_transaction'
                            WHERE k.supplier_id = l.supplier_id AND k.entry_id = e.id AND k.doc_type = 'bank' AND t.currency = 'CZK')",
            [$supplierId, $periodId]
        );
        $out[] = ['key' => 'bank', 'documents' => $bankDocs, 'journal' => $bankJournal, 'ok' => abs($bankDocs - $bankJournal) < 0.005, 'other_accounts' => 0];
        return $out;
    }
}
