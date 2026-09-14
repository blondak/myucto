<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use PDO;

final class PurchasePaymentMatchReader
{
    public static function byTransactions(PDO $pdo, int $supplierId, array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT pm.bank_transaction_id AS tx_id, pm.purchase_invoice_id, pm.amount,
                    COALESCE(NULLIF(pi.vendor_invoice_number,''), pi.varsymbol) AS ref,
                    c.company_name AS vendor_name,
                    UPPER(COALESCE(NULLIF(bt.currency,''), NULLIF(bs.currency,''), 'CZK')) AS currency
               FROM payment_matches pm
               JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id
                                         AND pi.supplier_id = pm.supplier_id
          LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
               JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE pm.supplier_id = ? AND pm.bank_transaction_id IN ($placeholders)
           ORDER BY pm.id"
        );
        $stmt->execute(array_merge([$supplierId], $transactionIds));
        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $matches[(int) $row['tx_id']][] = [
                'purchase_invoice_id' => (int) $row['purchase_invoice_id'],
                'ref' => ($row['ref'] ?? '') !== '' ? (string) $row['ref'] : null,
                'vendor_name' => $row['vendor_name'] !== null ? (string) $row['vendor_name'] : null,
                'amount' => (float) $row['amount'],
                'currency' => (string) $row['currency'],
            ];
        }
        return $matches;
    }
}
