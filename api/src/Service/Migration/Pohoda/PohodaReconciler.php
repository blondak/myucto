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
 *      banka × 221 - jen převedené doklady a zápisy, na které jsou navázané; rozdíl, který
 *      je už v deníku POHODY, se vypíše po dokladech a převod neshodí;
 *   4. vyrovnaná rozvaha bez účtů, které mapa výkazů nezná.
 */
final class PohodaReconciler
{
    public const STEP = 'reconciliation';

    private const LABELS = ['purchase_invoices' => 'Přijaté faktury × 321', 'issued_invoices' => 'Vydané faktury × 311'];

    /**
     * Doklady POHODY, ze kterých vznikly doklady kontroly: agenda exportu, element dokladu,
     * zdroje zápisů v deníku, účet dokladu a zda účet roste na straně Dal.
     */
    private const SOURCES = [
        'issued_invoices' => [
            'invoice' => [['issued', 'invoice'], ['issued_debit', 'invoice'], ['issued_credit', 'invoice'], ['issued_corrective', 'invoice'], ['receivable', 'invoice'], ['internal', 'intDoc']],
            'journal' => [PohodaJournal::ISSUED, PohodaJournal::RECEIVABLE, PohodaJournal::INTERNAL],
            'table' => ['invoices', 'invoice', 'invoice', '311', false],
        ],
        'purchase_invoices' => [
            'invoice' => [['received', 'invoice'], ['received_debit', 'invoice'], ['received_credit', 'invoice'], ['received_corrective', 'invoice'], ['commitment', 'invoice'], ['internal', 'intDoc']],
            'journal' => [PohodaJournal::RECEIVED, PohodaJournal::COMMITMENT, PohodaJournal::INTERNAL],
            'table' => ['purchase_invoices', 'purchase_invoice', 'purchase_invoice', '321', true],
        ],
    ];

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
        foreach ($documents as $i => $d) {
            if (!$d['ok']) {
                $documents[$i] = $d = $this->explainBySource($ctx, $periodId, $d);
            }
            if (isset($d['source_differences'])) {
                $p->info(self::STEP, 'source_difference', self::sourceDifferenceText($d), ['check' => $d['key'], 'documents' => array_column($d['source_differences'], 'document_no')]);
            }
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

    /**
     * Rozdíl dokladů proti deníku, který je už v samotné POHODĚ: doklad zní na jinou částku,
     * než kolik jeho zápis v deníku POHODY dá na účet dokladu. Typicky odpočet nedaňové
     * zálohy, který POHODA zaúčtovala kladně na stranu MD 311 (311/602) místo proti ní - doklad
     * je uhrazený, ale 311 z něj v deníku drží dvojnásobek. MyÚčto obojí převzalo věrně
     * (předvaha sedí na deník POHODY), převod tedy sedí a rozdíl se vypíše po dokladech
     * (`source_differences`). Vysvětlený je jen doklad, jehož rozdíl v MyÚčtu je na haléř
     * stejný jako rozdíl téhož dokladu v POHODĚ, a jen když tyto doklady vysvětlí celý
     * rozdíl kontroly; cokoli jiného zůstává chybou převodu.
     *
     * @param array{key:string,documents:float,journal:float,ok:bool,other_accounts:int} $row
     * @return array<string,mixed>
     */
    private function explainBySource(PohodaContext $ctx, int $periodId, array $row): array
    {
        $spec = self::SOURCES[$row['key']] ?? null;
        if ($spec === null) {
            return $row;
        }
        $mine = $this->entryDifferences($ctx->supplierId, $periodId, $spec['table']);
        if ($mine === []) {
            return $row;
        }
        $pohoda = self::pohodaDifferences($ctx->export, $spec, array_keys($mine));
        $explained = [];
        $total = 0.0;
        foreach ($mine as $docNo => $difference) {
            $source = $pohoda[$docNo] ?? null;
            if ($source === null || abs($source['difference']) < 0.005 || abs($source['difference'] - $difference) >= 0.005) {
                return $row;
            }
            $explained[] = [
                'document_no' => (string) $docNo,
                'difference' => $difference,
                'reason' => abs($source['advance']) >= 0.005 && abs($source['advance'] - $difference) < 0.005 ? 'advance_deduction' : 'amount',
            ];
            $total += $difference;
        }
        if (abs(round($row['documents'] - $row['journal'], 2) - round($total, 2)) >= 0.005) {
            return $row;
        }
        $row['ok'] = true;
        $row['source_differences'] = $explained;
        return $row;
    }

    /** @param array{key:string,documents:float,journal:float,source_differences:list<array{document_no:string,difference:float,reason:string}>} $row */
    private static function sourceDifferenceText(array $row): string
    {
        $money = static fn (float $v): string => number_format($v, 2, ',', ' ');
        $list = static function (array $rows) use ($money): string {
            $shown = array_map(static fn (array $s): string => $s['document_no'] . ' (' . $money($s['difference']) . ')', array_slice($rows, 0, 30));
            return implode(', ', $shown) . (count($rows) > 30 ? ' a další' : '');
        };
        $account = self::SOURCES[$row['key']]['table'][3] ?? '';
        $advance = array_values(array_filter($row['source_differences'], static fn (array $s): bool => $s['reason'] === 'advance_deduction'));
        $amount = array_values(array_filter($row['source_differences'], static fn (array $s): bool => $s['reason'] !== 'advance_deduction'));
        $text = sprintf('%s: rozdíl dokladů proti deníku %s Kč je už v deníku POHODY, převod ho převzal beze změny.',
            self::LABELS[$row['key']] ?? $row['key'], $money(round($row['documents'] - $row['journal'], 2)));
        if ($advance !== []) {
            $text .= sprintf(' U %d dokladů je odpočet nedaňové zálohy zaúčtovaný kladně na stranu MD účtu %s, takže účet v deníku drží navíc částku zálohy: %s.',
                count($advance), $account, $list($advance));
        }
        if ($amount !== []) {
            $text .= sprintf(' U %d dokladů se částka dokladu liší od jeho zápisu na účtu %s: %s.', count($amount), $account, $list($amount));
        }
        return $text . ' Saldo těchto dokladů na účtu ' . $account . ' zkontrolujte a případně opravte interním dokladem.';
    }

    /**
     * Rozdíl převedených dokladů proti účtu dokladu v zápisech, na které jsou navázané,
     * po číslech dokladů (jen zápisy, které mají účet dokladu na jedné straně - jako kontrola).
     *
     * @param array{0:string,1:string,2:string,3:string,4:bool} $table
     * @return array<string,float>
     */
    private function entryDifferences(int $supplierId, int $periodId, array $table): array
    {
        [$docTable, $kind, $docType, $prefix, $creditPositive] = $table;
        $sign = $creditPositive ? "CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END" : "CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END";
        $linked = "FROM journal_entry_document_links k
                     JOIN {$docTable} d ON d.id = k.doc_id AND d.supplier_id = k.supplier_id
                    WHERE k.supplier_id = e.supplier_id AND k.entry_id = e.id AND k.doc_type = '{$docType}'
                      AND EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = '{$kind}' AND m.target_id = d.id)";
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.document_no,
                    (SELECT COALESCE(SUM(d.total_with_vat), 0) {$linked}) AS docs,
                    (SELECT COALESCE(SUM({$sign}), 0)
                       FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
                      WHERE l.supplier_id = e.supplier_id AND l.entry_id = e.id AND a.account_code LIKE '{$prefix}%') AS journal
               FROM journal_entries e
              WHERE e.supplier_id = ? AND e.period_id = ? AND e.source_type <> 'opening' AND EXISTS (SELECT 1 {$linked})
                AND (SELECT COUNT(DISTINCT l2.side) FROM journal_entry_lines l2
                       JOIN chart_of_accounts a2 ON a2.id = l2.account_id AND a2.supplier_id = l2.supplier_id
                      WHERE l2.supplier_id = e.supplier_id AND l2.entry_id = e.id AND a2.account_code LIKE '{$prefix}%') = 1"
        );
        $stmt->execute([$supplierId, $periodId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $docNo = trim((string) $r['document_no']);
            $out[$docNo] = round(($out[$docNo] ?? 0.0) + (float) $r['docs'] - (float) $r['journal'], 2);
        }
        return array_filter($out, static fn (float $d): bool => abs($d) >= 0.005);
    }

