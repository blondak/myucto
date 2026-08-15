<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollSubmissionRepository
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
            $savepoint = 'payroll_submission_' . ++$this->savepointSequence;
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
     * @return list<array{
     *   id:int,environment:string,agenda_code:string,subject_type:string,
     *   subject_reference:string,period_start:string,period_end:string,
     *   obligation_kind:string,preferred_channel:string,status:string,
     *   row_version:int,earliest_submission_on:string,due_on:string,
     *   calendar_basis:string,latest_submission:?array{
     *     id:int,status:string,submission_kind:string,channel:string,
     *     submitted_at:?string,decided_at:?string
     *   }
     * }>
     */
    public function listOverview(
        int $supplierId,
        string $environment,
        string $periodStart,
        string $periodEnd,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.id, obligation.environment,
                    obligation.agenda_code, obligation.subject_type,
                    obligation.subject_reference, obligation.period_start,
                    obligation.period_end, obligation.obligation_kind,
                    obligation.preferred_channel, obligation.status,
                    obligation.row_version,
                    deadline.earliest_submission_on, deadline.due_on,
                    deadline.calendar_basis,
                    latest_submission.id AS submission_id,
                    latest_submission.status AS submission_status,
                    latest_submission.submission_kind,
                    latest_submission.channel AS submission_channel,
                    latest_submission.submitted_at,
                    latest_submission.decided_at
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
                                  submission.submission_kind,
                                  submission.channel,
                                  submission.submitted_at,
                                  submission.decided_at,
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
              WHERE obligation.supplier_id = ?
                AND obligation.environment = ?
                AND obligation.period_start <= ?
                AND obligation.period_end >= ?
              ORDER BY deadline.due_on ASC,
                       obligation.agenda_code ASC,
                       obligation.id ASC
              LIMIT 200',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $supplierId,
            $environment,
            $periodEnd,
            $periodStart,
        ]);

        $result = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = self::associativeRow($row, 'přehled mzdových podání');
            $submissionId = self::nullableInteger($row, 'submission_id');
            $result[] = [
                'id' => self::integer($row, 'id'),
                'environment' => self::string($row, 'environment'),
                'agenda_code' => self::string($row, 'agenda_code'),
                'subject_type' => self::string($row, 'subject_type'),
                'subject_reference' => self::string(
                    $row,
                    'subject_reference',
                ),
                'period_start' => self::string($row, 'period_start'),
                'period_end' => self::string($row, 'period_end'),
                'obligation_kind' => self::string($row, 'obligation_kind'),
                'preferred_channel' => self::string(
                    $row,
                    'preferred_channel',
                ),
                'status' => self::string($row, 'status'),
                'row_version' => self::integer($row, 'row_version'),
                'earliest_submission_on' => self::string(
                    $row,
                    'earliest_submission_on',
                ),
                'due_on' => self::string($row, 'due_on'),
                'calendar_basis' => self::string($row, 'calendar_basis'),
                'latest_submission' => $submissionId === null
                    ? null
                    : [
                        'id' => $submissionId,
                        'status' => self::string($row, 'submission_status'),
                        'submission_kind' => self::string(
                            $row,
                            'submission_kind',
                        ),
                        'channel' => self::string(
                            $row,
                            'submission_channel',
                        ),
                        'submitted_at' => self::nullableString(
                            $row,
                            'submitted_at',
                        ),
                        'decided_at' => self::nullableString(
                            $row,
                            'decided_at',
                        ),
                    ],
            ];
        }

        return $result;
    }

    /**
     * @return array{
     *   id:int,due_on:string,status:string,row_version:int,
     *   request_fingerprint:string
     * }|null
     */
    public function findObligationByIdempotencyForUpdate(
        int $supplierId,
        string $idempotencyKeyHash,
        string $environment = 'production',
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.id, obligation.status,
                    obligation.row_version, obligation.request_fingerprint,
                    deadline.due_on
               FROM payroll_obligations obligation
               JOIN payroll_submission_deadlines deadline
                 ON deadline.supplier_id = obligation.supplier_id
                AND deadline.obligation_id = obligation.id
                AND deadline.deadline_kind = "regular"
              WHERE obligation.supplier_id = ?
                AND obligation.environment = ?
                AND obligation.idempotency_key_hash = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $idempotencyKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'mzdovou povinnost');

        return [
            'id' => self::integer($row, 'id'),
            'due_on' => self::string($row, 'due_on'),
            'status' => self::string($row, 'status'),
            'row_version' => self::integer($row, 'row_version'),
            'request_fingerprint' => self::hash(
                $row,
                'request_fingerprint',
            ),
        ];
    }

    public function insertObligation(
        int $supplierId,
        string $environment,
        string $agendaCode,
        string $subjectType,
        string $subjectReference,
        string $periodStart,
        string $periodEnd,
        string $obligationKind,
        string $channel,
        string $sourceEventType,
        string $sourceEventReference,
        string $sourceEventHash,
        string $requestFingerprint,
        string $idempotencyKeyHash,
        ?int $responsibleUserId,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type,
                 subject_reference, period_start, period_end,
                 obligation_kind, preferred_channel,
                 responsible_user_id, source_event_type,
                 source_event_reference, source_event_hash,
                 request_fingerprint, idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $agendaCode,
            $subjectType,
            $subjectReference,
            $periodStart,
            $periodEnd,
            $obligationKind,
            $channel,
            $responsibleUserId,
            $sourceEventType,
            $sourceEventReference,
            $sourceEventHash,
            $requestFingerprint,
            $idempotencyKeyHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertDeadline(
        int $supplierId,
        string $environment,
        int $obligationId,
        string $deadlineKind,
        string $earliestSubmissionOn,
        string $dueOn,
        string $calendarBasis,
        ?int $fictionDeliveryDays,
        string $rulesetId,
        string $rulesetHash,
        string $triggerEventHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_deadlines
                (supplier_id, environment, obligation_id, deadline_kind,
                 earliest_submission_on, due_on, calendar_basis,
                 fiction_delivery_days, ruleset_id, ruleset_hash,
                 trigger_event_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $obligationId,
            $deadlineKind,
            $earliestSubmissionOn,
            $dueOn,
            $calendarBasis,
            $fictionDeliveryDays,
            $rulesetId,
            $rulesetHash,
            $triggerEventHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function obligationExistsForUpdate(
        int $supplierId,
        int $obligationId,
        string $environment = 'production',
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_obligations
               WHERE supplier_id = ? AND environment = ? AND id = ?
               FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $obligationId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array{
     *   id:int,environment:string,agenda_code:string,subject_type:string,
     *   subject_reference:string,period_start:string,period_end:string,
     *   obligation_kind:string,status:string,row_version:int,
     *   earliest_submission_on:string,due_on:string
     * }|null
     */
    public function lockObligation(
        int $supplierId,
        int $obligationId,
        string $environment,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.id, obligation.environment,
                    obligation.agenda_code, obligation.subject_type,
                    obligation.subject_reference, obligation.period_start,
                    obligation.period_end, obligation.obligation_kind,
                    obligation.status, obligation.row_version,
                    deadline.earliest_submission_on, deadline.due_on
               FROM payroll_obligations obligation
               JOIN payroll_submission_deadlines deadline
                 ON deadline.supplier_id = obligation.supplier_id
                AND deadline.environment = obligation.environment
                AND deadline.obligation_id = obligation.id
                AND deadline.deadline_kind = "regular"
              WHERE obligation.supplier_id = ?
                AND obligation.environment = ?
                AND obligation.id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $obligationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'mzdovou povinnost');

        return [
            'id' => self::integer($row, 'id'),
            'environment' => self::string($row, 'environment'),
            'agenda_code' => self::string($row, 'agenda_code'),
            'subject_type' => self::string($row, 'subject_type'),
            'subject_reference' => self::string($row, 'subject_reference'),
            'period_start' => self::string($row, 'period_start'),
            'period_end' => self::string($row, 'period_end'),
            'obligation_kind' => self::string($row, 'obligation_kind'),
            'status' => self::string($row, 'status'),
            'row_version' => self::integer($row, 'row_version'),
            'earliest_submission_on' => self::string(
                $row,
                'earliest_submission_on',
            ),
            'due_on' => self::string($row, 'due_on'),
        ];
    }

    /**
     * @return array{
     *   id:int,status:string,row_version:int,request_fingerprint:string,
     *   source_snapshot_hash:string,submission_kind:string,channel:string,
     *   environment:string,obligation_id:int,corrects_submission_id:?int,
     *   correlation_reference:?string
     * }|null
     */
    public function findSubmissionByIdempotencyForUpdate(
        int $supplierId,
        string $idempotencyKeyHash,
        string $environment = 'production',
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, row_version, request_fingerprint,
                    source_snapshot_hash, submission_kind, channel,
                    environment, obligation_id, corrects_submission_id,
                    correlation_reference
               FROM payroll_submissions
              WHERE supplier_id = ? AND environment = ?
                AND idempotency_key_hash = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $idempotencyKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : self::submissionRow(
                self::associativeRow($row, 'mzdové podání'),
            );
    }

    /**
     * @return array{
     *   id:int,status:string,row_version:int,request_fingerprint:string,
     *   source_snapshot_hash:string,submission_kind:string,channel:string,
     *   environment:string,obligation_id:int,corrects_submission_id:?int,
     *   correlation_reference:?string
     * }|null
     */
    public function lockSubmission(
        int $supplierId,
        int $submissionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, row_version, request_fingerprint,
                    source_snapshot_hash, submission_kind, channel,
                    environment, obligation_id, corrects_submission_id,
                    correlation_reference
               FROM payroll_submissions
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $submissionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : self::submissionRow(
                self::associativeRow($row, 'mzdové podání'),
            );
    }

    /**
     * @return array{
     *   id:int,status:string,row_version:int,request_fingerprint:string,
     *   source_snapshot_hash:string,submission_kind:string,channel:string,
     *   environment:string,obligation_id:int,corrects_submission_id:?int,
     *   correlation_reference:?string
     * }|null
     */
    public function findSubmission(
        int $supplierId,
        int $submissionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, row_version, request_fingerprint,
                    source_snapshot_hash, submission_kind, channel,
                    environment, obligation_id, corrects_submission_id,
                    correlation_reference
               FROM payroll_submissions
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $submissionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : self::submissionRow(
                self::associativeRow($row, 'mzdové podání'),
            );
    }

    public function insertSubmission(
        int $supplierId,
        string $environment,
        int $obligationId,
        ?int $correctsSubmissionId,
        string $submissionKind,
        string $channel,
        ?int $sourceRevisionId,
        string $sourceSnapshotHash,
        string $requestFingerprint,
        string $idempotencyKeyHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id, corrects_submission_id,
                 submission_kind, channel, source_revision_id,
                 source_snapshot_hash, request_fingerprint,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $obligationId,
            $correctsSubmissionId,
            $submissionKind,
            $channel,
            $sourceRevisionId,
            $sourceSnapshotHash,
            $requestFingerprint,
            $idempotencyKeyHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertPart(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $partReference,
        string $agendaCode,
        string $subjectReference,
        string $sourceEntityType,
        string $sourceEntityReference,
        string $sourceSnapshotHash,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_parts
                (supplier_id, environment, submission_id, part_reference,
                 agenda_code, subject_reference, source_entity_type,
                 source_entity_reference, source_snapshot_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $partReference,
            $agendaCode,
            $subjectReference,
            $sourceEntityType,
            $sourceEntityReference,
            $sourceSnapshotHash,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function partBelongsToSubmission(
        int $supplierId,
        int $submissionId,
        int $partId,
        string $environment = 'production',
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_submission_parts
               WHERE supplier_id = ? AND environment = ?
                 AND submission_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $partId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array{id:int,status:string,row_version:int}|null */
    public function lockPart(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $partId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, row_version
               FROM payroll_submission_parts
              WHERE supplier_id = ?
                AND environment = ?
                AND submission_id = ?
                AND id = ?
              FOR UPDATE',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $partId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'část podání');

        return [
            'id' => self::integer($row, 'id'),
            'status' => self::string($row, 'status'),
            'row_version' => self::integer($row, 'row_version'),
        ];
    }

    public function updatePartStatus(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $partId,
        int $expectedRowVersion,
        string $status,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_submission_parts
                SET status = ?, row_version = row_version + 1
              WHERE supplier_id = ?
                AND environment = ?
                AND submission_id = ?
                AND id = ?
                AND row_version = ?',
        );
        $statement->execute([
            $status,
            $supplierId,
            $environment,
            $submissionId,
            $partId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new PayrollSubmissionConflictException(
                'Část podání se mezitím změnila.',
            );
        }
    }

    /**
     * @return array{
     *   id:int,submission_id:int,part_id:?int,artifact_kind:string,
     *   direction:string,mime_type:string,byte_size:int,
     *   artifact_sha256:string,xsd_version:?string,
     *   catalog_version:?string,channel:string,environment:string
     * }|null
     */
    public function findArtifactByIdempotencyForUpdate(
        int $supplierId,
        string $idempotencyKeyHash,
        string $environment = 'production',
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, submission_id, part_id, artifact_kind,
                    direction, mime_type, byte_size, artifact_sha256,
                    xsd_version, catalog_version, channel, environment
               FROM payroll_submission_artifacts
              WHERE supplier_id = ? AND environment = ?
                AND idempotency_key_hash = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $idempotencyKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'artefakt podání');

        return [
            'id' => self::integer($row, 'id'),
            'submission_id' => self::integer($row, 'submission_id'),
            'part_id' => self::nullableInteger($row, 'part_id'),
            'artifact_kind' => self::string($row, 'artifact_kind'),
            'direction' => self::string($row, 'direction'),
            'mime_type' => self::string($row, 'mime_type'),
            'byte_size' => self::integer($row, 'byte_size'),
            'artifact_sha256' => self::hash($row, 'artifact_sha256'),
            'xsd_version' => self::nullableString($row, 'xsd_version'),
            'catalog_version' => self::nullableString(
                $row,
                'catalog_version',
            ),
            'channel' => self::string($row, 'channel'),
            'environment' => self::string($row, 'environment'),
        ];
    }

    public function insertArtifact(
        int $supplierId,
        string $environment,
        int $submissionId,
        ?int $partId,
        string $artifactKind,
        string $direction,
        string $mimeType,
        string $contentCiphertext,
        int $byteSize,
        string $artifactSha256,
        ?string $xsdVersion,
        ?string $catalogVersion,
        string $channel,
        string $idempotencyKeyHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_artifacts
                (supplier_id, environment, submission_id, part_id, artifact_kind,
                 direction, mime_type, content_ciphertext, byte_size,
                 artifact_sha256, xsd_version, catalog_version, channel,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $partId,
            $artifactKind,
            $direction,
            $mimeType,
            $contentCiphertext,
            $byteSize,
            $artifactSha256,
            $xsdVersion,
            $catalogVersion,
            $channel,
            $idempotencyKeyHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * ID zmrazené datové věty podání.
     *
     * Běh na pozadí nemá od uživatele nic — ani variabilní symbol, kterým se
     * VREP ptá na výsledek. Jediný zdroj, který ho nese v podobě, jakou ČSSZ
     * skutečně dostala, je artefakt odeslaného XML; dohledávat symbol jinde
     * (nastavení pracovišť) by mohlo tiše sáhnout po jiném.
     *
     * Bere se NEJSTARŠÍ odchozí XML, protože to je ten dokument, který se
     * zmrazil a odeslal.
     */
    public function findOutboundXmlArtifactId(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): ?int {
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_submission_artifacts
              WHERE supplier_id = ?
                AND environment = ?
                AND submission_id = ?
                AND artifact_kind = "outbound_xml"
                AND direction = "outbound"
              ORDER BY id
              LIMIT 1',
        );
        $statement->execute([$supplierId, $environment, $submissionId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Povinnost a její stav podle podání — bez zámku, pro čtení na pozadí.
     *
     * @return array{
     *   id:int,status:string,row_version:int,agenda_code:string,
     *   subject_type:string,subject_reference:string,
     *   period_start:string,period_end:string
     * }|null
     */
    public function findObligationOfSubmission(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.id, obligation.status, obligation.row_version,
                    obligation.agenda_code, obligation.subject_type,
                    obligation.subject_reference, obligation.period_start,
                    obligation.period_end
               FROM payroll_submissions submission
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
              WHERE submission.supplier_id = ?
                AND submission.environment = ?
                AND submission.id = ?',
        );
        $statement->execute([$supplierId, $environment, $submissionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'row_version' => (int) $row['row_version'],
            'agenda_code' => (string) $row['agenda_code'],
            'subject_type' => (string) $row['subject_type'],
            'subject_reference' => (string) $row['subject_reference'],
            'period_start' => (string) $row['period_start'],
            'period_end' => (string) $row['period_end'],
        ];
    }

    /**
     * @return array{
     *   id:int,submission_id:int,part_id:?int,content_ciphertext:string,
     *   byte_size:int,artifact_sha256:string,environment:string,
     *   artifact_kind:string,direction:string,mime_type:string,
     *   xsd_version:?string,catalog_version:?string,channel:string
     * }|null
     */
    public function findArtifact(int $supplierId, int $artifactId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, submission_id, part_id, content_ciphertext,
                    byte_size, artifact_sha256, environment, artifact_kind,
                    direction, mime_type, xsd_version, catalog_version, channel
               FROM payroll_submission_artifacts
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $artifactId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'artefakt podání');

        return [
            'id' => self::integer($row, 'id'),
            'submission_id' => self::integer($row, 'submission_id'),
            'part_id' => self::nullableInteger($row, 'part_id'),
            'content_ciphertext' => self::string(
                $row,
                'content_ciphertext',
            ),
            'byte_size' => self::integer($row, 'byte_size'),
            'artifact_sha256' => self::hash($row, 'artifact_sha256'),
            'environment' => self::string($row, 'environment'),
            'artifact_kind' => self::string($row, 'artifact_kind'),
            'direction' => self::string($row, 'direction'),
            'mime_type' => self::string($row, 'mime_type'),
            'xsd_version' => self::nullableString($row, 'xsd_version'),
            'catalog_version' => self::nullableString(
                $row,
                'catalog_version',
            ),
            'channel' => self::string($row, 'channel'),
        ];
    }

    /**
     * @return array{
     *   id:int,submission_id:int,artifact_id:int,remote_status:?string,
     *   summary_hash:string,request_fingerprint:string
     * }|null
     */
    public function findReceiptByIdempotencyForUpdate(
        int $supplierId,
        string $idempotencyKeyHash,
        string $environment = 'production',
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, submission_id, artifact_id,
                    remote_status, summary_hash, request_fingerprint
               FROM payroll_submission_receipts
              WHERE supplier_id = ? AND environment = ?
                AND idempotency_key_hash = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $idempotencyKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'protokol podání');

        return [
            'id' => self::integer($row, 'id'),
            'submission_id' => self::integer($row, 'submission_id'),
            'artifact_id' => self::integer($row, 'artifact_id'),
            'remote_status' => self::nullableString($row, 'remote_status'),
            'summary_hash' => self::hash($row, 'summary_hash'),
            'request_fingerprint' => self::hash(
                $row,
                'request_fingerprint',
            ),
        ];
    }

    public function insertReceipt(
        int $supplierId,
        string $environment,
        int $submissionId,
        ?int $partId,
        int $artifactId,
        string $receiptReference,
        ?string $correlationReference,
        string $protocolCode,
        ?string $remoteStatus,
        string $verificationStatus,
        string $summaryHash,
        string $requestFingerprint,
        string $idempotencyKeyHash,
        string $receivedAt,
        ?int $importedBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_receipts
                (supplier_id, environment, submission_id, part_id, artifact_id,
                  receipt_reference, correlation_reference, protocol_code,
                  remote_status, verification_status, summary_hash,
                  request_fingerprint, idempotency_key_hash,
                  received_at, imported_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $partId,
            $artifactId,
            $receiptReference,
            $correlationReference,
            $protocolCode,
            $remoteStatus,
            $verificationStatus,
            $summaryHash,
            $requestFingerprint,
            $idempotencyKeyHash,
            $receivedAt,
            $importedBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function updateSubmissionStatus(
        int $supplierId,
        int $submissionId,
        int $expectedRowVersion,
        string $status,
        ?string $correlationReference,
        ?string $submittedAt,
        ?string $decidedAt,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_submissions
                SET status = ?,
                    correlation_reference =
                      COALESCE(?, correlation_reference),
                    submitted_at = COALESCE(?, submitted_at),
                    decided_at = COALESCE(?, decided_at),
                    row_version = row_version + 1
              WHERE supplier_id = ?
                AND id = ?
                AND row_version = ?',
        );
        $statement->execute([
            $status,
            $correlationReference,
            $submittedAt,
            $decidedAt,
            $supplierId,
            $submissionId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new PayrollSubmissionConflictException(
                'Podání se mezitím změnilo.',
            );
        }

        return $expectedRowVersion + 1;
    }

    public function bumpSubmissionVersion(
        int $supplierId,
        int $submissionId,
        int $expectedRowVersion,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_submissions
                SET row_version = row_version + 1
              WHERE supplier_id = ?
                AND id = ?
                AND row_version = ?',
        );
        $statement->execute([
            $supplierId,
            $submissionId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new PayrollSubmissionConflictException(
                'Podání se mezitím změnilo.',
            );
        }

        return $expectedRowVersion + 1;
    }

    public function updateObligationStatus(
        int $supplierId,
        string $environment,
        int $obligationId,
        int $expectedRowVersion,
        string $status,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_obligations
                SET status = ?, row_version = row_version + 1
              WHERE supplier_id = ?
                AND environment = ?
                AND id = ?
                AND row_version = ?',
        );
        $statement->execute([
            $status,
            $supplierId,
            $environment,
            $obligationId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new PayrollSubmissionConflictException(
                'Povinnost podání se mezitím změnila.',
            );
        }
    }

    public function insertIssue(
        int $supplierId,
        string $environment,
        int $submissionId,
        ?int $partId,
        string $severity,
        string $validationStage,
        string $issueCode,
        ?string $entityType,
        ?string $entityReference,
        ?string $detailsCiphertext,
        ?string $detailsHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_issues
                (supplier_id, environment, submission_id, part_id, severity,
                 validation_stage, issue_code, entity_type,
                 entity_reference, details_ciphertext, details_hash,
                 created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $partId,
            $severity,
            $validationStage,
            $issueCode,
            $entityType,
            $entityReference,
            $detailsCiphertext,
            $detailsHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertAgendaMatrix(
        int $supplierId,
        string $agendaCode,
        string $validFrom,
        ?string $validTo,
        string $replacementMode,
        string $rulesetId,
        string $rulesetHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_agenda_matrix
                (supplier_id, agenda_code, valid_from, valid_to,
                 replacement_mode, ruleset_id, ruleset_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $agendaCode,
            $validFrom,
            $validTo,
            $replacementMode,
            $rulesetId,
            $rulesetHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array{
     *   id:int,valid_to:?string,replacement_mode:string,
     *   ruleset_id:string,ruleset_hash:string
     * }|null
     */
    public function findAgendaMatrixByStartForUpdate(
        int $supplierId,
        string $agendaCode,
        string $validFrom,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, valid_to, replacement_mode, ruleset_id, ruleset_hash
               FROM payroll_agenda_matrix
              WHERE supplier_id = ?
                AND agenda_code = ?
                AND valid_from = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $agendaCode, $validFrom]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'řádek matice agendy');

        return [
            'id' => self::integer($row, 'id'),
            'valid_to' => self::nullableString($row, 'valid_to'),
            'replacement_mode' => self::string($row, 'replacement_mode'),
            'ruleset_id' => self::string($row, 'ruleset_id'),
            'ruleset_hash' => self::hash($row, 'ruleset_hash'),
        ];
    }

    public function agendaMatrixOverlapsForUpdate(
        int $supplierId,
        string $agendaCode,
        string $validFrom,
        ?string $validTo,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_agenda_matrix
              WHERE supplier_id = ?
                AND agenda_code = ?
                AND valid_from <= COALESCE(?, "9999-12-31")
                AND COALESCE(valid_to, "9999-12-31") >= ?
              LIMIT 1
              FOR UPDATE',
        );
        $statement->execute([
            $supplierId,
            $agendaCode,
            $validTo,
            $validFrom,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function effectiveAgendaReplacementMode(
        int $supplierId,
        string $agendaCode,
        string $onDate,
    ): ?string {
        $statement = $this->db->pdo()->prepare(
            'SELECT replacement_mode
               FROM payroll_agenda_matrix
              WHERE supplier_id = ?
                AND agenda_code = ?
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC
              LIMIT 1',
        );
        $statement->execute([
            $supplierId,
            $agendaCode,
            $onDate,
            $onDate,
        ]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{
     *   status:string,period_start:string,result_snapshot_hash:?string,
     *   run_id:int,office_id:?int
     * }|null
     */
    public function approvedRevisionScope(
        int $supplierId,
        int $revisionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.status, run.period_start,
                    revision.result_snapshot_hash, run.id AS run_id,
                    run.office_id
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND revision.id = ?',
        );
        $statement->execute([$supplierId, $revisionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'zdrojovou revizi podání');

        return [
            'status' => self::string($row, 'status'),
            'period_start' => self::string($row, 'period_start'),
            'result_snapshot_hash' => self::nullableString(
                $row,
                'result_snapshot_hash',
            ),
            'run_id' => self::integer($row, 'run_id'),
            'office_id' => self::nullableInteger($row, 'office_id'),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   id:int,status:string,row_version:int,request_fingerprint:string,
     *   source_snapshot_hash:string,submission_kind:string,channel:string,
     *   environment:string,obligation_id:int,corrects_submission_id:?int,
     *   correlation_reference:?string
     * }
     */
    private static function submissionRow(array $row): array
    {
        return [
            'id' => self::integer($row, 'id'),
            'status' => self::string($row, 'status'),
            'row_version' => self::integer($row, 'row_version'),
            'request_fingerprint' => self::hash(
                $row,
                'request_fingerprint',
            ),
            'source_snapshot_hash' => self::hash(
                $row,
                'source_snapshot_hash',
            ),
            'submission_kind' => self::string($row, 'submission_kind'),
            'channel' => self::string($row, 'channel'),
            'environment' => self::string($row, 'environment'),
            'obligation_id' => self::integer($row, 'obligation_id'),
            'corrects_submission_id' => self::nullableInteger(
                $row,
                'corrects_submission_id',
            ),
            'correlation_reference' => self::nullableString(
                $row,
                'correlation_reference',
            ),
        ];
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
