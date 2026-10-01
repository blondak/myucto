<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Zahození mzdové platební dávky (`payroll_payment_batch_discards`).
 *
 * Volá se uvnitř transakce, kterou vede {@see PayrollPaymentBatchRepository}.
 */
final class PayrollPaymentBatchDiscardRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   id:int,
     *   channel:string,
     *   export_format:string,
     *   payer_reference:string,
     *   planned_payment_date:string,
     *   currency_code:string
     * }|null
     */
    public function lockBatch(int $supplierId, int $batchId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, channel, export_format, payer_reference,
                    planned_payment_date, currency_code
               FROM payroll_payment_batches
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $batchId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'channel' => (string) $row['channel'],
            'export_format' => (string) $row['export_format'],
            'payer_reference' => (string) $row['payer_reference'],
            'planned_payment_date' => (string) $row['planned_payment_date'],
            'currency_code' => (string) $row['currency_code'],
        ];
    }

    /**
     * @return array{handover_state:string,discarded_at:string}|null
     */
    public function findDiscard(int $supplierId, int $batchId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT handover_state, discarded_at
               FROM payroll_payment_batch_discards
              WHERE supplier_id = ? AND batch_id = ?',
        );
        $statement->execute([$supplierId, $batchId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'handover_state' => (string) $row['handover_state'],
            'discarded_at' => (string) $row['discarded_at'],
        ];
    }

    /** Je k dávce doložená (spárovaná) úhrada? */
    public function hasSettlement(int $supplierId, int $batchId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT EXISTS (
               SELECT 1
                 FROM payroll_payment_items item
                 JOIN payroll_payment_allocations allocation
                   ON allocation.supplier_id = item.supplier_id
                  AND allocation.item_id = item.id
                 JOIN payroll_payment_matches payment_match
                   ON payment_match.supplier_id = allocation.supplier_id
                  AND payment_match.allocation_id = allocation.id
                WHERE item.supplier_id = ? AND item.batch_id = ?
             )',
        );
        $statement->execute([$supplierId, $batchId]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * Jak daleko se dávka dostala ven z aplikace.
     *
     * `submitted` = evidovaný pokus o předání bance přes napojení účtu
     * (i neúspěšný - jeho výsledek nemusí být jistý), `downloaded` = některý
     * soubor dávky si uživatel stáhl, `none` = dávka aplikaci neopustila.
     *
     * @return 'none'|'downloaded'|'submitted'
     */
    public function handoverState(int $supplierId, int $batchId): string
    {
        $submitted = $this->db->pdo()->prepare(
            'SELECT EXISTS (
               SELECT 1 FROM bank_payment_order_submissions
                WHERE supplier_id = ? AND payroll_batch_id = ?
             )',
        );
        $submitted->execute([$supplierId, $batchId]);
        if ((bool) $submitted->fetchColumn()) {
            return 'submitted';
        }
        $downloaded = $this->db->pdo()->prepare(
            'SELECT EXISTS (
               SELECT 1
                 FROM payroll_payment_exports export
                 JOIN payroll_payment_export_download_grants grant_row
                   ON grant_row.supplier_id = export.supplier_id
                  AND grant_row.export_id = export.id
                WHERE export.supplier_id = ? AND export.batch_id = ?
                  AND grant_row.used_at IS NOT NULL
             )',
        );
        $downloaded->execute([$supplierId, $batchId]);

        return (bool) $downloaded->fetchColumn() ? 'downloaded' : 'none';
    }

    /**
     * Požadavky, ze kterých dávka vznikla - pro sestavení náhradní dávky.
     *
     * @return non-empty-list<array{liability_id:int,amount_minor:int}>
     */
    public function batchRequests(int $supplierId, int $batchId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT allocation.liability_id,
                    SUM(allocation.amount_minor) AS amount_minor
               FROM payroll_payment_items item
               JOIN payroll_payment_allocations allocation
                 ON allocation.supplier_id = item.supplier_id
                AND allocation.item_id = item.id
              WHERE item.supplier_id = ? AND item.batch_id = ?
              GROUP BY allocation.liability_id
              ORDER BY allocation.liability_id',
        );
        $statement->execute([$supplierId, $batchId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'liability_id' => (int) $row['liability_id'],
                'amount_minor' => (int) $row['amount_minor'],
            ];
        }
        if ($result === []) {
            throw new \UnexpectedValueException(
                'Platební dávka nemá žádné alokace.',
            );
        }

        return $result;
    }

    public function insert(
        int $supplierId,
        int $batchId,
        string $handoverState,
        bool $bankCancellationConfirmed,
        ?int $userId,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_batch_discards
                (supplier_id, batch_id, handover_state,
                 bank_cancellation_confirmed, discarded_by)
             VALUES (?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $batchId,
            $handoverState,
            $bankCancellationConfirmed ? 1 : 0,
            $userId,
        ]);
    }
}