    /**
     * Rozdíl dokladu proti jeho zápisu přímo v POHODĚ (částka dokladu tak, jak ji převod
     * převezme, − účet dokladu v deníku POHODY) a odpočet nedaňových záloh dokladu.
     *
     * @param array{invoice:list<array{0:string,1:string}>,journal:list<string>,table:array{0:string,1:string,2:string,3:string,4:bool}} $spec
     * @param list<string|int> $docNos
     * @return array<string,array{difference:float,advance:float}>
     */
    private static function pohodaDifferences(PohodaExport $export, array $spec, array $docNos): array
    {
        $wanted = array_flip(array_map('strval', $docNos));
        [, , , $prefix, $creditPositive] = $spec['table'];
        $amounts = [];
        $advances = [];
        foreach ($spec['invoice'] as [$agenda, $tag]) {
            foreach ($export->records($agenda, $tag) as $r) {
                $docNo = PohodaXml::text(PohodaXml::get($r, $tag . 'Header') ?? [], 'number/numberRequested');
                if (!isset($wanted[$docNo])) {
                    continue;
                }
                [$total, $advance] = self::documentTotal($r, $tag);
                $amounts[$docNo] = ($amounts[$docNo] ?? 0.0) + $total;
                $advances[$docNo] = ($advances[$docNo] ?? 0.0) + $advance;
            }
        }
        $journal = [];
        foreach ($export->records('journal', 'accountingItem') as $item) {
            $docNo = PohodaJournal::number($item);
            if (!isset($amounts[$docNo]) || !in_array(PohodaJournal::source($item), $spec['journal'], true) || PohodaJournal::isYearEndClosing($item)) {
                continue;
            }
            $effect = PohodaJournal::effect($item);
            if ($effect === null) {
                continue;
            }
            $net = (str_starts_with($effect['debit'], $prefix) ? $effect['amount'] : 0.0) - (str_starts_with($effect['credit'], $prefix) ? $effect['amount'] : 0.0);
            $journal[$docNo] = ($journal[$docNo] ?? 0.0) + ($creditPositive ? -$net : $net);
        }
        $out = [];
        foreach ($amounts as $docNo => $amount) {
            $out[(string) $docNo] = ['difference' => round($amount - ($journal[$docNo] ?? 0.0), 2), 'advance' => round($advances[$docNo], 2)];
        }
        return $out;
    }

