<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class JmhzEldpEvidenceSnapshotRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owns) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($owns) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    public function lockSource(int $supplierId, int $revisionId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.id, revision.run_id, revision.revision_no,
                    revision.revision_kind, revision.status,
                    revision.ruleset_manifest_hash,
                    revision.input_snapshot_json, revision.input_snapshot_hash,
                    revision.result_snapshot_json, revision.result_snapshot_hash,
                    run.period_start, run.current_revision_no
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ? AND revision.id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $revisionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        foreach (['id', 'run_id', 'revision_no', 'current_revision_no'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        return ['revision' => $row];
    }

    public function insertClaim(
        int $supplierId,
        string $environment,
        string $idempotencyHash,
        int $sourceRevisionId,
        int $employmentId,
        string $confirmationFingerprint,
        ?int $createdBy,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_eldp_idempotency_claims
                (supplier_id, environment, idempotency_key_hash,
                 source_revision_id, employment_id, confirmation_fingerprint, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        try {
            $statement->execute([
                $supplierId, $environment, $idempotencyHash,
                $sourceRevisionId, $employmentId, $confirmationFingerprint, $createdBy,
            ]);
            return true;
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    public function findClaimForUpdate(int $supplierId, string $environment, string $idempotencyHash): ?array
    {
        return $this->findOne(
            'SELECT * FROM payroll_jmhz_eldp_idempotency_claims
              WHERE supplier_id = ? AND environment = ? AND idempotency_key_hash = ?
              FOR UPDATE',
            [$supplierId, $environment, $idempotencyHash],
        );
    }

    public function bindClaim(int $supplierId, string $environment, string $idempotencyHash, int $snapshotId): void
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_jmhz_eldp_idempotency_claims
                SET evidence_snapshot_id = ?
              WHERE supplier_id = ? AND environment = ?
                AND idempotency_key_hash = ? AND evidence_snapshot_id IS NULL'
        );
        $statement->execute([$snapshotId, $supplierId, $environment, $idempotencyHash]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Idempotentní vazbu ELDP nelze uzamknout.');
        }
    }

    /** @return array<string,mixed>|null */
    public function findByScopeForUpdate(
        int $supplierId,
        string $environment,
        int $revisionId,
        int $employmentId,
    ): ?array {
        return $this->findOne(
            'SELECT * FROM payroll_jmhz_eldp_evidence_snapshots
              WHERE supplier_id = ? AND environment = ?
                AND source_revision_id = ? AND employment_id = ?
              FOR UPDATE',
            [$supplierId, $environment, $revisionId, $employmentId],
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, string $environment, int $id): ?array
    {
        return $this->findOne(
            'SELECT * FROM payroll_jmhz_eldp_evidence_snapshots
              WHERE supplier_id = ? AND environment = ? AND id = ?',
            [$supplierId, $environment, $id],
        );
    }

    /** @param array<string,mixed> $record */
    public function insert(array $record): int
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_eldp_evidence_snapshots
                (supplier_id, environment, run_id, source_revision_id,
                 employee_id, employment_id, period_start, schema_reference,
                 section_count, source_manifest_json, source_manifest_sha256,
                 snapshot_ciphertext, snapshot_fingerprint,
                 request_fingerprint, idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $record['supplier_id'], $record['environment'], $record['run_id'],
            $record['source_revision_id'], $record['employee_id'],
            $record['employment_id'], $record['period_start'],
            $record['schema_reference'], $record['section_count'],
            $record['source_manifest_json'], $record['source_manifest_sha256'],
            $record['snapshot_ciphertext'], $record['snapshot_fingerprint'],
            $record['request_fingerprint'], $record['idempotency_key_hash'],
            $record['created_by'],
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param list<int|string> $parameters
     * @return array<string,mixed>|null
     */
    private function findOne(string $sql, array $parameters): ?array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        foreach ([
            'id', 'supplier_id', 'run_id', 'source_revision_id', 'employee_id',
            'employment_id', 'section_count', 'evidence_snapshot_id',
        ] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }
        if (array_key_exists('created_by', $row)) {
            $row['created_by'] = $row['created_by'] === null ? null : (int) $row['created_by'];
        }
        return $row;
    }
}
