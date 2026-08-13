<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class JmhzPreparationSnapshotRepository
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
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
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
                    revision.schema_version, revision.ruleset_manifest_hash,
                    revision.input_snapshot_json, revision.input_snapshot_hash,
                    revision.result_snapshot_json, revision.result_snapshot_hash,
                    run.period_start, run.current_revision_no, run.office_id,
                    office.social_security_variable_symbol
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
          LEFT JOIN payroll_offices office
                 ON office.supplier_id = run.supplier_id
                AND office.id = run.office_id
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
        $row['office_id'] = $row['office_id'] === null ? null : (int) $row['office_id'];
        return [
            'revision' => $row,
            'office' => [
                'id' => $row['office_id'],
                'social_security_variable_symbol' => $row['social_security_variable_symbol'],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public function findByIdempotencyForUpdate(
        int $supplierId,
        string $environment,
        string $idempotencyHash,
    ): ?array {
        return $this->findOne(
            'SELECT * FROM payroll_jmhz_preparation_snapshots
              WHERE supplier_id = ? AND environment = ? AND idempotency_key_hash = ?
              FOR UPDATE',
            [$supplierId, $environment, $idempotencyHash],
        );
    }

    public function insertIdempotencyClaim(
        int $supplierId,
        string $environment,
        string $idempotencyHash,
        int $sourceRevisionId,
        ?int $createdBy,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_preparation_idempotency_claims
                (supplier_id, environment, idempotency_key_hash,
                 source_revision_id, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        try {
            $statement->execute([
                $supplierId,
                $environment,
                $idempotencyHash,
                $sourceRevisionId,
                $createdBy,
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
    public function findIdempotencyClaimForUpdate(
        int $supplierId,
        string $environment,
        string $idempotencyHash,
    ): ?array {
        $claim = $this->findOne(
            'SELECT * FROM payroll_jmhz_preparation_idempotency_claims
              WHERE supplier_id = ? AND environment = ?
                AND idempotency_key_hash = ?
              FOR UPDATE',
            [$supplierId, $environment, $idempotencyHash],
        );
        if ($claim !== null) {
            $claim['preparation_snapshot_id'] = $claim['preparation_snapshot_id'] === null
                ? null
                : (int) $claim['preparation_snapshot_id'];
        }
        return $claim;
    }

    public function bindIdempotencyClaim(
        int $supplierId,
        string $environment,
        string $idempotencyHash,
        int $preparationSnapshotId,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_jmhz_preparation_idempotency_claims
                SET preparation_snapshot_id = ?
              WHERE supplier_id = ? AND environment = ?
                AND idempotency_key_hash = ?
                AND preparation_snapshot_id IS NULL'
        );
        $statement->execute([
            $preparationSnapshotId,
            $supplierId,
            $environment,
            $idempotencyHash,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Idempotentní vazbu přípravy JMHZ nelze uzamknout.');
        }
    }

    /** @return array<string,mixed>|null */
    public function findByRequestForUpdate(
        int $supplierId,
        string $environment,
        string $requestFingerprint,
    ): ?array {
        return $this->findOne(
            'SELECT * FROM payroll_jmhz_preparation_snapshots
              WHERE supplier_id = ? AND environment = ? AND request_fingerprint = ?
              FOR UPDATE',
            [$supplierId, $environment, $requestFingerprint],
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, string $environment, int $id): ?array
    {
        return $this->findOne(
            'SELECT * FROM payroll_jmhz_preparation_snapshots
              WHERE supplier_id = ? AND environment = ? AND id = ?',
            [$supplierId, $environment, $id],
        );
    }

    /** @param array<string,mixed> $record */
    public function insert(array $record): int
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_preparation_snapshots
                (supplier_id, environment, run_id, source_revision_id,
                 period_start, scenario_key, builder_version,
                 readiness_status, issue_count, source_manifest_json,
                 source_manifest_sha256, readiness_json, readiness_sha256,
                 snapshot_ciphertext, snapshot_fingerprint,
                 request_fingerprint, idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $record['supplier_id'], $record['environment'], $record['run_id'],
            $record['source_revision_id'], $record['period_start'],
            $record['scenario_key'], $record['builder_version'],
            $record['readiness_status'], $record['issue_count'],
            $record['source_manifest_json'], $record['source_manifest_sha256'],
            $record['readiness_json'], $record['readiness_sha256'],
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
        foreach (['id', 'supplier_id', 'run_id', 'source_revision_id', 'issue_count'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (int) $row[$field];
            }
        }
        $row['created_by'] = $row['created_by'] === null ? null : (int) $row['created_by'];
        return $row;
    }
}
