<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollSubmissionInboxRepository
{
    private int $savepointSequence = 0;

    public function __construct(private readonly Connection $db) {}

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
            $savepoint = 'payroll_submission_inbox_' . ++$this->savepointSequence;
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

    public function lockSupplier(int $supplierId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM supplier WHERE id = ? FOR UPDATE',
        );
        $statement->execute([$supplierId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Kandidáti pro derivaci: povinnosti, které ještě mohou mít problém,
     * nebo mají existující nevyřešenou položku inboxu (aby šla dořešit,
     * i když už povinnost mezitím přešla do fulfilled/cancelled).
     *
     * @return list<array{
     *   obligation_id:int,obligation_status:string,agenda_code:string,
     *   subject_type:string,subject_reference:string,period_start:string,
     *   period_end:string,earliest_submission_on:string,due_on:string,
     *   submission_id:?int,submission_status:?string,
     *   inbox_id:?int,inbox_problem_kind:?string,
     *   inbox_escalation_level:?string,inbox_status:?string,
     *   inbox_row_version:?int,inbox_snoozed_until:?string
     * }>
     */
    public function findSyncCandidates(
        int $supplierId,
        string $environment,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.id AS obligation_id,
                    obligation.status AS obligation_status,
                    obligation.agenda_code, obligation.subject_type,
                    obligation.subject_reference, obligation.period_start,
                    obligation.period_end,
                    deadline.earliest_submission_on, deadline.due_on,
                    latest_submission.id AS submission_id,
                    latest_submission.status AS submission_status,
                    inbox.id AS inbox_id,
                    inbox.problem_kind AS inbox_problem_kind,
                    inbox.escalation_level AS inbox_escalation_level,
                    inbox.status AS inbox_status,
                    inbox.row_version AS inbox_row_version,
                    inbox.snoozed_until AS inbox_snoozed_until
               FROM payroll_obligations obligation
               JOIN payroll_submission_deadlines deadline
                 ON deadline.supplier_id = obligation.supplier_id
                AND deadline.environment = obligation.environment
                AND deadline.obligation_id = obligation.id
                AND deadline.deadline_kind = "regular"
               LEFT JOIN (
                    SELECT ranked.*
                      FROM (
                           SELECT submission.id, submission.supplier_id,
                                  submission.environment,
                                  submission.obligation_id,
                                  submission.status,
                                  ROW_NUMBER() OVER (
                                      PARTITION BY submission.supplier_id,
                                                   submission.environment,
                                                   submission.obligation_id
                                      ORDER BY submission.created_at DESC,
                                               submission.id DESC
                                  ) AS row_rank
                             FROM payroll_submissions submission
                            WHERE submission.supplier_id = ?
                              AND submission.environment = ?
                      ) ranked
                     WHERE ranked.row_rank = 1
               ) latest_submission
                 ON latest_submission.supplier_id = obligation.supplier_id
                AND latest_submission.environment = obligation.environment
                AND latest_submission.obligation_id = obligation.id
               LEFT JOIN payroll_submission_inbox_items inbox
                 ON inbox.supplier_id = obligation.supplier_id
                AND inbox.environment = obligation.environment
                AND inbox.obligation_id = obligation.id
              WHERE obligation.supplier_id = ?
                AND obligation.environment = ?
                AND (
                      obligation.status NOT IN ("fulfilled", "cancelled")
                      OR (
                           inbox.id IS NOT NULL
                           AND inbox.status <> "resolved"
                         )
                    )
              ORDER BY obligation.id ASC
              LIMIT 1000',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $supplierId,
            $environment,
        ]);

        $result = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = self::associativeRow($row, 'kandidáta pro inbox');
            $result[] = [
                'obligation_id' => self::integer($row, 'obligation_id'),
                'obligation_status' => self::string(
                    $row,
                    'obligation_status',
                ),
                'agenda_code' => self::string($row, 'agenda_code'),
                'subject_type' => self::string($row, 'subject_type'),
                'subject_reference' => self::string(
                    $row,
                    'subject_reference',
                ),
                'period_start' => self::string($row, 'period_start'),
                'period_end' => self::string($row, 'period_end'),
                'earliest_submission_on' => self::string(
                    $row,
                    'earliest_submission_on',
                ),
                'due_on' => self::string($row, 'due_on'),
                'submission_id' => self::nullableInteger(
                    $row,
                    'submission_id',
                ),
                'submission_status' => self::nullableString(
                    $row,
                    'submission_status',
                ),
                'inbox_id' => self::nullableInteger($row, 'inbox_id'),
                'inbox_problem_kind' => self::nullableString(
                    $row,
                    'inbox_problem_kind',
                ),
                'inbox_escalation_level' => self::nullableString(
                    $row,
                    'inbox_escalation_level',
                ),
                'inbox_status' => self::nullableString(
                    $row,
                    'inbox_status',
                ),
                'inbox_row_version' => self::nullableInteger(
                    $row,
                    'inbox_row_version',
                ),
                'inbox_snoozed_until' => self::nullableString(
                    $row,
                    'inbox_snoozed_until',
                ),
            ];
        }

        return $result;
    }

    public function insertItem(
        int $supplierId,
        string $environment,
        int $obligationId,
        ?int $submissionId,
        string $sourceKeyHash,
        string $problemKind,
        string $escalationLevel,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_inbox_items
                (supplier_id, environment, obligation_id, submission_id,
                 source_key_hash, problem_kind, escalation_level)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $obligationId,
            $submissionId,
            $sourceKeyHash,
            $problemKind,
            $escalationLevel,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function updateProblem(
        int $supplierId,
        int $itemId,
        int $expectedRowVersion,
        ?int $submissionId,
        string $problemKind,
        string $escalationLevel,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_submission_inbox_items
                SET submission_id = ?, problem_kind = ?,
                    escalation_level = ?, row_version = row_version + 1
              WHERE supplier_id = ?
                AND id = ?
                AND row_version = ?
                AND status <> "resolved"',
        );
        $statement->execute([
            $submissionId,
            $problemKind,
            $escalationLevel,
            $supplierId,
            $itemId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new PayrollSubmissionConflictException(
                'Položka inboxu se mezitím změnila.',
            );
        }
    }

    public function resolveItem(
        int $supplierId,
        int $itemId,
        int $expectedRowVersion,
        string $resolvedAt,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_submission_inbox_items
                SET status = "resolved", resolved_at = ?,
                    snoozed_until = NULL, snooze_reason = NULL,
                    snoozed_by = NULL, row_version = row_version + 1
              WHERE supplier_id = ?
                AND id = ?
                AND row_version = ?
                AND status <> "resolved"',
        );
        $statement->execute([
            $resolvedAt,
            $supplierId,
            $itemId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new PayrollSubmissionConflictException(
                'Položka inboxu se mezitím změnila.',
            );
        }
    }

    public function reopenExpiredSnooze(
        int $supplierId,
        int $itemId,
        int $expectedRowVersion,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_submission_inbox_items
                SET status = "open", snoozed_until = NULL,
                    snooze_reason = NULL, snoozed_by = NULL,
                    row_version = row_version + 1
              WHERE supplier_id = ?
                AND id = ?
                AND row_version = ?
                AND status = "snoozed"',
        );
        $statement->execute([$supplierId, $itemId, $expectedRowVersion]);
        if ($statement->rowCount() !== 1) {
            throw new PayrollSubmissionConflictException(
                'Položka inboxu se mezitím změnila.',
            );
        }
    }

    /**
     * @return array{
     *   id:int,supplier_id:int,environment:string,obligation_id:int,
     *   submission_id:?int,problem_kind:string,escalation_level:string,
     *   status:string,row_version:int
     * }|null
     */
    public function lockItem(int $supplierId, int $itemId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, environment, obligation_id,
                    submission_id, problem_kind, escalation_level,
                    status, row_version
               FROM payroll_submission_inbox_items
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $itemId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'položku inboxu');

        return [
            'id' => self::integer($row, 'id'),
            'supplier_id' => self::integer($row, 'supplier_id'),
            'environment' => self::string($row, 'environment'),
            'obligation_id' => self::integer($row, 'obligation_id'),
            'submission_id' => self::nullableInteger(
                $row,
                'submission_id',
            ),
            'problem_kind' => self::string($row, 'problem_kind'),
            'escalation_level' => self::string($row, 'escalation_level'),
            'status' => self::string($row, 'status'),
            'row_version' => self::integer($row, 'row_version'),
        ];
    }

    public function acknowledgeItem(
        int $supplierId,
        int $itemId,
        int $expectedRowVersion,
        int $userId,
        string $acknowledgedAt,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_submission_inbox_items
                SET status = "acknowledged", acknowledged_at = ?,
                    acknowledged_by = ?, snoozed_until = NULL,
                    snooze_reason = NULL, snoozed_by = NULL,
                    row_version = row_version + 1
              WHERE supplier_id = ?
                AND id = ?
                AND row_version = ?
                AND status IN ("open", "acknowledged", "snoozed")',
        );
        $statement->execute([
            $acknowledgedAt,
            $userId,
            $supplierId,
            $itemId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new PayrollSubmissionConflictException(
                'Položka inboxu se mezitím změnila.',
            );
        }
    }

    public function snoozeItem(
        int $supplierId,
        int $itemId,
        int $expectedRowVersion,
        string $snoozedUntil,
        string $reason,
        int $userId,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_submission_inbox_items
                SET status = "snoozed", snoozed_until = ?,
                    snooze_reason = ?, snoozed_by = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ?
                AND id = ?
                AND row_version = ?
                AND status IN ("open", "acknowledged", "snoozed")',
        );
        $statement->execute([
            $snoozedUntil,
            $reason,
            $userId,
            $supplierId,
            $itemId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new PayrollSubmissionConflictException(
                'Položka inboxu se mezitím změnila.',
            );
        }
    }

    /**
     * @return list<array{
     *   id:int,obligation_id:int,submission_id:?int,agenda_code:string,
     *   subject_type:string,subject_reference:string,period_start:string,
     *   period_end:string,due_on:string,problem_kind:string,
     *   escalation_level:string,status:string,snoozed_until:?string,
     *   snooze_reason:?string,acknowledged_at:?string,resolved_at:?string,
     *   row_version:int,created_at:string,updated_at:string
     * }>
     */
    public function listItems(int $supplierId, string $environment): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT inbox.id, inbox.obligation_id, inbox.submission_id,
                    obligation.agenda_code, obligation.subject_type,
                    obligation.subject_reference, obligation.period_start,
                    obligation.period_end,
                    deadline.due_on,
                    inbox.problem_kind, inbox.escalation_level,
                    inbox.status, inbox.snoozed_until, inbox.snooze_reason,
                    inbox.acknowledged_at, inbox.resolved_at,
                    inbox.row_version, inbox.created_at, inbox.updated_at
               FROM payroll_submission_inbox_items inbox
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = inbox.supplier_id
                AND obligation.environment = inbox.environment
                AND obligation.id = inbox.obligation_id
               JOIN payroll_submission_deadlines deadline
                 ON deadline.supplier_id = obligation.supplier_id
                AND deadline.environment = obligation.environment
                AND deadline.obligation_id = obligation.id
                AND deadline.deadline_kind = "regular"
              WHERE inbox.supplier_id = ? AND inbox.environment = ?
              ORDER BY FIELD(inbox.status, "open", "snoozed", "acknowledged", "resolved") ASC,
                       FIELD(inbox.escalation_level, "overdue", "due_today", "due_soon") ASC,
                       deadline.due_on ASC,
                       inbox.id ASC',
        );
        $statement->execute([$supplierId, $environment]);

        $result = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = self::associativeRow($row, 'položku inboxu');
            $result[] = [
                'id' => self::integer($row, 'id'),
                'obligation_id' => self::integer($row, 'obligation_id'),
                'submission_id' => self::nullableInteger(
                    $row,
                    'submission_id',
                ),
                'agenda_code' => self::string($row, 'agenda_code'),
                'subject_type' => self::string($row, 'subject_type'),
                'subject_reference' => self::string(
                    $row,
                    'subject_reference',
                ),
                'period_start' => self::string($row, 'period_start'),
                'period_end' => self::string($row, 'period_end'),
                'due_on' => self::string($row, 'due_on'),
                'problem_kind' => self::string($row, 'problem_kind'),
                'escalation_level' => self::string(
                    $row,
                    'escalation_level',
                ),
                'status' => self::string($row, 'status'),
                'snoozed_until' => self::nullableString(
                    $row,
                    'snoozed_until',
                ),
                'snooze_reason' => self::nullableString(
                    $row,
                    'snooze_reason',
                ),
                'acknowledged_at' => self::nullableString(
                    $row,
                    'acknowledged_at',
                ),
                'resolved_at' => self::nullableString(
                    $row,
                    'resolved_at',
                ),
                'row_version' => self::integer($row, 'row_version'),
                'created_at' => self::string($row, 'created_at'),
                'updated_at' => self::string($row, 'updated_at'),
            ];
        }

        return $result;
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
