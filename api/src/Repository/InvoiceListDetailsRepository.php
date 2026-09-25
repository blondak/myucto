<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class InvoiceListDetailsRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly JournalEntryRepository $journal,
    ) {}

    /** @param list<int> $ids */
    public function forDocuments(int $supplierId, string $source, array $ids, bool $includeVat, bool $includePosting): array
    {
        if ($ids === []) return [];
        $result = $includeVat ? $this->vatForDocuments($supplierId, $source, $ids) : [];
        if (!$includePosting) return $result;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT je.id, je.source_id FROM journal_entries je
              WHERE je.supplier_id = ? AND je.source_type = ?
                AND je.source_id IN ({$placeholders})
                AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL"
        );
        $stmt->execute([$supplierId, $source, ...$ids]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $lines = $this->journal->linesForEntries(array_map(static fn (array $e): int => (int) $e['id'], $entries), $supplierId);
        foreach ($entries as $entry) {
            $documentId = (int) $entry['source_id'];
            $result[$documentId] ??= [];
            foreach ($lines[(int) $entry['id']] ?? [] as $line) {
                $key = $line['side'] === 'debit' ? 'debit_accounts' : 'credit_accounts';
                $result[$documentId][$key][] = (string) $line['account_code'];
            }
        }
        foreach ($result as &$details) {
            foreach (['debit_accounts', 'credit_accounts'] as $key) {
                $codes = array_unique($details[$key] ?? []);
                sort($codes, SORT_NATURAL);
                $details[$key] = array_values($codes);
            }
        }
        unset($details);
        return $result;
    }

    /** @param list<int> $ids */
    public function vatForDocuments(int $supplierId, string $source, array $ids): array
    {
        if ($ids === []) return [];
        [$table, $itemsTable, $foreignKey] = match ($source) {
            'invoice' => ['invoices', 'invoice_items', 'invoice_id'],
            'purchase_invoice' => ['purchase_invoices', 'purchase_invoice_items', 'purchase_invoice_id'],
        };
        return $this->vatBreakdown($supplierId, $table, $itemsTable, $foreignKey, $ids);
    }

    /** @param list<int> $ids */
    private function vatBreakdown(int $supplierId, string $table, string $itemsTable, string $foreignKey, array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT item.{$foreignKey} AS document_id, item.vat_rate_snapshot AS rate, cur.code AS source_currency,
                    SUM(item.total_without_vat) AS base, SUM(item.total_vat) AS vat
               FROM {$itemsTable} item
               JOIN {$table} doc ON doc.id = item.{$foreignKey}
               JOIN currencies cur ON cur.id = doc.currency_id
              WHERE doc.supplier_id = ? AND doc.id IN ({$placeholders})
              GROUP BY item.{$foreignKey}, item.vat_rate_snapshot, cur.code
              ORDER BY item.vat_rate_snapshot DESC"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['document_id']]['source_currency'] = (string) $row['source_currency'];
            $result[(int) $row['document_id']]['vat_breakdown'][] = [
                'rate' => (float) $row['rate'],
                'base' => round((float) $row['base'], 2),
                'vat' => round((float) $row['vat'], 2),
            ];
        }
        return $result;
    }
}
