<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\CreditCard;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CreditCardAccountRepository;
use MyInvoice\Service\Accounting\Card\CardClearingAccounts;
use MyInvoice\Service\Accounting\CreditCard\CreditCardAccounts;
use MyInvoice\Service\Accounting\CreditCard\CreditCardPostingService;
use PDO;

/**
 * Přehled a detail úvěrových účtů kreditních karet pro stránku Kreditní karty.
 *
 * Výpisy účtu = výpisy firmy (`bank_statements.supplier_id`) s číslem a kódem banky
 * úvěrového účtu. Kontrola „účetnictví sedí na banku": zůstatek analytiky 231.x ke dni
 * každého výpisu proti jeho konečnému zůstatku.
 *
 * Detail je rozcestník, ne druhá obrazovka banky: pohyby se zpracovávají ve výpisu
 * (/bank/{id}), tady je u každého pohybu vidět, co s ním zbývá udělat
 * ({@see self::STATES}), a souhrn „co zbývá dořešit".
 */
final class CreditCardOverview
{
    /**
     * Stav pohybu z pohledu účetní:
     *   unposted      - nezaúčtováno (bez návrhu),
     *   suggested     - automatika navrhla zaúčtování, čeká na schválení,
     *   clearing_open - nákup leží na mezičlenu 378.x, chybí doklad (nebo uzavření bez dokladu),
     *   settled       - nákup vypořádaný dokladem nebo uzavřený bez dokladu,
     *   posted        - zaúčtováno (bez mezičlenu),
     *   ignored       - ignorováno.
     */
    public const STATES = ['unposted', 'suggested', 'clearing_open', 'settled', 'posted', 'ignored'];

    /** Stavy, se kterými ještě musí někdo něco udělat. */
    public const TODO_STATES = ['unposted', 'suggested', 'clearing_open'];

    public function __construct(
        private readonly Connection $db,
        private readonly CreditCardAccountRepository $accounts,
        private readonly CreditCardAccounts $analytics,
        private readonly CardClearingAccounts $clearingAccounts,
        private readonly CreditCardPostingService $posting,
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
        $transactions = $this->transactions($supplierId, array_column($statements, 'id'));
        $code = $account['analytic_suffix'] !== null ? CreditCardAccounts::codeFor((string) $account['analytic_suffix']) : null;
        foreach ($statements as $i => $s) {
            $ledger = $code !== null ? $this->analytics->balance($supplierId, $code, $s['statement_date']) : null;
            $todo = array_filter($transactions, static fn (array $t): bool => $t['statement_id'] === $s['id'] && in_array($t['state'], self::TODO_STATES, true));
            $statements[$i] += [
                'ledger_balance' => $ledger,
                'difference'     => $ledger !== null ? round($ledger - $s['curr_balance'], 2) : null,
                'todo_count'     => count($todo),
            ];
        }
        return $this->summary($supplierId, $account, $statements) + [
            'statements'       => $statements,
            'transactions'     => $transactions,
            'todo'             => self::todo($transactions),
            'clearing'         => $this->posting->clearingInfo($supplierId, $account),
            'opening'          => $this->posting->openingPreview($supplierId, $id),
            'analytic_options' => $this->analytics->analyticOptions($supplierId),
        ];
    }

    /**
     * Souhrn „co zbývá dořešit": počet a částka pohybů v každém stavu, který ještě čeká.
     *
     * @param list<array<string,mixed>> $transactions
     * @return array<string, array{count:int, amount:float, first_statement_id:?int, first_tx_id:?int}>
     */
    public static function todo(array $transactions): array
    {
        $out = [];
        foreach (self::TODO_STATES as $state) {
            $out[$state] = ['count' => 0, 'amount' => 0.0, 'first_statement_id' => null, 'first_tx_id' => null];
        }
        // Pohyby jsou seřazené od nejnovějšího - proklik míří na nejstarší nevyřešený.
        foreach (array_reverse($transactions) as $t) {
            if (!isset($out[$t['state']])) {
                continue;
            }
            $row = &$out[$t['state']];
            $row['count']++;
            $row['amount'] = round($row['amount'] + abs((float) $t['amount']), 2);
            $row['first_statement_id'] ??= (int) $t['statement_id'];
            $row['first_tx_id'] ??= (int) $t['id'];
            unset($row);
        }
        return $out;
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
        ], $this->accounts->statements($supplierId, $account));
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
        $codes = $this->clearingAccounts->allClearingCodes($supplierId);
        $codeIn = $codes === [] ? "''" : implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount, bt.currency, bt.description, bt.counterparty_name,
                    bt.counterparty_account, bt.counterparty_bank, bt.card_last4, bt.match_status, bt.ignore_note,
                    (SELECT je.id FROM journal_entries je
                      WHERE je.supplier_id = ? AND je.source_type = 'bank' AND je.source_id = bt.id AND je.reversed_by IS NULL
                      ORDER BY je.id DESC LIMIT 1) AS entry_id,
                    (SELECT c.account_code FROM journal_entries je
                       JOIN journal_entry_lines jel ON jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
                       JOIN chart_of_accounts c ON c.id = jel.account_id AND c.supplier_id = je.supplier_id
                      WHERE je.supplier_id = ? AND je.source_type = 'bank' AND je.source_id = bt.id AND je.reversed_by IS NULL
                        AND c.account_code IN ({$codeIn})
                      LIMIT 1) AS clearing_code,
                    EXISTS (SELECT 1 FROM journal_entries s
                             WHERE s.supplier_id = ? AND s.source_id = bt.id AND s.reversed_by IS NULL
                               AND s.source_type IN ('card_settlement', 'card_writeoff')) AS settled,
                    (SELECT s.id FROM bank_posting_suggestions s
                      WHERE s.supplier_id = ? AND s.bank_transaction_id = bt.id AND s.status IN ('pending','needs_input','blocked')
                      ORDER BY s.id DESC LIMIT 1) AS suggestion_id,
                    EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.supplier_id = ? AND pm.bank_transaction_id = bt.id) AS has_document
               FROM bank_transactions bt
              WHERE bt.statement_id IN ({$in}) AND bt.source = 'statement'
              ORDER BY bt.posted_at DESC, bt.id DESC"
        );
        $stmt->execute([$supplierId, $supplierId, ...$codes, $supplierId, $supplierId, $supplierId]);
        return array_map(static function (array $r): array {
            $amount = round((float) $r['amount'], 2);
            $entryId = $r['entry_id'] !== null ? (int) $r['entry_id'] : null;
            $clearing = $r['clearing_code'] !== null ? (string) $r['clearing_code'] : null;
            $suggestionId = $r['suggestion_id'] !== null ? (int) $r['suggestion_id'] : null;
            $state = match (true) {
                (string) $r['match_status'] === 'ignored' => 'ignored',
                $entryId === null && $suggestionId !== null => 'suggested',
                $entryId === null => 'unposted',
                $clearing !== null && !(bool) $r['settled'] => 'clearing_open',
                $clearing !== null => 'settled',
                default => 'posted',
            };
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
                'ignore_note'          => $r['ignore_note'] !== null ? (string) $r['ignore_note'] : null,
                'entry_id'             => $entryId,
                'suggestion_id'        => $suggestionId,
                'clearing_code'        => $clearing,
                'has_document'         => (bool) $r['has_document'],
                'state'                => $state,
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
