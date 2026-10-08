<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Evidence ručních potvrzení přijetí podání
 * ({@see \MyInvoice\Service\Payroll\Submission\PayrollSubmissionManualAcceptanceService}).
 *
 * Rozpor s pozdějším ověřeným protokolem se neukládá sem (řádky jsou neměnné),
 * ale jako problém podání s kódem {@see self::CONTRADICTION_ISSUE_CODE}.
 * Odkaz `entity_reference` má tvar `{id ručního potvrzení}:{id protokolu}`,
 * takže jde dohledat, KTERÉ potvrzení KTERÝ protokol vyvrátil.
 */
final class PayrollSubmissionManualAcceptanceRepository
{
    public const CONTRADICTION_ISSUE_CODE = 'manual_acceptance_contradicted';
    public const CONTRADICTION_ENTITY_TYPE = 'manual_acceptance';

    public function __construct(private readonly Connection $db) {}

    /** @template T @param callable():T $callback @return T */
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
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    public function byIdempotencyForUpdate(int $supplierId, string $environment, string $hash): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT acceptance.*, recorder.name AS recorded_by_name
               FROM payroll_submission_manual_acceptances acceptance
               LEFT JOIN users recorder ON recorder.id = acceptance.recorded_by
              WHERE acceptance.supplier_id = ?
                AND acceptance.environment = ?
                AND acceptance.idempotency_key_hash = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::acceptance($row) : null;
    }

    /** @param array<string,mixed> $row */
    public function insert(array $row): int
    {
        $columns = array_keys($row);
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_manual_acceptances ('
                . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')',
        );
        $statement->execute(array_values($row));

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return list<array<string,mixed>> od nejstaršího */
    public function history(int $supplierId, string $environment, int $submissionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT acceptance.*, recorder.name AS recorded_by_name
               FROM payroll_submission_manual_acceptances acceptance
               LEFT JOIN users recorder ON recorder.id = acceptance.recorded_by
              WHERE acceptance.supplier_id = ?
                AND acceptance.environment = ?
                AND acceptance.submission_id = ?
              ORDER BY acceptance.id',
        );
        $statement->execute([$supplierId, $environment, $submissionId]);

        return array_map(
            self::acceptance(...),
            $statement->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    /** @return array<string,mixed>|null */
    public function latest(int $supplierId, string $environment, int $submissionId): ?array
    {
        return $this->latestBySubmissions($supplierId, $environment, [$submissionId])[$submissionId] ?? null;
    }

    /**
     * Poslední ruční potvrzení ke každému podání — jedním dotazem pro celou
     * stránku přehledu, ne v cyklu.
     *
     * @param list<int> $submissionIds
     * @return array<int,array<string,mixed>> `submission_id` → řádek
     */
    public function latestBySubmissions(int $supplierId, string $environment, array $submissionIds): array
    {
        $submissionIds = self::positiveIds($submissionIds);
        if ($submissionIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($submissionIds), '?'));
        $statement = $this->db->pdo()->prepare(
            'WITH ranked AS (
                SELECT acceptance.*,
                       ROW_NUMBER() OVER (
                         PARTITION BY acceptance.submission_id
                         ORDER BY acceptance.id DESC
                       ) AS position
                  FROM payroll_submission_manual_acceptances acceptance
                 WHERE acceptance.supplier_id = ?
                   AND acceptance.environment = ?
                   AND acceptance.submission_id IN (' . $placeholders . ')
             )
             SELECT ranked.*, recorder.name AS recorded_by_name
               FROM ranked
               LEFT JOIN users recorder ON recorder.id = ranked.recorded_by
              WHERE ranked.position = 1',
        );
        $statement->execute([$supplierId, $environment, ...$submissionIds]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            unset($row['position']);
            $acceptance = self::acceptance($row);
            $result[$acceptance['submission_id']] = $acceptance;
        }

        return $result;
    }

    /**
     * Rozpory ručních potvrzení s ověřenými protokoly.
     *
     * @param list<int> $acceptanceIds
     * @return array<int,array{issue_id:int,receipt_id:int,remote_status:?string,received_at:string,is_resolved:bool}>
     *         `id ručního potvrzení` → rozpor
     */
    public function contradictions(int $supplierId, string $environment, array $acceptanceIds): array
    {
        $acceptanceIds = self::positiveIds($acceptanceIds);
        if ($acceptanceIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($acceptanceIds), '?'));
        $statement = $this->db->pdo()->prepare(
            'WITH links AS (
                SELECT issue.id AS issue_id,
                       issue.is_resolved,
                       CAST(SUBSTRING_INDEX(issue.entity_reference, ":", 1) AS UNSIGNED) AS acceptance_id,
                       CAST(SUBSTRING_INDEX(issue.entity_reference, ":", -1) AS UNSIGNED) AS receipt_id
                  FROM payroll_submission_issues issue
                 WHERE issue.supplier_id = ?
                   AND issue.environment = ?
                   AND issue.issue_code = ?
                   AND issue.entity_type = ?
             )
             SELECT links.issue_id, links.is_resolved, links.acceptance_id,
                    links.receipt_id, receipt.remote_status, receipt.received_at
               FROM links
               JOIN payroll_submission_receipts receipt
                 ON receipt.supplier_id = ?
                AND receipt.environment = ?
                AND receipt.id = links.receipt_id
              WHERE links.acceptance_id IN (' . $placeholders . ')
              ORDER BY links.issue_id',
        );
        $statement->execute([
            $supplierId,
            $environment,
            self::CONTRADICTION_ISSUE_CODE,
            self::CONTRADICTION_ENTITY_TYPE,
            $supplierId,
            $environment,
            ...$acceptanceIds,
        ]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[(int) $row['acceptance_id']] = [
                'issue_id' => (int) $row['issue_id'],
                'receipt_id' => (int) $row['receipt_id'],
                'remote_status' => $row['remote_status'] === null ? null : (string) $row['remote_status'],
                'received_at' => (string) $row['received_at'],
                'is_resolved' => (bool) $row['is_resolved'],
            ];
        }

        return $result;
    }

    /**
     * Uzavře otevřené rozpory podání, když je účetní vědomě přebije novým
     * ručním potvrzením. Rozpor v historii zůstává, jen přestane svítit.
     */
    public function resolveOpenContradictions(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $resolvedBy,
        string $resolvedAt,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_submission_issues
                SET is_resolved = 1,
                    resolved_by = ?,
                    resolved_at = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ?
                AND environment = ?
                AND submission_id = ?
                AND issue_code = ?
                AND entity_type = ?
                AND is_resolved = 0',
        );
        $statement->execute([
            $resolvedBy,
            $resolvedAt,
            $supplierId,
            $environment,
            $submissionId,
            self::CONTRADICTION_ISSUE_CODE,
            self::CONTRADICTION_ENTITY_TYPE,
        ]);

        return $statement->rowCount();
    }

    /** @param list<int> $ids @return list<int> */
    private static function positiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function acceptance(array $row): array
    {
        foreach ([
            'id',
            'supplier_id',
            'submission_id',
            'obligation_id',
            'submission_row_version_before',
            'recorded_by',
        ] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['attachment_artifact_id'] = $row['attachment_artifact_id'] === null
            ? null
            : (int) $row['attachment_artifact_id'];
        $row['superseded_predecessor'] = (bool) $row['superseded_predecessor'];
        $row['recorded_by_name'] = isset($row['recorded_by_name'])
            ? (string) $row['recorded_by_name']
            : null;

        return $row;
    }
}
