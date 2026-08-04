<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollPaymentBatchRepository
{
    private int $savepointSequence = 0;

    public function __construct(private readonly Connection $db) {}

    public function lockSupplier(int $supplierId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM supplier WHERE id = ? FOR UPDATE',
        );
        $statement->execute([$supplierId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'payroll_payment_batch_'
                . ++$this->savepointSequence;
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            } elseif ($savepoint !== null) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    /**
     * @return array{
     *   batch_id:int,
     *   batch_reference:string,
     *   channel:string,
     *   export_format:string,
     *   planned_payment_date:string,
     *   currency_code:string,
     *   declared_total_minor:int,
     *   declared_item_count:int,
     *   snapshot_hash:string
     * }|null
     */
    public function findByIdempotencyForUpdate(
        int $supplierId,
        string $idempotencyKeyHash,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, batch_reference, channel, export_format,
                    planned_payment_date, currency_code,
                    declared_total_minor, declared_item_count, snapshot_hash
               FROM payroll_payment_batches
              WHERE supplier_id = ? AND idempotency_key_hash = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $idempotencyKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'platební dávku');

        return [
            'batch_id' => self::integer($row, 'id'),
            'batch_reference' => self::string($row, 'batch_reference'),
            'channel' => self::string($row, 'channel'),
            'export_format' => self::string($row, 'export_format'),
            'planned_payment_date' => self::string(
                $row,
                'planned_payment_date',
            ),
            'currency_code' => self::string($row, 'currency_code'),
            'declared_total_minor' => self::integer(
                $row,
                'declared_total_minor',
            ),
            'declared_item_count' => self::integer(
                $row,
                'declared_item_count',
            ),
            'snapshot_hash' => self::hash($row, 'snapshot_hash'),
        ];
    }

    /**
     * @param non-empty-list<int> $liabilityIds
     * @return list<array{
     *   id:int,
     *   revision_id:int,
     *   employee_id:?int,
     *   liability_reference:string,
     *   liability_kind:string,
     *   direction:string,
     *   recipient_reference:string,
     *   due_on:string,
     *   currency_code:string,
     *   amount_minor:int,
     *   allocated_minor:int,
     *   source_snapshot_json:string,
     *   source_snapshot_hash:string
     * }>
     */
    public function lockLiabilities(
        int $supplierId,
        array $liabilityIds,
    ): array {
        $placeholders = implode(
            ',',
            array_fill(0, count($liabilityIds), '?'),
        );
        $statement = $this->db->pdo()->prepare(
            'SELECT liability.id, liability.revision_id,
                    liability.employee_id, liability.liability_reference,
                    liability.liability_kind, liability.direction,
                    liability.recipient_reference, liability.due_on,
                    liability.currency_code, liability.amount_minor,
                    liability.source_snapshot_json,
                    liability.source_snapshot_hash,
                    (
                      SELECT COALESCE(SUM(allocation.amount_minor), 0)
                        FROM payroll_payment_allocations allocation
                       WHERE allocation.supplier_id = liability.supplier_id
                         AND allocation.liability_id = liability.id
                    ) AS allocated_minor
               FROM payroll_payment_liabilities liability
              WHERE liability.supplier_id = ?
                AND liability.id IN (' . $placeholders . ')
              ORDER BY liability.id
              FOR UPDATE',
        );
        $statement->execute([$supplierId, ...$liabilityIds]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::associativeRow($row, 'platební závazek');
            $result[] = [
                'id' => self::integer($row, 'id'),
                'revision_id' => self::integer($row, 'revision_id'),
                'employee_id' => self::nullableInteger(
                    $row,
                    'employee_id',
                ),
                'liability_reference' => self::string(
                    $row,
                    'liability_reference',
                ),
                'liability_kind' => self::string(
                    $row,
                    'liability_kind',
                ),
                'direction' => self::string($row, 'direction'),
                'recipient_reference' => self::string(
                    $row,
                    'recipient_reference',
                ),
                'due_on' => self::string($row, 'due_on'),
                'currency_code' => self::string(
                    $row,
                    'currency_code',
                ),
                'amount_minor' => self::integer($row, 'amount_minor'),
                'allocated_minor' => self::integer(
                    $row,
                    'allocated_minor',
                ),
                'source_snapshot_json' => self::string(
                    $row,
                    'source_snapshot_json',
                ),
                'source_snapshot_hash' => self::hash(
                    $row,
                    'source_snapshot_hash',
                ),
            ];
        }

        return $result;
    }

    /**
     * @return array{
     *   id:int,
     *   employee_id:int,
     *   bank_account_ciphertext:string,
     *   bank_account_hash:string,
     *   effective_from:string,
     *   effective_to:?string,
     *   is_active:bool,
     *   row_version:int,
     *   verification_source:?string,
     *   verified_on:?string,
     *   verified_by:?int
     * }|null
     */
    public function lockPersonAccount(
        int $supplierId,
        int $employeeId,
        int $accountId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, bank_account_ciphertext,
                    LOWER(HEX(bank_account_hash)) AS bank_account_hash,
                    effective_from, effective_to, is_active, row_version,
                    verification_source, verified_on, verified_by
               FROM payroll_person_accounts
              WHERE supplier_id = ? AND employee_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $employeeId, $accountId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'účet zaměstnance');

        return [
            'id' => self::integer($row, 'id'),
            'employee_id' => self::integer($row, 'employee_id'),
            'bank_account_ciphertext' => self::string(
                $row,
                'bank_account_ciphertext',
            ),
            'bank_account_hash' => self::hash(
                $row,
                'bank_account_hash',
            ),
            'effective_from' => self::string($row, 'effective_from'),
            'effective_to' => self::nullableString(
                $row,
                'effective_to',
            ),
            'is_active' => self::boolean($row, 'is_active'),
            'row_version' => self::integer($row, 'row_version'),
            'verification_source' => self::nullableString(
                $row,
                'verification_source',
            ),
            'verified_on' => self::nullableString($row, 'verified_on'),
            'verified_by' => self::nullableInteger($row, 'verified_by'),
        ];
    }

    /**
     * @return array{
     *   id:int,
     *   code:string,
     *   is_active:bool,
     *   account_number:?string,
     *   bank_code:?string,
     *   iban:?string,
     *   bic:?string
     * }|null
     */
    public function lockPayerCurrency(
        int $supplierId,
        int $currencyId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, code, is_active, account_number, bank_code,
                    iban, bic
               FROM currencies
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $currencyId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'účet plátce');

        return [
            'id' => self::integer($row, 'id'),
            'code' => self::string($row, 'code'),
            'is_active' => self::boolean($row, 'is_active'),
            'account_number' => self::nullableString(
                $row,
                'account_number',
            ),
            'bank_code' => self::nullableString($row, 'bank_code'),
            'iban' => self::nullableString($row, 'iban'),
            'bic' => self::nullableString($row, 'bic'),
        ];
    }

    public function lockSupplierName(int $supplierId): ?string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COALESCE(NULLIF(display_name, ""), company_name)
               FROM supplier
              WHERE id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId]);
        $value = $statement->fetchColumn();

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    public function lockEmployeeName(
        int $supplierId,
        int $employeeId,
    ): ?string {
        $statement = $this->db->pdo()->prepare(
            'SELECT full_name
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $employeeId]);
        $value = $statement->fetchColumn();

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    public function insertBatch(
        int $supplierId,
        string $batchReference,
        string $channel,
        string $exportFormat,
        string $plannedPaymentDate,
        string $currencyCode,
        string $payerReference,
        int $declaredTotalMinor,
        int $declaredItemCount,
        string $snapshotCiphertext,
        string $snapshotHash,
        string $idempotencyKeyHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor,
                 declared_item_count, snapshot_ciphertext, snapshot_hash,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, "outgoing", ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $batchReference,
            $channel,
            $exportFormat,
            $plannedPaymentDate,
            $currencyCode,
            $payerReference,
            $declaredTotalMinor,
            $declaredItemCount,
            $snapshotCiphertext,
            $snapshotHash,
            $idempotencyKeyHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertItem(
        int $supplierId,
        int $batchId,
        string $itemReference,
        string $recipientReference,
        int $amountMinor,
        string $instructionCiphertext,
        string $instructionHash,
        string $idempotencyKeyHash,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference,
                 recipient_reference, amount_minor,
                 instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $batchId,
            $itemReference,
            $recipientReference,
            $amountMinor,
            $instructionCiphertext,
            $instructionHash,
            $idempotencyKeyHash,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertAllocation(
        int $supplierId,
        int $itemId,
        int $liabilityId,
        int $amountMinor,
        string $idempotencyKeyHash,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $itemId,
            $liabilityId,
            $amountMinor,
            $idempotencyKeyHash,
        ]);
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není celé číslo.",
            );
        }
        $normalized = filter_var($value, FILTER_VALIDATE_INT);
        if ($normalized === false) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není celé číslo.",
            );
        }

        return $normalized;
    }

    /** @param array<string,mixed> $row */
    private static function nullableInteger(
        array $row,
        string $field,
    ): ?int {
        return ($row[$field] ?? null) === null
            ? null
            : self::integer($row, $field);
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není neprázdný text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(
        array $row,
        string $field,
    ): ?string {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není text.",
            );
        }

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $row */
    private static function boolean(array $row, string $field): bool
    {
        $value = self::integer($row, $field);
        if ($value !== 0 && $value !== 1) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není boolean.",
            );
        }

        return $value === 1;
    }

    /** @param array<string,mixed> $row */
    private static function hash(array $row, string $field): string
    {
        $value = self::string($row, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není SHA-256.",
            );
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private static function associativeRow(
        mixed $value,
        string $context,
    ): array {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatný {$context}.",
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "Databázový {$context} nemá textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
