<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollPaymentReconciliationQueryService
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed> */
    public function forPeriod(int $supplierId, string $period): array
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma párování plateb musí být kladné číslo.',
            );
        }
        [$from, $to] = $this->periodRange($period);
        $evidenceRange = $this->evidenceRange(
            $supplierId,
            $from,
            $to,
        );

        return [
            'period' => $period,
            'allocations' => $this->allocations(
                $supplierId,
                $from,
                $to,
            ),
            'matches' => $this->matches($supplierId, $from, $to),
            'bank_evidence' => $evidenceRange === null
                ? []
                : $this->bankEvidence(
                    $supplierId,
                    $evidenceRange[0],
                    $evidenceRange[1],
                ),
            'cash_evidence' => $evidenceRange === null
                ? []
                : $this->cashEvidence(
                    $supplierId,
                    $evidenceRange[0],
                    $evidenceRange[1],
                ),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function allocations(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT allocation.id, allocation.item_id,
                    payment_item.item_reference,
                    payment_batch.id AS batch_id,
                    payment_batch.batch_reference,
                    payment_batch.channel,
                    payment_batch.planned_payment_date,
                    liability.id AS liability_id,
                    liability.liability_kind,
                    liability.direction,
                    liability.currency_code,
                    employee.full_name AS employee_name,
                    allocation.amount_minor,
                    COALESCE(SUM(payment_match.amount_minor), 0)
                      AS settled_minor
               FROM payroll_payment_allocations allocation
               JOIN payroll_payment_items payment_item
                 ON payment_item.supplier_id = allocation.supplier_id
                AND payment_item.id = allocation.item_id
               JOIN payroll_payment_batches payment_batch
                 ON payment_batch.supplier_id = payment_item.supplier_id
                AND payment_batch.id = payment_item.batch_id
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = allocation.supplier_id
                AND liability.id = allocation.liability_id
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
               LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = liability.supplier_id
                AND employee.id = liability.employee_id
               LEFT JOIN payroll_payment_matches payment_match
                 ON payment_match.supplier_id = allocation.supplier_id
                AND payment_match.allocation_id = allocation.id
              WHERE allocation.supplier_id = ?
                AND run.period_start >= ?
                AND run.period_start < ?
              GROUP BY allocation.id, allocation.item_id,
                       payment_item.item_reference, payment_batch.id,
                       payment_batch.batch_reference,
                       payment_batch.channel,
                       payment_batch.planned_payment_date,
                       liability.id, liability.liability_kind,
                       liability.direction, liability.currency_code,
                       employee.full_name, allocation.amount_minor
              ORDER BY payment_batch.planned_payment_date,
                       employee.full_name, allocation.id',
        );
        $statement->execute([$supplierId, $from, $to]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rawRow) {
            $row = self::row($rawRow, 'platební alokaci');
            $amount = self::integer($row, 'amount_minor');
            $settled = self::integer($row, 'settled_minor');
            if ($amount <= 0 || $settled < 0 || $settled > $amount) {
                throw new \UnexpectedValueException(
                    'Součet platební alokace je mimo povolené meze.',
                );
            }
            $result[] = [
                'id' => self::integer($row, 'id'),
                'item_id' => self::integer($row, 'item_id'),
                'item_reference' => self::text(
                    $row,
                    'item_reference',
                ),
                'batch_id' => self::integer($row, 'batch_id'),
                'batch_reference' => self::text(
                    $row,
                    'batch_reference',
                ),
                'channel' => self::enum(
                    $row,
                    'channel',
                    ['bank', 'cash'],
                ),
                'planned_payment_date' => self::date(
                    $row,
                    'planned_payment_date',
                ),
                'liability_id' => self::integer($row, 'liability_id'),
                'liability_kind' => self::text(
                    $row,
                    'liability_kind',
                ),
                'direction' => self::enum(
                    $row,
                    'direction',
                    ['outgoing', 'incoming'],
                ),
                'currency_code' => self::currency(
                    $row,
                    'currency_code',
                ),
                'employee_name' => self::nullableText(
                    $row,
                    'employee_name',
                ),
                'amount_minor' => $amount,
                'settled_minor' => $settled,
                'remaining_minor' => $amount - $settled,
            ];
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function matches(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT payment_match.id, payment_match.allocation_id,
                    payment_match.event_kind,
                    payment_match.source_match_id,
                    payment_match.amount_minor,
                    payment_match.bank_statement_id,
                    payment_match.bank_transaction_id,
                    payment_match.cash_document_id,
                    payment_match.actual_payment_date,
                    payment_match.evidence_amount_minor,
                    payment_match.evidence_currency_code,
                    payment_match.evidence_fact_hash,
                    payment_match.created_at,
                    payment_batch.batch_reference,
                    liability.liability_kind,
                    employee.full_name AS employee_name,
                    CASE
                      WHEN payment_match.event_kind = "matched"
                      THEN payment_match.amount_minor + COALESCE((
                        SELECT SUM(reversal.amount_minor)
                          FROM payroll_payment_matches reversal
                         WHERE reversal.supplier_id =
                               payment_match.supplier_id
                           AND reversal.source_match_id = payment_match.id
                           AND reversal.event_kind = "reversed"
                      ), 0)
                      ELSE 0
                    END AS reversible_minor
               FROM payroll_payment_matches payment_match
               JOIN payroll_payment_allocations allocation
                 ON allocation.supplier_id = payment_match.supplier_id
                AND allocation.id = payment_match.allocation_id
               JOIN payroll_payment_items payment_item
                 ON payment_item.supplier_id = allocation.supplier_id
                AND payment_item.id = allocation.item_id
               JOIN payroll_payment_batches payment_batch
                 ON payment_batch.supplier_id = payment_item.supplier_id
                AND payment_batch.id = payment_item.batch_id
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = allocation.supplier_id
                AND liability.id = allocation.liability_id
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
               LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = liability.supplier_id
                AND employee.id = liability.employee_id
              WHERE payment_match.supplier_id = ?
                AND run.period_start >= ?
                AND run.period_start < ?
              ORDER BY payment_match.actual_payment_date DESC,
                       payment_match.id DESC',
        );
        $statement->execute([$supplierId, $from, $to]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rawRow) {
            $row = self::row($rawRow, 'spárování platby');
            $eventKind = self::enum(
                $row,
                'event_kind',
                ['matched', 'reversed'],
            );
            $amount = self::integer($row, 'amount_minor');
            $reversible = self::integer($row, 'reversible_minor');
            if (($eventKind === 'matched' && $amount <= 0)
                || ($eventKind === 'reversed' && $amount >= 0)
                || $reversible < 0
            ) {
                throw new \UnexpectedValueException(
                    'Platební událost má neplatné částky.',
                );
            }
            $bankTransactionId = self::nullableInteger(
                $row,
                'bank_transaction_id',
            );
            $result[] = [
                'id' => self::integer($row, 'id'),
                'allocation_id' => self::integer(
                    $row,
                    'allocation_id',
                ),
                'event_kind' => $eventKind,
                'source_match_id' => self::nullableInteger(
                    $row,
                    'source_match_id',
                ),
                'amount_minor' => $amount,
                'evidence_kind' => $bankTransactionId === null
                    ? 'cash'
                    : 'bank',
                'bank_statement_id' => self::nullableInteger(
                    $row,
                    'bank_statement_id',
                ),
                'bank_transaction_id' => $bankTransactionId,
                'cash_document_id' => self::nullableInteger(
                    $row,
                    'cash_document_id',
                ),
                'actual_payment_date' => self::date(
                    $row,
                    'actual_payment_date',
                ),
                'evidence_amount_minor' => self::integer(
                    $row,
                    'evidence_amount_minor',
                ),
                'evidence_currency_code' => self::currency(
                    $row,
                    'evidence_currency_code',
                ),
                'evidence_fact_hash' => self::hash(
                    $row,
                    'evidence_fact_hash',
                ),
                'batch_reference' => self::text(
                    $row,
                    'batch_reference',
                ),
                'liability_kind' => self::text(
                    $row,
                    'liability_kind',
                ),
                'employee_name' => self::nullableText(
                    $row,
                    'employee_name',
                ),
                'reversible_minor' => $reversible,
                'created_at' => self::text($row, 'created_at'),
            ];
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function bankEvidence(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT bank_statement.id AS bank_statement_id,
                    bank_transaction.id AS bank_transaction_id,
                    bank_transaction.posted_at,
                    CAST(ROUND(ABS(bank_transaction.amount) * 100)
                      AS SIGNED) AS amount_minor,
                    COALESCE(bank_transaction.currency,
                             bank_statement.currency) AS currency_code,
                    CASE WHEN bank_transaction.amount < 0
                         THEN "outgoing" ELSE "incoming" END AS direction,
                    bank_transaction.description,
                    COALESCE(SUM(
                      CASE WHEN payroll_match.event_kind = "matched"
                           THEN ABS(payroll_match.amount_minor) ELSE 0 END
                    ), 0) AS matched_minor,
                    COALESCE(SUM(
                      CASE WHEN payroll_match.event_kind = "reversed"
                           THEN ABS(payroll_match.amount_minor) ELSE 0 END
                    ), 0) AS reversed_minor
               FROM bank_statements bank_statement
               JOIN bank_transactions bank_transaction
                 ON bank_transaction.statement_id = bank_statement.id
               LEFT JOIN payroll_payment_matches payroll_match
                 ON payroll_match.supplier_id = bank_statement.supplier_id
                AND payroll_match.bank_statement_id = bank_statement.id
                AND payroll_match.bank_transaction_id =
                    bank_transaction.id
              WHERE bank_statement.supplier_id = ?
                AND bank_transaction.posted_at >= ?
                AND bank_transaction.posted_at < ?
                AND bank_transaction.amount <> 0
                AND bank_transaction.matched_invoice_id IS NULL
                AND bank_transaction.match_status = "unmatched"
                AND NOT EXISTS (
                  SELECT 1 FROM invoice_payments invoice_payment
                   WHERE invoice_payment.bank_transaction_id =
                         bank_transaction.id
                )
                AND NOT EXISTS (
                  SELECT 1 FROM payment_matches payment_match
                   WHERE payment_match.bank_transaction_id =
                         bank_transaction.id
                )
              GROUP BY bank_statement.id, bank_transaction.id,
                       bank_transaction.posted_at,
                       bank_transaction.amount,
                       bank_transaction.currency,
                       bank_statement.currency,
                       bank_transaction.description
              ORDER BY bank_transaction.posted_at DESC,
                       bank_transaction.id DESC',
        );
        $statement->execute([$supplierId, $from, $to]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rawRow) {
            $row = self::row($rawRow, 'bankovní důkaz');
            $amount = self::integer($row, 'amount_minor');
            $matched = self::integer($row, 'matched_minor');
            $reversed = self::integer($row, 'reversed_minor');
            if ($amount <= 0 || $matched < 0 || $matched > $amount
                || $reversed < 0 || $reversed > $amount
            ) {
                throw new \UnexpectedValueException(
                    'Využití bankovního důkazu je mimo povolené meze.',
                );
            }
            $result[] = [
                'kind' => 'bank',
                'bank_statement_id' => self::integer(
                    $row,
                    'bank_statement_id',
                ),
                'bank_transaction_id' => self::integer(
                    $row,
                    'bank_transaction_id',
                ),
                'cash_document_id' => null,
                'date' => self::date($row, 'posted_at'),
                'amount_minor' => $amount,
                'currency_code' => self::currency(
                    $row,
                    'currency_code',
                ),
                'direction' => self::enum(
                    $row,
                    'direction',
                    ['outgoing', 'incoming'],
                ),
                'description' => self::nullableText(
                    $row,
                    'description',
                ),
                'available_match_minor' => $amount - $matched,
                'available_reversal_minor' => $amount - $reversed,
            ];
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function cashEvidence(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT cash_document.id AS cash_document_id,
                    cash_document.issue_date,
                    CAST(ROUND(ABS(cash_document.total_amount) * 100)
                      AS SIGNED) AS amount_minor,
                    cash_document.currency_code,
                    CASE WHEN cash_document.doc_type = "out"
                         THEN "outgoing" ELSE "incoming" END AS direction,
                    cash_document.status, cash_document.doc_number,
                    cash_document.description,
                    COALESCE(SUM(
                      CASE WHEN payroll_match.event_kind = "matched"
                           THEN ABS(payroll_match.amount_minor) ELSE 0 END
                    ), 0) AS matched_minor,
                    COALESCE(SUM(
                      CASE WHEN payroll_match.event_kind = "reversed"
                           THEN ABS(payroll_match.amount_minor) ELSE 0 END
                    ), 0) AS reversed_minor
               FROM cash_documents cash_document
               LEFT JOIN payroll_payment_matches payroll_match
                 ON payroll_match.supplier_id = cash_document.supplier_id
                AND payroll_match.cash_document_id = cash_document.id
              WHERE cash_document.supplier_id = ?
                AND cash_document.issue_date >= ?
                AND cash_document.issue_date < ?
                AND cash_document.total_amount <> 0
                AND cash_document.status IN ("posted", "reversed")
                AND cash_document.purpose = "other"
                AND cash_document.invoice_id IS NULL
                AND cash_document.purchase_invoice_id IS NULL
                AND cash_document.invoice_payment_id IS NULL
              GROUP BY cash_document.id, cash_document.issue_date,
                       cash_document.total_amount,
                       cash_document.currency_code,
                       cash_document.doc_type, cash_document.status,
                       cash_document.doc_number,
                       cash_document.description
              ORDER BY cash_document.issue_date DESC,
                       cash_document.id DESC',
        );
        $statement->execute([$supplierId, $from, $to]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rawRow) {
            $row = self::row($rawRow, 'pokladní důkaz');
            $amount = self::integer($row, 'amount_minor');
            $matched = self::integer($row, 'matched_minor');
            $reversed = self::integer($row, 'reversed_minor');
            if ($amount <= 0 || $matched < 0 || $matched > $amount
                || $reversed < 0 || $reversed > $amount
            ) {
                throw new \UnexpectedValueException(
                    'Využití pokladního důkazu je mimo povolené meze.',
                );
            }
            $result[] = [
                'kind' => 'cash',
                'bank_statement_id' => null,
                'bank_transaction_id' => null,
                'cash_document_id' => self::integer(
                    $row,
                    'cash_document_id',
                ),
                'date' => self::date($row, 'issue_date'),
                'amount_minor' => $amount,
                'currency_code' => self::currency(
                    $row,
                    'currency_code',
                ),
                'direction' => self::enum(
                    $row,
                    'direction',
                    ['outgoing', 'incoming'],
                ),
                'status' => self::enum(
                    $row,
                    'status',
                    ['posted', 'reversed'],
                ),
                'reference' => self::nullableText(
                    $row,
                    'doc_number',
                ),
                'description' => self::nullableText(
                    $row,
                    'description',
                ),
                'available_match_minor' => $amount - $matched,
                'available_reversal_minor' => $amount - $reversed,
            ];
        }

        return $result;
    }

    /** @return array{string,string} */
    private function periodRange(string $period): array
    {
        if (preg_match(
            '/^(20[0-9]{2}|21[0-9]{2})-(0[1-9]|1[0-2])$/D',
            $period,
        ) !== 1) {
            throw new \InvalidArgumentException(
                'Mzdové období musí mít tvar RRRR-MM.',
            );
        }
        $from = new \DateTimeImmutable($period . '-01');
        return [
            $from->format('Y-m-d'),
            $from->modify('first day of next month')->format('Y-m-d'),
        ];
    }

    /**
     * @return array{string,string}|null
     */
    private function evidenceRange(
        int $supplierId,
        string $periodFrom,
        string $periodTo,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT MIN(liability.due_on) AS evidence_from
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE liability.supplier_id = ?
                AND run.period_start >= ?
                AND run.period_start < ?',
        );
        $statement->execute([$supplierId, $periodFrom, $periodTo]);
        $row = self::row(
            $statement->fetch(PDO::FETCH_ASSOC),
            'rozsah platebních důkazů',
        );
        $earliestDueOn = self::nullableText($row, 'evidence_from');
        if ($earliestDueOn === null) {
            return null;
        }
        $from = new \DateTimeImmutable(
            min($periodFrom, $earliestDueOn),
        );
        $to = new \DateTimeImmutable('tomorrow');
        if ($to <= $from) {
            return null;
        }

        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value, string $context): array
    {
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

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není celé číslo.",
            );
        }
        $result = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($result)) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není celé číslo.",
            );
        }

        return $result;
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
    private static function text(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není neprázdný text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableText(
        array $row,
        string $field,
    ): ?string {
        $value = $row[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function date(array $row, string $field): string
    {
        $value = self::text($row, $field);
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není datum.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function currency(array $row, string $field): string
    {
        $value = self::text($row, $field);
        if (preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není měna.",
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $row
     * @param non-empty-list<string> $allowed
     */
    private static function enum(
        array $row,
        string $field,
        array $allowed,
    ): string {
        $value = self::text($row, $field);
        if (!in_array($value, $allowed, true)) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není povolená.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function hash(array $row, string $field): string
    {
        $value = self::text($row, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není SHA-256.",
            );
        }

        return $value;
    }
}
