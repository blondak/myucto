<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class TaxSubmissionEpoRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array{vat_root_folder_id:?int,income_tax_root_folder_id:?int} */
    public function settings(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT vat_root_folder_id, income_tax_root_folder_id
               FROM tax_submission_settings
              WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'vat_root_folder_id' => $row !== false && $row['vat_root_folder_id'] !== null
                ? (int) $row['vat_root_folder_id']
                : null,
            'income_tax_root_folder_id' => $row !== false && $row['income_tax_root_folder_id'] !== null
                ? (int) $row['income_tax_root_folder_id']
                : null,
        ];
    }

    public function saveSettings(
        int $supplierId,
        ?int $vatRootFolderId,
        ?int $incomeTaxRootFolderId,
        ?int $userId,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO tax_submission_settings
                (supplier_id, vat_root_folder_id, income_tax_root_folder_id, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                vat_root_folder_id = VALUES(vat_root_folder_id),
                income_tax_root_folder_id = VALUES(income_tax_root_folder_id),
                updated_by = VALUES(updated_by)'
        )->execute([$supplierId, $vatRootFolderId, $incomeTaxRootFolderId, $userId]);
    }

    /** @return array<string,mixed>|null */
    public function lockSubmission(int $submissionId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM tax_submissions
              WHERE id = ? AND supplier_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$submissionId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function expireAttempts(int $submissionId, int $supplierId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = 'expired'
              WHERE tax_submission_id = ? AND supplier_id = ?
                AND status IN ('prepared','handoff_created','awaiting_confirmation')
                AND (
                    (handoff_expires_at IS NOT NULL AND handoff_expires_at <= CURRENT_TIMESTAMP)
                    OR (status = 'prepared' AND requested_at <= CURRENT_TIMESTAMP - INTERVAL 5 MINUTE)
                )"
        )->execute([$submissionId, $supplierId]);
    }

    /** @return array<string,mixed>|null */
    public function activeAttempt(
        int $submissionId,
        int $supplierId,
        string $environment = 'production',
    ): ?array
    {
        $environment = $this->normalizeEnvironment($environment);
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM tax_submission_attempts
              WHERE tax_submission_id = ? AND supplier_id = ?
                AND (epo_environment = 'production' OR epo_environment = ?)
                AND (
                  (channel = 'epo_assisted'
                    AND status IN ('prepared','handoff_created','awaiting_confirmation')
                    AND (handoff_expires_at IS NULL
                      OR handoff_expires_at > CURRENT_TIMESTAMP))
                  OR
                  (channel = 'epo_direct'
                    AND (
                      status IN ('submitting','processing','uncertain')
                      OR (status = 'confirmed' AND epo_environment = 'production')
                    ))
                )
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->execute([$submissionId, $supplierId, $environment]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeAttempt($row) : null;
    }

    public function insertAttempt(
        int $supplierId,
        int $submissionId,
        string $idempotencyKey,
        string $requestSha256,
        ?int $userId,
        string $environment,
    ): int {
        $environment = $this->normalizeEnvironment($environment);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tax_submission_attempts
                (supplier_id, tax_submission_id, epo_environment,
                 idempotency_key, request_sha256, requested_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $submissionId,
            $environment,
            $idempotencyKey,
            $requestSha256,
            $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function markHandoffCreated(int $attemptId, int $httpStatus, string $expiresAt): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = 'awaiting_confirmation',
                    response_http_status = ?,
                    handoff_expires_at = ?,
                    error_code = NULL,
                    error_message = NULL
              WHERE id = ? AND status = 'prepared'"
        );
        $stmt->execute([$httpStatus, $expiresAt, $attemptId]);
        return $stmt->rowCount() > 0;
    }

    public function markAttemptFailed(
        int $attemptId,
        string $errorCode,
        string $errorMessage,
        ?int $httpStatus,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = 'failed',
                    response_http_status = ?,
                    error_code = ?,
                    error_message = ?
              WHERE id = ? AND status = 'prepared'"
        );
        $stmt->execute([$httpStatus, $errorCode, mb_substr($errorMessage, 0, 500), $attemptId]);
        return $stmt->rowCount() > 0;
    }

    public function cancelActiveAttempt(int $attemptId, int $submissionId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = 'cancelled'
              WHERE id = ? AND tax_submission_id = ? AND supplier_id = ?
                AND status IN ('prepared','handoff_created','awaiting_confirmation')"
        );
        $stmt->execute([$attemptId, $submissionId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    public function markAttemptConfirmed(int $attemptId, string $confirmedAt): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = 'confirmed', confirmed_at = ?
              WHERE id = ? AND status = 'awaiting_confirmation'"
        );
        $stmt->execute([$confirmedAt, $attemptId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array{id:int,status:string,epo_environment:string}|null */
    public function latestConfirmableAttempt(int $submissionId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, status, epo_environment FROM tax_submission_attempts
              WHERE tax_submission_id = ? AND supplier_id = ?
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$submissionId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['status'] !== 'awaiting_confirmation') {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'epo_environment' => $this->normalizeEnvironment((string) $row['epo_environment']),
        ];
    }

    /**
     * @return 'deleted'|'has_evidence'|'not_found'
     */
    public function deleteSubmissionIfNoEvidence(int $submissionId, int $supplierId): string
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($this->lockSubmission($submissionId, $supplierId) === null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return 'not_found';
            }
            if ($this->hasEvidence($submissionId, $supplierId)) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return 'has_evidence';
            }

            $stmt = $pdo->prepare(
                'DELETE FROM tax_submissions WHERE id = ? AND supplier_id = ?'
            );
            $stmt->execute([$submissionId, $supplierId]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $stmt->rowCount() > 0 ? 'deleted' : 'not_found';
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function hasEvidence(int $submissionId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT EXISTS(
                    SELECT 1
                      FROM tax_submissions s
                     WHERE s.id = ? AND s.supplier_id = ?
                       AND s.status IN ('submitted','accepted')
                )
                OR EXISTS(
                    SELECT 1
                      FROM tax_submission_attempts a
                     WHERE a.tax_submission_id = ? AND a.supplier_id = ?
                )
                OR EXISTS(
                    SELECT 1
                      FROM tax_submission_artifacts a
                     WHERE a.tax_submission_id = ? AND a.supplier_id = ?
                )"
        );
        $stmt->execute([
            $submissionId,
            $supplierId,
            $submissionId,
            $supplierId,
            $submissionId,
            $supplierId,
        ]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function attempts(int $submissionId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, channel, epo_environment, status, request_sha256, signing_credential_id,
                    signing_fingerprint, response_http_status, test_passed,
                    test_messages_json, tested_at, error_code, error_message,
                    requested_by, requested_at, handoff_expires_at, submitted_at,
                    remote_submission_ref, last_status_json, last_status_at,
                    confirmed_at, poll_count, next_poll_at,
                    resolution_code, resolution_note, resolved_by, resolved_at,
                    (remote_submission_ref IS NOT NULL
                      AND state_password_ciphertext IS NOT NULL) AS status_query_available,
                    ((remote_submission_ref IS NOT NULL
                      AND state_password_ciphertext IS NOT NULL)
                      OR (offline_transfer_id IS NOT NULL
                        AND offline_password_ciphertext IS NOT NULL)) AS refresh_available,
                    (submitted_signed_ciphertext IS NOT NULL) AS confirmation_recovery_available,
                    updated_at
               FROM tax_submission_attempts
              WHERE tax_submission_id = ? AND supplier_id = ?
              ORDER BY id DESC'
        );
        $stmt->execute([$submissionId, $supplierId]);
        return array_map(
            fn (array $row): array => $this->normalizeAttempt($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    public function addArtifact(
        int $supplierId,
        int $submissionId,
        ?int $attemptId,
        int $documentId,
        string $kind,
        string $sha256,
        string $verificationStatus,
        ?array $verification,
        ?int $userId,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tax_submission_artifacts
                (supplier_id, tax_submission_id, attempt_id, document_id, artifact_kind,
                 sha256, verification_status, verification_json, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                attempt_id = VALUES(attempt_id),
                document_id = VALUES(document_id),
                verification_status = VALUES(verification_status),
                verification_json = VALUES(verification_json),
                uploaded_by = VALUES(uploaded_by),
                created_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $supplierId,
            $submissionId,
            $attemptId,
            $documentId,
            $kind,
            $sha256,
            $verificationStatus,
            $verification !== null
                ? json_encode($verification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function attemptBelongsToSubmission(int $attemptId, int $submissionId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM tax_submission_attempts
              WHERE id = ? AND tax_submission_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$attemptId, $submissionId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    public function artifact(int $artifactId, int $submissionId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.tax_submission_id, a.document_id,
                    d.sha256 AS document_sha256, d.filename, d.original_name,
                    d.mime_type, d.doc_type, d.size_bytes
               FROM tax_submission_artifacts a
               JOIN documents d ON d.id = a.document_id
              WHERE a.id = ? AND a.tax_submission_id = ? AND a.supplier_id = ?
                AND d.deleted_at IS NULL'
        );
        $stmt->execute([$artifactId, $submissionId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function artifactByKindAndSha(
        int $submissionId,
        int $supplierId,
        string $kind,
        string $sha256,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.*, d.title, d.original_name, d.size_bytes, d.doc_type, d.folder_id
               FROM tax_submission_artifacts a
               JOIN documents d ON d.id = a.document_id
              WHERE a.tax_submission_id = ? AND a.supplier_id = ?
                AND a.artifact_kind = ? AND a.sha256 = ?
                AND d.deleted_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$submissionId, $supplierId, $kind, $sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeArtifact($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function sourceArtifact(int $submissionId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.*, d.title, d.original_name, d.size_bytes, d.doc_type, d.folder_id
               FROM tax_submission_artifacts a
               JOIN documents d ON d.id = a.document_id
              WHERE a.tax_submission_id = ? AND a.supplier_id = ?
                AND a.artifact_kind = 'source_xml'
                AND d.deleted_at IS NULL
              ORDER BY a.id DESC LIMIT 1"
        );
        $stmt->execute([$submissionId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeArtifact($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function artifacts(int $submissionId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.*, d.title, d.original_name, d.size_bytes, d.doc_type, d.folder_id
               FROM tax_submission_artifacts a
               JOIN documents d ON d.id = a.document_id
              WHERE a.tax_submission_id = ? AND a.supplier_id = ?
                AND d.deleted_at IS NULL
              ORDER BY a.created_at DESC, a.id DESC'
        );
        $stmt->execute([$submissionId, $supplierId]);
        return array_map(
            fn (array $row): array => $this->normalizeArtifact($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    /**
     * Doplní list snapshotů o pokusy a artefakty bez N+1 dotazů.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function enrich(array $rows, int $supplierId): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $rows,
        )));
        if ($ids === []) {
            return $rows;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = [$supplierId, ...$ids];

        $attemptStmt = $this->db->pdo()->prepare(
            "SELECT id, tax_submission_id, channel, epo_environment, status, request_sha256,
                    signing_credential_id, signing_fingerprint, response_http_status,
                    test_passed, test_messages_json, tested_at,
                    error_code, error_message, requested_by, requested_at,
                    handoff_expires_at, submitted_at, remote_submission_ref,
                    last_status_json, last_status_at, confirmed_at,
                    poll_count, next_poll_at,
                    resolution_code, resolution_note, resolved_by, resolved_at,
                    (remote_submission_ref IS NOT NULL
                      AND state_password_ciphertext IS NOT NULL) AS status_query_available,
                    ((remote_submission_ref IS NOT NULL
                      AND state_password_ciphertext IS NOT NULL)
                      OR (offline_transfer_id IS NOT NULL
                        AND offline_password_ciphertext IS NOT NULL)) AS refresh_available,
                    (submitted_signed_ciphertext IS NOT NULL) AS confirmation_recovery_available,
                    updated_at
               FROM tax_submission_attempts
              WHERE supplier_id = ? AND tax_submission_id IN ($placeholders)
              ORDER BY id DESC"
        );
        $attemptStmt->execute($params);
        $attempts = [];
        foreach ($attemptStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $attempt) {
            $attempts[(int) $attempt['tax_submission_id']][] = $this->normalizeAttempt($attempt);
        }

        $artifactStmt = $this->db->pdo()->prepare(
            "SELECT a.*, d.title, d.original_name, d.size_bytes, d.doc_type, d.folder_id
               FROM tax_submission_artifacts a
               JOIN documents d ON d.id = a.document_id AND d.deleted_at IS NULL
              WHERE a.supplier_id = ? AND a.tax_submission_id IN ($placeholders)
              ORDER BY a.created_at DESC, a.id DESC"
        );
        $artifactStmt->execute($params);
        $artifacts = [];
        foreach ($artifactStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $artifact) {
            $artifacts[(int) $artifact['tax_submission_id']][] = $this->normalizeArtifact($artifact);
        }

        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            $row['attempts'] = $attempts[$id] ?? [];
            $row['artifacts'] = $artifacts[$id] ?? [];
        }
        unset($row);

        return $rows;
    }

    private function normalizeAttempt(array $row): array
    {
        $row['id'] = (int) $row['id'];
        if (isset($row['tax_submission_id'])) {
            $row['tax_submission_id'] = (int) $row['tax_submission_id'];
        }
        $row['response_http_status'] = $row['response_http_status'] !== null
            ? (int) $row['response_http_status']
            : null;
        if (array_key_exists('signing_credential_id', $row)) {
            $row['signing_credential_id'] = $row['signing_credential_id'] !== null
                ? (int) $row['signing_credential_id']
                : null;
        }
        if (array_key_exists('test_passed', $row)) {
            $row['test_passed'] = $row['test_passed'] !== null
                ? (bool) $row['test_passed']
                : null;
        }
        if (array_key_exists('test_messages_json', $row)) {
            $row['test_messages'] = $row['test_messages_json'] !== null
                ? (json_decode((string) $row['test_messages_json'], true) ?: [])
                : [];
            unset($row['test_messages_json']);
        }
        if (array_key_exists('last_status_json', $row)) {
            $row['remote_status'] = $row['last_status_json'] !== null
                ? (json_decode((string) $row['last_status_json'], true) ?: null)
                : null;
            unset($row['last_status_json']);
        }
        if (array_key_exists('poll_count', $row)) {
            $row['poll_count'] = (int) $row['poll_count'];
        }
        if (array_key_exists('resolved_by', $row)) {
            $row['resolved_by'] = $row['resolved_by'] !== null ? (int) $row['resolved_by'] : null;
        }
        if (array_key_exists('status_query_available', $row)) {
            $row['status_query_available'] = (bool) $row['status_query_available'];
        }
        if (array_key_exists('refresh_available', $row)) {
            $row['refresh_available'] = (bool) $row['refresh_available'];
        }
        if (array_key_exists('confirmation_recovery_available', $row)) {
            $row['confirmation_recovery_available'] = (bool) $row['confirmation_recovery_available'];
        }
        $row['requested_by'] = $row['requested_by'] !== null ? (int) $row['requested_by'] : null;
        unset($row['idempotency_key']);
        return $row;
    }

    private function normalizeArtifact(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['tax_submission_id'] = (int) $row['tax_submission_id'];
        $row['attempt_id'] = $row['attempt_id'] !== null ? (int) $row['attempt_id'] : null;
        $row['document_id'] = (int) $row['document_id'];
        $row['uploaded_by'] = $row['uploaded_by'] !== null ? (int) $row['uploaded_by'] : null;
        if (isset($row['size_bytes'])) {
            $row['size_bytes'] = (int) $row['size_bytes'];
        }
        if (array_key_exists('folder_id', $row)) {
            $row['folder_id'] = $row['folder_id'] !== null ? (int) $row['folder_id'] : null;
        }
        $row['verification'] = $row['verification_json'] !== null
            ? (json_decode((string) $row['verification_json'], true) ?: null)
            : null;
        unset($row['verification_json']);
        return $row;
    }

    private function normalizeEnvironment(string $environment): string
    {
        $environment = strtolower(trim($environment));
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException('Neplatné prostředí EPO.');
        }
        return $environment;
    }
}
