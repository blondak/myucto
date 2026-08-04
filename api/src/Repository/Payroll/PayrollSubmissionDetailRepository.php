<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollSubmissionDetailRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   submission:array{
     *     id:int,environment:string,obligation_id:int,agenda_code:string,
     *     subject_type:string,subject_reference:string,period_start:string,
     *     period_end:string,submission_kind:string,channel:string,status:string,
     *     row_version:int,source_revision_id:?int,corrects_submission_id:?int,
     *     correlation_reference:?string,submitted_at:?string,decided_at:?string,
     *     created_at:string,updated_at:string
     *   },
     *   parts:list<array{
     *     id:int,part_reference:string,agenda_code:string,
     *     subject_reference:string,status:string,source_entity_type:string,
     *     source_entity_reference:string,row_version:int,created_at:string,
     *     updated_at:string
     *   }>,
     *   artifacts:list<array{
     *     id:int,part_id:?int,artifact_kind:string,direction:string,
     *     mime_type:string,byte_size:int,xsd_version:?string,
     *     catalog_version:?string,channel:string,created_at:string
     *   }>,
     *   receipts:list<array{
     *     id:int,part_id:?int,artifact_id:int,receipt_reference:string,
     *     correlation_reference:?string,protocol_code:string,
     *     remote_status:?string,verification_status:string,
     *     received_at:string,created_at:string
     *   }>,
     *   issues:list<array{
     *     id:int,part_id:?int,severity:string,validation_stage:string,
     *     issue_code:string,entity_type:?string,entity_reference:?string,
     *     is_resolved:bool,row_version:int,resolved_at:?string,
     *     created_at:string,updated_at:string
     *   }>
     * }|null
     */
    public function find(
        int $supplierId,
        int $submissionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT submission.id, submission.environment,
                    submission.obligation_id, obligation.agenda_code,
                    obligation.subject_type, obligation.subject_reference,
                    obligation.period_start, obligation.period_end,
                    submission.submission_kind, submission.channel,
                    submission.status, submission.row_version,
                    submission.source_revision_id,
                    submission.corrects_submission_id,
                    submission.correlation_reference,
                    submission.submitted_at, submission.decided_at,
                    submission.created_at, submission.updated_at
               FROM payroll_submissions submission
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
              WHERE submission.supplier_id = ? AND submission.id = ?',
        );
        $statement->execute([$supplierId, $submissionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'detail mzdového podání');

        return [
            'submission' => [
                'id' => self::integer($row, 'id'),
                'environment' => self::string($row, 'environment'),
                'obligation_id' => self::integer($row, 'obligation_id'),
                'agenda_code' => self::string($row, 'agenda_code'),
                'subject_type' => self::string($row, 'subject_type'),
                'subject_reference' => self::string(
                    $row,
                    'subject_reference',
                ),
                'period_start' => self::string($row, 'period_start'),
                'period_end' => self::string($row, 'period_end'),
                'submission_kind' => self::string(
                    $row,
                    'submission_kind',
                ),
                'channel' => self::string($row, 'channel'),
                'status' => self::string($row, 'status'),
                'row_version' => self::integer($row, 'row_version'),
                'source_revision_id' => self::nullableInteger(
                    $row,
                    'source_revision_id',
                ),
                'corrects_submission_id' => self::nullableInteger(
                    $row,
                    'corrects_submission_id',
                ),
                'correlation_reference' => self::nullableString(
                    $row,
                    'correlation_reference',
                ),
                'submitted_at' => self::nullableString(
                    $row,
                    'submitted_at',
                ),
                'decided_at' => self::nullableString($row, 'decided_at'),
                'created_at' => self::string($row, 'created_at'),
                'updated_at' => self::string($row, 'updated_at'),
            ],
            'parts' => $this->parts($supplierId, $submissionId),
            'artifacts' => $this->artifacts($supplierId, $submissionId),
            'receipts' => $this->receipts($supplierId, $submissionId),
            'issues' => $this->issues($supplierId, $submissionId),
        ];
    }

    /**
     * @return list<array{
     *   id:int,part_reference:string,agenda_code:string,
     *   subject_reference:string,status:string,source_entity_type:string,
     *   source_entity_reference:string,row_version:int,created_at:string,
     *   updated_at:string
     * }>
     */
    private function parts(int $supplierId, int $submissionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, part_reference, agenda_code, subject_reference,
                    status, source_entity_type, source_entity_reference,
                    row_version, created_at, updated_at
               FROM payroll_submission_parts
              WHERE supplier_id = ? AND submission_id = ?
              ORDER BY id ASC',
        );
        $statement->execute([$supplierId, $submissionId]);

        $result = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = self::associativeRow($row, 'část podání');
            $result[] = [
                'id' => self::integer($row, 'id'),
                'part_reference' => self::string($row, 'part_reference'),
                'agenda_code' => self::string($row, 'agenda_code'),
                'subject_reference' => self::string(
                    $row,
                    'subject_reference',
                ),
                'status' => self::string($row, 'status'),
                'source_entity_type' => self::string(
                    $row,
                    'source_entity_type',
                ),
                'source_entity_reference' => self::string(
                    $row,
                    'source_entity_reference',
                ),
                'row_version' => self::integer($row, 'row_version'),
                'created_at' => self::string($row, 'created_at'),
                'updated_at' => self::string($row, 'updated_at'),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{
     *   id:int,part_id:?int,artifact_kind:string,direction:string,
     *   mime_type:string,byte_size:int,xsd_version:?string,
     *   catalog_version:?string,channel:string,created_at:string
     * }>
     */
    private function artifacts(int $supplierId, int $submissionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, part_id, artifact_kind, direction, mime_type,
                    byte_size, xsd_version, catalog_version, channel,
                    created_at
               FROM payroll_submission_artifacts
              WHERE supplier_id = ? AND submission_id = ?
              ORDER BY id ASC',
        );
        $statement->execute([$supplierId, $submissionId]);

        $result = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = self::associativeRow($row, 'artefakt podání');
            $result[] = [
                'id' => self::integer($row, 'id'),
                'part_id' => self::nullableInteger($row, 'part_id'),
                'artifact_kind' => self::string($row, 'artifact_kind'),
                'direction' => self::string($row, 'direction'),
                'mime_type' => self::string($row, 'mime_type'),
                'byte_size' => self::integer($row, 'byte_size'),
                'xsd_version' => self::nullableString($row, 'xsd_version'),
                'catalog_version' => self::nullableString(
                    $row,
                    'catalog_version',
                ),
                'channel' => self::string($row, 'channel'),
                'created_at' => self::string($row, 'created_at'),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{
     *   id:int,part_id:?int,artifact_id:int,receipt_reference:string,
     *   correlation_reference:?string,protocol_code:string,
     *   remote_status:?string,verification_status:string,
     *   received_at:string,created_at:string
     * }>
     */
    private function receipts(int $supplierId, int $submissionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, part_id, artifact_id, receipt_reference,
                    correlation_reference, protocol_code, remote_status,
                    verification_status, received_at, created_at
               FROM payroll_submission_receipts
              WHERE supplier_id = ? AND submission_id = ?
              ORDER BY received_at DESC, id DESC',
        );
        $statement->execute([$supplierId, $submissionId]);

        $result = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = self::associativeRow($row, 'protokol podání');
            $result[] = [
                'id' => self::integer($row, 'id'),
                'part_id' => self::nullableInteger($row, 'part_id'),
                'artifact_id' => self::integer($row, 'artifact_id'),
                'receipt_reference' => self::string(
                    $row,
                    'receipt_reference',
                ),
                'correlation_reference' => self::nullableString(
                    $row,
                    'correlation_reference',
                ),
                'protocol_code' => self::string($row, 'protocol_code'),
                'remote_status' => self::nullableString(
                    $row,
                    'remote_status',
                ),
                'verification_status' => self::string(
                    $row,
                    'verification_status',
                ),
                'received_at' => self::string($row, 'received_at'),
                'created_at' => self::string($row, 'created_at'),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{
     *   id:int,part_id:?int,severity:string,validation_stage:string,
     *   issue_code:string,entity_type:?string,entity_reference:?string,
     *   is_resolved:bool,row_version:int,resolved_at:?string,
     *   created_at:string,updated_at:string
     * }>
     */
    private function issues(int $supplierId, int $submissionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, part_id, severity, validation_stage, issue_code,
                    entity_type, entity_reference, is_resolved, row_version,
                    resolved_at, created_at, updated_at
               FROM payroll_submission_issues
              WHERE supplier_id = ? AND submission_id = ?
              ORDER BY is_resolved ASC,
                       FIELD(severity, "blocker", "error", "warning", "info"),
                       id ASC',
        );
        $statement->execute([$supplierId, $submissionId]);

        $result = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = self::associativeRow($row, 'problém podání');
            $result[] = [
                'id' => self::integer($row, 'id'),
                'part_id' => self::nullableInteger($row, 'part_id'),
                'severity' => self::string($row, 'severity'),
                'validation_stage' => self::string(
                    $row,
                    'validation_stage',
                ),
                'issue_code' => self::string($row, 'issue_code'),
                'entity_type' => self::nullableString($row, 'entity_type'),
                'entity_reference' => self::nullableString(
                    $row,
                    'entity_reference',
                ),
                'is_resolved' => self::integer($row, 'is_resolved') === 1,
                'row_version' => self::integer($row, 'row_version'),
                'resolved_at' => self::nullableString($row, 'resolved_at'),
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
