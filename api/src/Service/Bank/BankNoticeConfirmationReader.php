<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Dávkový přehled uloženého životního cyklu. Živé posouzení změny patří do confirmation service. */
final class BankNoticeConfirmationReader
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<int,array<string,mixed>> */
    public function notices(int $supplierId, array $transactionIds): array
    {
        $rows = $this->rows($supplierId, $transactionIds, 'bt.id');
        if ($rows === []) return [];
        $targets = $this->targets($supplierId, $rows);
        $candidates = $this->candidateRows($supplierId, $rows);
        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $target = $targets[(int) ($row['authoritative_transaction_id'] ?? 0)] ?? null;
            $result[$id] = [
                'confirmation_state' => $row['confirmation_state'],
                'confirmation_reason' => $row['confirmation_reason'],
                'authoritative_transaction_id' => $target === null ? null : (int) $target['id'],
                'authoritative_statement_id' => $target === null ? null : (int) $target['statement_id'],
                'authoritative_statement_ids' => $target['statement_ids'] ?? [],
                'candidates' => [],
            ];
            if ($row['confirmation_state'] === 'confirmed') continue;
            $date = new \DateTimeImmutable((string) $row['posted_at']);
            $from = $date->modify('-' . EmailNoticeReconciler::DATE_WINDOW_DAYS . ' days')->format('Y-m-d');
            $to = $date->modify('+' . EmailNoticeReconciler::DATE_WINDOW_DAYS . ' days')->format('Y-m-d');
            foreach ($candidates as $candidate) {
                if ($candidate['posted_at'] < $from || $candidate['posted_at'] > $to
                    || !EmailNoticeReconciler::sameOwnAccount($candidate, $row)
                    || !EmailNoticeReconciler::sameCurrency($candidate, $row)
                    || EmailNoticeReconciler::twinIdentity($candidate, $row, VariableSymbolNormalizer::digits((string) ($candidate['variable_symbol'] ?? ''))) === null
                    || ((float) $candidate['amount'] <=> 0) !== ((float) $row['amount'] <=> 0)) continue;
                $public = array_intersect_key($candidate, array_flip(['id', 'statement_id', 'source', 'posted_at', 'amount', 'currency', 'variable_symbol', 'counterparty_name', 'counterparty_account', 'counterparty_bank', 'description', 'bank_ref', 'match_status']));
                $public['id'] = (int) $public['id'];
                $public['statement_id'] = (int) $public['statement_id'];
                $public['amount'] = (float) $public['amount'];
                $result[$id]['candidates'][] = $public;
            }
        }
        return $result;
    }

    /** Příznak mazání je předběžný; DELETE vždy znovu kontroluje závislosti pod zámkem. @return array<int,array<string,mixed>> */
    public function statements(int $supplierId, array $statementIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $statementIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) return [];
        $result = [];
        foreach ($ids as $id) $result[$id] = ['confirmation_summary' => ['pending' => 0, 'review' => 0, 'confirmed' => 0, 'total' => 0], 'can_delete_notices' => false, 'deletion_blockers' => []];
        foreach ($this->rows($supplierId, $ids, 'bt.statement_id', true) as $row) {
            $entry = &$result[(int) $row['statement_id']];
            $entry['confirmation_summary'][$row['confirmation_state']] += (int) $row['notice_count'];
            $entry['confirmation_summary']['total'] += (int) $row['notice_count'];
            $codes = [];
            if ($row['confirmation_state'] !== 'confirmed') $codes[$row['confirmation_reason'] ?? 'not_confirmed'] = (int) $row['notice_count'];
            foreach ($row as $key => $value) {
                if (str_starts_with($key, 'dependency_') && (int) $value > 0) $codes[$key] = max($codes[$key] ?? 0, (int) $value);
            }
            foreach ($codes as $code => $count) $entry['deletion_blockers'][$code] = ($entry['deletion_blockers'][$code] ?? 0) + $count;
            unset($entry);
        }
        foreach ($result as &$entry) {
            $summary = $entry['confirmation_summary'];
            $entry['can_delete_notices'] = $summary['total'] > 0 && $summary['confirmed'] === $summary['total'] && $entry['deletion_blockers'] === [];
            $entry['deletion_blockers'] = array_map(static fn (string $code, int $count): array => ['code' => $code, 'count' => $count], array_keys($entry['deletion_blockers']), array_values($entry['deletion_blockers']));
        }
        unset($entry);
        return $result;
    }

    /** @return array<int,int> */
    public function deletedCounts(int $supplierId, array $statementIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $statementIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) return [];
        $stmt = $this->db->pdo()->prepare('SELECT bt.statement_id, COUNT(*) AS notice_count FROM bank_notice_confirmations c JOIN bank_transactions bt ON bt.id=c.authoritative_transaction_id WHERE c.supplier_id=? AND c.deleted_at IS NOT NULL AND bt.statement_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY bt.statement_id');
        $stmt->execute([$supplierId, ...$ids]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(int) $row['statement_id']] = (int) $row['notice_count'];
        return $result;
    }

    private function rows(int $supplierId, array $ids, string $column, bool $dependencies = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) return [];
        $extra = '';
        $predicates = [];
        if ($dependencies) {
            $predicates = [];
            foreach ([...BankNoticeConfirmationService::blockingLinks(), ['invoice_payments', 'bank_transaction_id'], ['payment_matches', 'bank_transaction_id'], ['gopay_clearings', 'payout_match_transaction_id']] as [$table, $key]) {
                $predicates[$table][] = "EXISTS (SELECT 1 FROM {$table} dep WHERE dep.{$key}=bt.id)";
            }
            foreach (['journal_entries' => "source_type IN ('bank','card_settlement','card_writeoff') AND source_id=bt.id", 'cash_journal_notes' => "source_type='bank' AND source_id=bt.id AND deleted_at IS NULL", 'de_movement_classification_history' => "source_type='bank' AND source_id=bt.id", 'document_links' => "entity_type='bank_transaction' AND entity_id=bt.id", 'document_dimensions' => "doc_type='bank_transaction' AND doc_id=bt.id", 'document_dimension_splits' => "doc_type='bank_transaction' AND doc_id=bt.id"] as $table => $where) $predicates[$table][] = "EXISTS (SELECT 1 FROM {$table} dep WHERE {$where})";
            foreach ($predicates as $table => $conditions) $extra .= ', (' . implode(' OR ', $conditions) . ") AS dependency_{$table}";
        }
        $columns = $dependencies ? 'bt.id,bt.statement_id' : 'bt.*,bs.supplier_id,bs.account_number AS stmt_account,bs.bank_code AS stmt_bank,bs.currency AS stmt_currency,c.authoritative_transaction_id';
        $sql = "SELECT {$columns},
            CASE WHEN c.state='confirmed' AND c.authoritative_transaction_id IS NULL THEN 'review' ELSE COALESCE(c.state,'pending') END AS confirmation_state,
            CASE WHEN c.state='confirmed' AND c.authoritative_transaction_id IS NULL THEN 'authoritative_removed' WHEN c.id IS NULL THEN 'not_checked' ELSE c.reason END AS confirmation_reason
            {$extra} FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id LEFT JOIN bank_notice_confirmations c ON c.notice_transaction_id=bt.id AND c.supplier_id=bs.supplier_id
            WHERE bs.supplier_id=? AND bs.source='email_notice' AND bt.source='email_notice' AND {$column} IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
        if ($dependencies) {
            $sums = '';
            foreach (array_keys($predicates) as $table) $sums .= ", SUM(dependency_{$table}) AS dependency_{$table}";
            $sql = 'WITH notice_states AS (' . $sql . ') SELECT statement_id,confirmation_state,confirmation_reason,COUNT(*) AS notice_count' . $sums . ' FROM notice_states GROUP BY statement_id,confirmation_state,confirmation_reason ORDER BY statement_id,confirmation_state,confirmation_reason';
        } else {
            $sql .= ' ORDER BY bt.id';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, ...$ids]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function targets(int $supplierId, array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'authoritative_transaction_id')))));
        if ($ids === []) return [];
        $stmt = $this->db->pdo()->prepare('SELECT bt.id,bt.statement_id,bi.statement_id AS imported_statement_id FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id LEFT JOIN bank_transaction_imports bi ON bi.bank_transaction_id=bt.id WHERE bs.supplier_id=? AND bt.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY bt.id,bi.statement_id');
        $stmt->execute([$supplierId, ...$ids]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $result[$id] ??= ['id' => $id, 'statement_id' => (int) $row['statement_id'], 'statement_ids' => [(int) $row['statement_id']]];
            if ($row['imported_statement_id'] !== null) $result[$id]['statement_ids'][] = (int) $row['imported_statement_id'];
        }
        foreach ($result as &$row) $row['statement_ids'] = array_values(array_unique($row['statement_ids']));
        unset($row);
        return $result;
    }

    private function candidateRows(int $supplierId, array $rows): array
    {
        $pending = array_filter($rows, static fn (array $row): bool => $row['confirmation_state'] !== 'confirmed');
        if ($pending === []) return [];
        $ids = array_map('intval', array_column($pending, 'id'));
        $stmt = $this->db->pdo()->prepare('WITH notice_dates AS (SELECT DISTINCT posted_at FROM bank_transactions WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . "))
            SELECT DISTINCT bt.*,bs.account_number AS stmt_account,bs.bank_code AS stmt_bank,bs.currency AS stmt_currency
            FROM notice_dates nd JOIN bank_transactions bt ON bt.posted_at BETWEEN DATE_SUB(nd.posted_at, INTERVAL 5 DAY) AND DATE_ADD(nd.posted_at, INTERVAL 5 DAY)
            JOIN bank_statements bs ON bs.id=bt.statement_id WHERE bs.supplier_id=? AND bt.source='statement' AND bs.source IN " . BankStatementSource::sqlList() . ' ORDER BY bt.id');
        $stmt->execute([...$ids, $supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
