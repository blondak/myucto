<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class PayrollDocumentRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function approvedRevision(
        int $supplierId,
        int $runId,
        int $revisionId,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT revision.*, run.period_start
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND revision.run_id = ?
                AND revision.id = ?
                AND revision.status IN ("approved", "superseded")
                AND revision.result_snapshot_hash IS NOT NULL'
        );
        $stmt->execute([$supplierId, $runId, $revisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $documentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_generated_documents
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return array<string,mixed>|null */
    public function findByIdempotency(int $supplierId, string $keyHash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_generated_documents
              WHERE supplier_id = ? AND idempotency_key_hash = UNHEX(?)'
        );
        $stmt->execute([$supplierId, $keyHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForPeriod(int $supplierId, string $periodStart): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT document.*,
                    revision.revision_no,
                    revision.status AS revision_status,
                    employee.full_name AS employee_name,
                    run.office_id,
                    office.name AS office_name
               FROM payroll_generated_documents document
               JOIN payroll_runs run
                 ON run.supplier_id = document.supplier_id
                AND run.id = document.run_id
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = document.supplier_id
                AND revision.id = document.revision_id
          LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = document.supplier_id
                AND employee.id = document.employee_id
          LEFT JOIN payroll_offices office
                 ON office.supplier_id = run.supplier_id
                AND office.id = run.office_id
              WHERE document.supplier_id = ?
                AND run.period_start = ?
              ORDER BY document.document_kind = "monthly_bundle" DESC,
                       employee.full_name,
                       document.document_kind,
                       document.id DESC'
        );
        $stmt->execute([$supplierId, $periodStart]);
        return array_values(array_map(
            self::cast(...),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return list<array<string,mixed>> */
    public function approvedRevisionsForPeriod(
        int $supplierId,
        string $periodStart,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT run.id AS run_id,
                    run.office_id,
                    office.name AS office_name,
                    revision.id AS revision_id,
                    revision.revision_no,
                    revision.status
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
          LEFT JOIN payroll_offices office
                 ON office.supplier_id = run.supplier_id
                AND office.id = run.office_id
              WHERE run.supplier_id = ?
                AND run.period_start = ?
                AND revision.status IN ("approved", "superseded")
                AND revision.result_snapshot_hash IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1
                      FROM payroll_run_revisions newer
                     WHERE newer.supplier_id = revision.supplier_id
                       AND newer.run_id = revision.run_id
                       AND newer.revision_no > revision.revision_no
                       AND newer.status IN ("approved", "superseded")
                       AND newer.result_snapshot_hash IS NOT NULL
                )
              ORDER BY office.name, run.id'
        );
        $stmt->execute([$supplierId, $periodStart]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['run_id', 'revision_id', 'revision_no'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['office_id'] = $row['office_id'] === null
                ? null
                : (int) $row['office_id'];
        }
        unset($row);
        return array_values($rows);
    }

    /** @return list<array<string,mixed>> */
    public function forRevision(int $supplierId, int $revisionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_generated_documents
              WHERE supplier_id = ? AND revision_id = ?
                AND document_kind <> "monthly_bundle"
              ORDER BY id'
        );
        $stmt->execute([$supplierId, $revisionId]);
        return array_values(array_map(
            self::cast(...),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return array<string,mixed>|null */
    public function latestForRevisionKind(
        int $supplierId,
        int $revisionId,
        ?int $employeeId,
        string $documentKind,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_generated_documents
              WHERE supplier_id = ?
                AND revision_id = ?
                AND employee_id <=> ?
                AND document_kind = ?
              ORDER BY document_revision_no DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $documentKind,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return array<string,mixed>|null */
    public function latestForRunKind(
        int $supplierId,
        int $runId,
        ?int $employeeId,
        string $documentKind,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_generated_documents
              WHERE supplier_id = ?
                AND run_id = ?
                AND employee_id <=> ?
                AND document_kind = ?
              ORDER BY document_revision_no DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([
            $supplierId,
            $runId,
            $employeeId,
            $documentKind,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function employeeBelongsToRevision(
        int $supplierId,
        int $revisionId,
        int $employeeId,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?
                AND status = "calculated" AND result_hash IS NOT NULL'
        );
        $stmt->execute([$supplierId, $revisionId, $employeeId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function insertOrGet(array $record): array
    {
        $pdo = $this->db->pdo();
        $existing = $this->findByIdempotency(
            (int) $record['supplier_id'],
            (string) $record['idempotency_key_hash'],
        );
        if ($existing !== null) {
            return self::requireMatchingReplay($existing, $record);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, run_id, revision_id, employee_id, document_kind,
                 document_revision_no, supersedes_document_id, source_snapshot_hash,
                 revision_snapshot_hash,
                 template_version, renderer_version, file_sha256, size_bytes,
                 mime_type, storage_key, suggested_filename, manifest_json,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNHEX(?), ?)'
        );
        try {
            $stmt->execute([
                $record['supplier_id'],
                $record['run_id'],
                $record['revision_id'],
                $record['employee_id'],
                $record['document_kind'],
                $record['document_revision_no'],
                $record['supersedes_document_id'],
                $record['source_snapshot_hash'],
                $record['revision_snapshot_hash'],
                $record['template_version'],
                $record['renderer_version'],
                $record['file_sha256'],
                $record['size_bytes'],
                $record['mime_type'],
                $record['storage_key'],
                $record['suggested_filename'],
                $record['manifest_json'],
                $record['idempotency_key_hash'],
                $record['created_by'],
            ]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            $replayed = $this->findByIdempotency(
                (int) $record['supplier_id'],
                (string) $record['idempotency_key_hash'],
            );
            if ($replayed === null) {
                throw new \RuntimeException(
                    'Generated payroll document conflicts with an existing artifact.',
                    previous: $exception,
                );
            }
            return self::requireMatchingReplay($replayed, $record);
        }
        $id = (int) $pdo->lastInsertId();
        $found = $this->find((int) $record['supplier_id'], $id);
        if ($found === null) {
            throw new \RuntimeException('Generated payroll document could not be loaded.');
        }
        return self::requireMatchingReplay($found, $record);
    }

    /**
     * @param array<string,mixed> $found
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private static function requireMatchingReplay(array $found, array $record): array
    {
        if (
            $found['source_snapshot_hash'] !== $record['source_snapshot_hash']
            || $found['document_kind'] !== $record['document_kind']
            || $found['employee_id'] !== $record['employee_id']
            || $found['run_id'] !== $record['run_id']
            || $found['revision_id'] !== $record['revision_id']
        ) {
            throw new \RuntimeException('Payroll document idempotency key was reused for another request.');
        }
        return $found;
    }

    public function createDownloadGrant(
        int $supplierId,
        int $documentId,
        int $userId,
        string $tokenHash,
        string $expiresAt,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_document_download_grants
                (supplier_id, document_id, user_id, token_hash, expires_at)
             VALUES (?, ?, ?, UNHEX(?), ?)'
        );
        $stmt->execute([$supplierId, $documentId, $userId, $tokenHash, $expiresAt]);
    }

    public function consumeDownloadGrant(
        int $supplierId,
        int $documentId,
        int $userId,
        string $tokenHash,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_document_download_grants
                SET used_at = NOW()
              WHERE supplier_id = ? AND document_id = ? AND user_id = ?
                AND token_hash = UNHEX(?) AND used_at IS NULL AND expires_at >= NOW()'
        );
        $stmt->execute([$supplierId, $documentId, $userId, $tokenHash]);
        return $stmt->rowCount() === 1;
    }

    public function linkToDms(
        int $supplierId,
        int $payrollDocumentId,
        int $dmsDocumentId,
        ?int $actorUserId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_document_dms_links
                (supplier_id, payroll_document_id, dms_document_id, linked_by)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $payrollDocumentId,
            $dmsDocumentId,
            $actorUserId,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        foreach ([
            'id', 'supplier_id', 'run_id', 'revision_id', 'document_revision_no',
            'size_bytes', 'revision_no',
        ] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['employee_id', 'supersedes_document_id', 'created_by', 'office_id'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        $row['manifest'] = $row['manifest_json'] === null
            ? null
            : json_decode((string) $row['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['manifest_json']);
        return $row;
    }
}
