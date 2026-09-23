<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\CreditCard;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CreditCardAccountRepository;
use MyInvoice\Service\Accounting\CreditCard\CreditCardAccounts;
use PDO;

/**
 * Přehled a detail úvěrových účtů kreditních karet pro stránku Kreditní karty.
 *
 * Výpisy účtu = výpisy firmy (`bank_statements.supplier_id`) s číslem a kódem banky
 * úvěrového účtu. Kontrola „účetnictví sedí na banku": zůstatek analytiky 231.x ke dni
 * posledního výpisu proti jeho konečnému zůstatku.
 */
final class CreditCardOverview
{
    public function __construct(
        private readonly Connection $db,
        private readonly CreditCardAccountRepository $accounts,
        private readonly CreditCardAccounts $analytics,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, bool $includeArchived = false): array
    {
        $out = [];
        foreach ($this->accounts->listForSupplier($supplierId, $includeArchived) as $account) {
            $out[] = $this->summary($supplierId, $account);
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function detail(int $supplierId, int $id): ?array
    {
        $account = $this->accounts->find($supplierId, $id);
        if ($account === null) {
            return null;
        }
        $statements = $this->statements($supplierId, $account);
        return $this->summary($supplierId, $account, $statements) + [
            'statements'       => $statements,
            'transactions'     => $this->transactions($supplierId, array_column($statements, 'id')),
            'analytic_options' => $this->analytics->analyticOptions($supplierId),
        ];
    }

    /**
     * @param array<string,mixed> $account
     * @param list<array<string,mixed>>|null $statements
     * @return array<string,mixed>
     */
    private function summary(int $supplierId, array $account, ?array $statements = null): array
    {
        $statements ??= $this->statements($supplierId, $account);
        $code = $account['analytic_suffix'] !== null ? CreditCardAccounts::codeFor((string) $account['analytic_suffix']) : null;
        $last = $statements[0] ?? null;
        $ledger = $code !== null ? $this->analytics->balance($supplierId, $code) : null;
        $ledgerAtStatement = $code !== null && $last !== null
            ? $this->analytics->balance($supplierId, $code, (string) $last['statement_date'])
            : null;
        // Zůstatek 231 je dluh na straně D (záporný MD − D) - stejné znaménko jako výpis.
        $difference = $ledgerAtStatement !== null && $last !== null
            ? round($ledgerAtStatement - (float) $last['curr_balance'], 2)
            : null;
        $ids = array_column($statements, 'id');
        return $account + [
            'account_code'          => $code,
            'ledger_balance'        => $ledger,
            'last_statement'        => $last,
            'statement_count'       => count($statements),
            'unposted_count'        => $this->unpostedCount($supplierId, $ids),
            'balance_difference'    => $difference,
        ];
    }

    /**
     * @param array<string,mixed> $account
     * @return list<array<string,mixed>>
     */
    private function statements(int $supplierId, array $account): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT bs.id, bs.statement_date, bs.statement_number, bs.prev_balance, bs.curr_balance,
                    bs.credit_total, bs.debit_total, bs.transaction_count, bs.file_name, bs.source,
                    (bs.pdf_content IS NOT NULL) AS has_pdf
               FROM bank_statements bs
              WHERE bs.supplier_id = ?
                AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''), '[^0-9]', '')) = ?
                AND COALESCE(bs.bank_code, '') = ?
              ORDER BY bs.statement_date DESC, bs.id DESC"
        );
        $stmt->execute([$supplierId, (string) $account['account_canonical'], (string) $account['bank_code_norm']]);
        return array_map(static fn (array $r): array => [
            'id'                => (int) $r['id'],
            'statement_date'    => (string) $r['statement_date'],
            'statement_number'  => (string) ($r['statement_number'] ?? ''),
            'prev_balance'      => round((float) $r['prev_balance'], 2),
            'curr_balance'      => round((float) $r['curr_balance'], 2),
            'credit_total'      => round((float) $r['credit_total'], 2),
            'debit_total'       => round((float) $r['debit_total'], 2),
            'transaction_count' => (int) $r['transaction_count'],
            'file_name'         => (string) ($r['file_name'] ?? ''),
            'has_pdf'           => (bool) $r['has_pdf'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param list<int> $statementIds
     * @return list<array<string,mixed>>
     */
    private function transactions(int $supplierId, array $statementIds): array
    {
        if ($statementIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $statementIds));
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount, bt.currency, bt.description, bt.counterparty_name,
                    bt.counterparty_account, bt.counterparty_bank, bt.card_last4, bt.match_status,
                    (SELECT je.id FROM journal_entries je
                      WHERE je.supplier_id = ? AND je.source_type = 'bank' AND je.source_id = bt.id AND je.reversed_by IS NULL
                      ORDER BY je.id DESC LIMIT 1) AS entry_id,
                    (SELECT s.id FROM bank_posting_suggestions s
                      WHERE s.supplier_id = ? AND s.bank_transaction_id = bt.id AND s.status IN ('pending','needs_input','blocked')
                      ORDER BY s.id DESC LIMIT 1) AS suggestion_id
               FROM bank_transactions bt
              WHERE bt.statement_id IN ({$in}) AND bt.source = 'statement'
              ORDER BY bt.posted_at DESC, bt.id DESC"
        );
        $stmt->execute([$supplierId, $supplierId]);
        return array_map(static function (array $r): array {
            $amount = round((float) $r['amount'], 2);
            return [
                'id'                   => (int) $r['id'],
                'statement_id'         => (int) $r['statement_id'],
                'posted_at'            => (string) $r['posted_at'],
                'amount'               => $amount,
                'currency'             => (string) ($r['currency'] ?? 'CZK'),
                'description'          => $r['description'] !== null ? (string) $r['description'] : null,
                'counterparty_name'    => $r['counterparty_name'] !== null ? (string) $r['counterparty_name'] : null,
                'counterparty_account' => $r['counterparty_account'] !== null
                    ? (string) $r['counterparty_account'] . ($r['counterparty_bank'] !== null ? '/' . $r['counterparty_bank'] : '')
                    : null,
                'card_last4'           => $r['card_last4'] !== null ? (string) $r['card_last4'] : null,
                'kind'                 => CreditCardTransactionKind::classify($r['description'] !== null ? (string) $r['description'] : null, $amount),
                'match_status'         => (string) $r['match_status'],
                'entry_id'             => $r['entry_id'] !== null ? (int) $r['entry_id'] : null,
                'suggestion_id'        => $r['suggestion_id'] !== null ? (int) $r['suggestion_id'] : null,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param list<int> $statementIds */
    private function unpostedCount(int $supplierId, array $statementIds): int
    {
        if ($statementIds === []) {
            return 0;
        }
        $in = implode(',', array_map('intval', $statementIds));
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM bank_transactions bt
              WHERE bt.statement_id IN ({$in}) AND bt.source = 'statement' AND bt.match_status <> 'ignored'
                AND NOT EXISTS (SELECT 1 FROM journal_entries je
                                 WHERE je.supplier_id = ? AND je.source_type = 'bank'
                                   AND je.source_id = bt.id AND je.reversed_by IS NULL)"
        );
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }
}