    /**
     * Celkem dokladu tak, jak ho převod převezme ({@see InvoiceImporter}): rekapitulace po
     * sazbách se zaokrouhlením a zdaněné odpočty záloh; nedaňové odpočty jsou jen úhrada.
     *
     * @return array{0:float,1:float} celkem, součet nedaňových odpočtů záloh (záporný)
     */
    private static function documentTotal(array $r, string $tag): array
    {
        $summary = PohodaXml::get($r, $tag . 'Summary/homeCurrency') ?? [];
        $total = PohodaXml::num($summary, 'priceNone') + PohodaXml::num($summary, 'round/priceRound');
        foreach (['Low', 'High', '3'] as $bucket) {
            $total += PohodaXml::num($summary, 'price' . $bucket) + PohodaXml::num($summary, 'price' . $bucket . 'VAT');
        }
        $advance = 0.0;
        foreach (PohodaXml::all($r, $tag . 'Detail/invoiceAdvancePaymentItem') as $a) {
            if (abs(round(PohodaXml::num($a, 'homeCurrency/priceVAT'), 2)) >= 0.005) {
                $total += PohodaXml::num($a, 'homeCurrency/priceSum');
            } else {
                $advance += PohodaXml::num($a, 'homeCurrency/priceSum');
            }
        }
        return [round($total, 2), round($advance, 2)];
    }
}
