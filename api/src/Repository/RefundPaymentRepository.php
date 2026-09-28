<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\RefundDocument;
use PDO;

/**
 * Vystavené doklady k vyplacení ({@see RefundDocument}) jako položky platebního
 * příkazu: vratka se posílá odběrateli (klient dokladu), jen v CZK a jen když se
 * nevyplácí hotově. Tenant scope vždy přes `supplier_id`.
 */
final class RefundPaymentRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listCandidates(int $supplierId, int $limit = 500): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $this->columns() . '
               FROM invoices i
               JOIN clients c      ON c.id = i.client_id
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND ' . RefundDocument::openRefundSql('i') . "
                AND i.payment_method <> 'cash'
                AND cur.code = 'CZK'
              ORDER BY i.due_date ASC, i.id ASC
              LIMIT " . max(1, $limit)
        );
        $stmt->execute([$supplierId]);
        return array_map([$this, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $this->columns() . '
               FROM invoices i
               JOIN clients c      ON c.id = i.client_id
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ? AND i.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** @param list<int> $ids */
    public function markPaymentOrdered(array $ids, int $supplierId): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return;
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $this->db->pdo()->prepare(
            "UPDATE invoices SET payment_ordered_at = NOW() WHERE supplier_id = ? AND id IN ($place)"
        )->execute(array_merge([$supplierId], $ids));
    }

    /**
     * Po smazání příkazu vrátí doklady mezi nepředané, pokud už nejsou v jiném příkazu.
     *
     * @param list<int> $ids
     */
    public function clearPaymentOrdered(array $ids, int $supplierId): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return;
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $this->db->pdo()->prepare(
            "UPDATE invoices i SET i.payment_ordered_at = NULL
              WHERE i.supplier_id = ? AND i.id IN ($place)
                AND NOT EXISTS (SELECT 1 FROM payment_order_items poi
                                  JOIN payment_orders po ON po.id = poi.payment_order_id
                                 WHERE poi.invoice_id = i.id AND po.supplier_id = i.supplier_id)"
        )->execute(array_merge([$supplierId], $ids));
    }

    private function columns(): string
    {
        return 'i.id, i.invoice_type, i.status, i.varsymbol, i.issue_date, i.due_date,
                i.total_with_vat, i.amount_to_pay, i.payment_method, i.payment_ordered_at,
                i.client_id, c.company_name AS client_company_name, c.dic AS client_dic,
                cur.code AS currency, cur.symbol AS currency_symbol';
    }

    /** @param array<string,mixed> $row */
    private function cast(array $row): array
    {
        $row['id']             = (int) $row['id'];
        $row['client_id']      = (int) $row['client_id'];
        $row['total_with_vat'] = (float) $row['total_with_vat'];
        $row['amount_to_pay']  = (float) $row['amount_to_pay'];
        return $row;
    }
}
