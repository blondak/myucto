<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class JmhzOrdinaryEvidenceRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @template TReturn
     * @param callable(): TReturn $callback
     * @return TReturn
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
        string $idempotencyHash,
        int $revisionId,
        int $employeeId,
        int $employmentId,
        string $confirmationFingerprint,
        int $createdBy,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_ordinary_evidence_idempotency_claims
                (supplier_id, idempotency_key_hash, source_revision_id,
                 employee_id, employment_id, confirmation_fingerprint, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        try {
            $statement->execute([
                $supplierId, $idempotencyHash, $revisionId, $employeeId,
                $employmentId, $confirmationFingerprint, $createdBy,
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
    public function findClaimForUpdate(int $supplierId, string $idempotencyHash): ?array
    {
        return $this->findOne(
            'SELECT * FROM payroll_jmhz_ordinary_evidence_idempotency_claims
              WHERE supplier_id = ? AND idempotency_key_hash = ? FOR UPDATE',
            [$supplierId, $idempotencyHash],
        );
    }

    public function bindClaim(int $supplierId, string $idempotencyHash, int $snapshotId): void
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_jmhz_ordinary_evidence_idempotency_claims
                SET evidence_snapshot_id = ?
              WHERE supplier_id = ? AND idempotency_key_hash = ?
                AND evidence_snapshot_id IS NULL'
        );
        $statement->execute([$snapshotId, $supplierId, $idempotencyHash]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Idempotentní vazbu ordinary evidence nelze uzamknout.');
        }
    }

    /** @return array<string,mixed>|null */
    public function findByRevision(int $supplierId, int $revisionId): ?array
    {
        return $this->findOne(
            'SELECT * FROM payroll_jmhz_ordinary_evidence_snapshots
              WHERE supplier_id = ? AND source_revision_id = ?',
            [$supplierId, $revisionId],
        );
    }

    /** @return array<string,mixed>|null */
    public function findByRevisionForUpdate(int $supplierId, int $revisionId): ?array
    {
        return $this->findOne(
            'SELECT * FROM payroll_jmhz_ordinary_evidence_snapshots
              WHERE supplier_id = ? AND source_revision_id = ? FOR UPDATE',
            [$supplierId, $revisionId],
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        return $this->findOne(
            'SELECT * FROM payroll_jmhz_ordinary_evidence_snapshots
              WHERE supplier_id = ? AND id = ?',
            [$supplierId, $id],
        );
    }

    /** @param array<string,mixed> $record */
    public function insert(array $record): int
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_ordinary_evidence_snapshots
                (supplier_id, run_id, source_revision_id, employee_id,
                 employment_id, period_start, schema_reference,
                 source_manifest_json, source_manifest_sha256,
                 snapshot_ciphertext, snapshot_fingerprint,
                 request_fingerprint, idempotency_key_hash,
                 confirmed_by, confirmed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $record['supplier_id'], $record['run_id'], $record['source_revision_id'],
            $record['employee_id'], $record['employment_id'], $record['period_start'],
            $record['schema_reference'], $record['source_manifest_json'],
            $record['source_manifest_sha256'], $record['snapshot_ciphertext'],
            $record['snapshot_fingerprint'], $record['request_fingerprint'],
            $record['idempotency_key_hash'], $record['confirmed_by'],
            $record['confirmed_at'],
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
            'employment_id', 'confirmed_by', 'evidence_snapshot_id',
        ] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }
        return $row;
    }
}
