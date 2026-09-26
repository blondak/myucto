<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Oznámení soudu / exekutorovi o skončení pracovního poměru povinného
 * (§ 295 odst. 2 o. s. ř.). Obsah se zmrazí při vystavení, PDF se z něj
 * vykresluje deterministicky; mění se jen údaj o odeslání.
 */
final class PayrollEnforcementTerminationNoticeRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForCase(int $supplierId, int $caseId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, case_id, employee_id, employment_id, revision_no,
                    employment_ended_on, due_on, new_payer_name,
                    new_payer_reference, snapshot_hash, sent_on, sent_channel,
                    outbox_id, created_by, sent_by, created_at
               FROM payroll_enforcement_termination_notices
              WHERE supplier_id = ? AND case_id = ?
              ORDER BY revision_no DESC'
        );
        $stmt->execute([$supplierId, $caseId]);

        return array_map(self::cast(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $noticeId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_enforcement_termination_notices
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $noticeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::cast($row) : null;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function insert(
        int $supplierId,
        int $caseId,
        int $employeeId,
        ?int $employmentId,
        string $endedOn,
        string $dueOn,
        ?string $newPayerName,
        ?string $newPayerReference,
        string $snapshotJson,
        ?int $userId,
    ): array {
        $pdo = $this->db->pdo();
        $next = $pdo->prepare(
            'SELECT COALESCE(MAX(revision_no), 0) + 1
               FROM payroll_enforcement_termination_notices
              WHERE supplier_id = ? AND case_id = ? FOR UPDATE'
        );
        $next->execute([$supplierId, $caseId]);
        $revisionNo = (int) $next->fetchColumn();
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_termination_notices
                (supplier_id, case_id, employee_id, employment_id, revision_no,
                 employment_ended_on, due_on, new_payer_name, new_payer_reference,
                 snapshot_json, snapshot_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId, $caseId, $employeeId, $employmentId, $revisionNo,
            $endedOn, $dueOn, $newPayerName, $newPayerReference,
            $snapshotJson, hash('sha256', $snapshotJson), $userId,
        ]);

        return $this->find($supplierId, (int) $pdo->lastInsertId())
            ?? throw new \RuntimeException('Oznámení po uložení nebylo nalezeno.');
    }

    public function markSent(
        int $supplierId,
        int $noticeId,
        string $sentOn,
        string $channel,
        ?int $outboxId,
        ?int $userId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_enforcement_termination_notices
                SET sent_on = ?, sent_channel = ?,
                    outbox_id = COALESCE(?, outbox_id), sent_by = ?
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$sentOn, $channel, $outboxId, $userId, $supplierId, $noticeId]);
    }

    public function attachOutbox(int $supplierId, int $noticeId, int $outboxId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_enforcement_termination_notices
                SET outbox_id = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$outboxId, $supplierId, $noticeId]);
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed> */
    private static function cast(array $row): array
    {
        foreach (['id', 'case_id', 'employee_id', 'employment_id', 'revision_no',
            'outbox_id', 'created_by', 'sent_by'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }
        unset($row['supplier_id']);

        return $row;
    }
}
