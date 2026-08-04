<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunInputSnapshot;
use PDO;

final class PayrollRunRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, ?string $periodStart = null): array
    {
        $sql = 'SELECT run.*,
                       revision.id AS revision_id,
                       revision.revision_no,
                       revision.status AS revision_status,
                       revision.input_snapshot_json,
                       revision.result_snapshot_json,
                       revision.calculated_by,
                       revision.reviewed_by,
                       revision.approved_by
                  FROM payroll_runs run
             LEFT JOIN payroll_run_revisions revision
                    ON revision.supplier_id = run.supplier_id
                   AND revision.run_id = run.id
                   AND revision.revision_no = run.current_revision_no
                 WHERE run.supplier_id = ?';
        $params = [$supplierId];
        if ($periodStart !== null) {
            $sql .= ' AND run.period_start = ?';
            $params[] = $periodStart;
        }
        $sql .= ' ORDER BY run.period_start DESC, run.office_scope_id, run.id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $run = self::castRun($row);
            $run['revision_id'] = $row['revision_id'] === null
                ? null
                : (int) $row['revision_id'];
            $run['revision_no'] = $row['revision_no'] === null
                ? null
                : (int) $row['revision_no'];
            $run['revision_status'] = $row['revision_status'];
            $inputSnapshot = $row['input_snapshot_json'] === null
                ? null
                : json_decode(
                    (string) $row['input_snapshot_json'],
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            $run['payment_materialization_supported'] =
                self::supportsPaymentMaterialization($inputSnapshot);
            $run['result_snapshot'] = $row['result_snapshot_json'] === null
                ? null
                : json_decode(
                    (string) $row['result_snapshot_json'],
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            foreach (['calculated_by', 'reviewed_by', 'approved_by'] as $field) {
                $run[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
            unset($run['result_snapshot_json']);
            unset($run['input_snapshot_json']);
            $items[] = $run;
        }
        return $items;
    }

    /** @return array<string,mixed> */
    public function createOrGet(
        int $supplierId,
        string $periodStart,
        string $paymentDate,
        ?int $officeId,
        ?int $actorUserId,
    ): array {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, office_id, period_start, payment_date,
                 created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $stmt->execute([
            $supplierId,
            $officeId,
            $periodStart,
            $paymentDate,
            $actorUserId,
            $actorUserId,
        ]);
        $id = (int) $pdo->lastInsertId();
        if ($id <= 0) {
            $find = $pdo->prepare(
                'SELECT id FROM payroll_runs
                  WHERE supplier_id = ? AND period_start = ?
                    AND office_scope_id = COALESCE(?, 0)'
            );
            $find->execute([$supplierId, $periodStart, $officeId]);
            $id = (int) $find->fetchColumn();
        }
        $run = $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Mzdový běh se nepodařilo načíst.');
        if ((string) $run['payment_date'] !== $paymentDate) {
            throw new \DomainException(
                'Mzdový běh pro období už existuje s jiným datem výplaty.',
            );
        }
        if ($stmt->rowCount() === 1) {
            $this->insertEvent(
                $supplierId,
                $id,
                null,
                'created',
                null,
                'draft',
                $actorUserId,
                null,
                [
                    'period_start' => $periodStart,
                    'payment_date' => $paymentDate,
                    'office_id' => $officeId,
                ],
            );
        }
        return $run;
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $runId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_runs WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castRun($row);
    }

    /** @return array<string,mixed>|null */
    public function lock(int $supplierId, int $runId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_runs
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castRun($row);
    }

    /** @return array<string,mixed>|null */
    public function currentRevision(int $supplierId, int $runId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT revision.*
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
              WHERE run.supplier_id = ? AND run.id = ?'
        );
        $stmt->execute([$supplierId, $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castRevision($row);
    }

    /** @return array<string,mixed>|null */
    public function revision(int $supplierId, int $revisionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_run_revisions WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $revisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castRevision($row);
    }

    /** @return list<array<string,mixed>> */
    public function revisions(int $supplierId, int $runId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_run_revisions
              WHERE supplier_id = ? AND run_id = ?
              ORDER BY revision_no'
        );
        $stmt->execute([$supplierId, $runId]);
        return array_values(array_map(
            self::castRevision(...),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return list<array<string,mixed>> */
    public function events(int $supplierId, int $runId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_run_events
              WHERE supplier_id = ? AND run_id = ?
              ORDER BY id'
        );
        $stmt->execute([$supplierId, $runId]);
        return array_values(array_map(
            static function (array $row): array {
                $row['id'] = (int) $row['id'];
                $row['run_id'] = (int) $row['run_id'];
                $row['revision_id'] = $row['revision_id'] === null
                    ? null
                    : (int) $row['revision_id'];
                $row['actor_user_id'] = $row['actor_user_id'] === null
                    ? null
                    : (int) $row['actor_user_id'];
                $row['metadata'] = json_decode(
                    (string) $row['metadata_json'],
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                return $row;
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return list<array<string,mixed>> */
    public function validations(int $supplierId, int $revisionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ?
              ORDER BY FIELD(severity, "blocker", "warning", "info"), id'
        );
        $stmt->execute([$supplierId, $revisionId]);
        return array_values(array_map(
            static function (array $row): array {
                foreach (['id', 'revision_id'] as $field) {
                    $row[$field] = (int) $row[$field];
                }
                $row['entity_id'] = $row['entity_id'] === null
                    ? null
                    : (int) $row['entity_id'];
                $row['requires_override'] = (bool) $row['requires_override'];
                return $row;
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return array{blockers:int,unresolved_overrides:int} */
    public function validationCounts(int $supplierId, int $revisionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                SUM(severity = "blocker") AS blockers,
                SUM(
                    severity = "warning"
                    AND requires_override = 1
                    AND overridden_at IS NULL
                ) AS unresolved_overrides
               FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ?'
        );
        $stmt->execute([$supplierId, $revisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'blockers' => (int) ($row['blockers'] ?? 0),
            'unresolved_overrides' => (int) ($row['unresolved_overrides'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $result
     */
    public function replaceEnforcementValidations(
        int $supplierId,
        int $revisionId,
        array $result,
    ): void {
        $delete = $this->db->pdo()->prepare(
            'DELETE FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ?
                AND code = "enforcement_manual_review"'
        );
        $delete->execute([$supplierId, $revisionId]);
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_validations
                (supplier_id, revision_id, severity, code, entity_type,
                 entity_id, message, remediation_path, requires_override)
             VALUES (?, ?, "blocker", "enforcement_manual_review", "employee",
                     ?, ?, "/payroll/enforcement", 0)'
        );
        foreach ($result['people'] ?? [] as $person) {
            if (!is_array($person) || array_is_list($person)) {
                throw new \UnexpectedValueException('Výsledek osoby není platný.');
            }
            $enforcement = $person['enforcement'] ?? null;
            $enforcementResult = is_array($enforcement)
                ? ($enforcement['result'] ?? null)
                : null;
            if (!is_array($enforcementResult)
                || ($enforcementResult['status'] ?? null) !== 'manual_review'
            ) {
                continue;
            }
            $issues = $enforcementResult['issues'] ?? [];
            $message = 'Exekuční srážka vyžaduje doplnění nebo kontrolu podkladů.';
            if (is_array($issues) && $issues !== []) {
                $message .= ' ' . implode(', ', array_filter(
                    $issues,
                    static fn (mixed $issue): bool => is_string($issue),
                ));
            }
            $insert->execute([
                $supplierId,
                $revisionId,
                (int) ($person['employee_id'] ?? 0),
                mb_substr($message, 0, 500),
            ]);
        }
    }

    /** @param array<string,mixed> $result */
    public function replaceStatutoryValidations(
        int $supplierId,
        int $revisionId,
        array $result,
    ): void {
        $delete = $this->db->pdo()->prepare(
            'DELETE FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ?
                AND code = "statutory_calculation_manual_review"'
        );
        $delete->execute([$supplierId, $revisionId]);
        $statutory = $result['statutory'] ?? null;
        if (!is_array($statutory) || array_is_list($statutory)
            || ($statutory['status'] ?? null) === 'calculated'
        ) {
            return;
        }
        $issues = $statutory['issues'] ?? [];
        $message = 'Zákonný výpočet pojistného, daně nebo čisté mzdy vyžaduje kontrolu.';
        if (is_array($issues) && $issues !== []) {
            $message .= ' ' . implode(', ', array_filter(
                $issues,
                static fn (mixed $issue): bool => is_string($issue),
            ));
        }
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_validations
                (supplier_id, revision_id, severity, code, entity_type,
                 entity_id, message, remediation_path, requires_override)
             VALUES (?, ?, "blocker", "statutory_calculation_manual_review",
                     "run", NULL, ?, "/payroll/runs", 0)'
        );
        $insert->execute([$supplierId, $revisionId, mb_substr($message, 0, 500)]);
    }

    /** @return array<string,mixed>|null */
    public function commandReceipt(int $supplierId, string $keyHashBinary): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_run_commands
              WHERE supplier_id = ? AND idempotency_key_hash = ?'
        );
        $stmt->execute([$supplierId, $keyHashBinary]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        foreach (['id', 'run_id', 'expected_row_version'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['revision_id'] = $row['revision_id'] === null
            ? null
            : (int) $row['revision_id'];
        $row['result'] = json_decode(
            (string) $row['result_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        return $row;
    }

    public function insertRevision(
        int $supplierId,
        int $runId,
        int $revisionNo,
        ?int $previousRevisionId,
        string $kind,
        PayrollRunInputSnapshot $snapshot,
        string $idempotencyKeyHash,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version, ruleset_manifest_hash,
                 input_snapshot_json, input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?, "snapshot", ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $runId,
            $revisionNo,
            $previousRevisionId,
            $kind,
            (string) $snapshot->data['schema_version'],
            $snapshot->rulesetManifestHash,
            $snapshot->json,
            $snapshot->hash,
            $idempotencyKeyHash,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertSnapshotGraph(
        int $supplierId,
        int $revisionId,
        PayrollRunInputSnapshot $snapshot,
    ): void {
        $personInsert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id)
             VALUES (?, ?, ?)'
        );
        $employmentInsert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id,
                 input_json, input_hash)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($snapshot->data['people'] as $person) {
            if (!is_array($person) || !is_array($person['employee'] ?? null)) {
                throw new \UnexpectedValueException('Snapshot osoby není platný.');
            }
            $employeeId = (int) $person['employee']['id'];
            $personInsert->execute([$supplierId, $revisionId, $employeeId]);
            foreach ($person['employments'] ?? [] as $employment) {
                if (!is_array($employment)
                    || !is_array($employment['employment'] ?? null)
                ) {
                    throw new \UnexpectedValueException('Snapshot vztahu není platný.');
                }
                $json = CanonicalJson::encode($employment);
                $employmentInsert->execute([
                    $supplierId,
                    $revisionId,
                    $employeeId,
                    (int) $employment['employment']['id'],
                    $json,
                    hash('sha256', $json),
                ]);
            }
        }
        $validationInsert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_validations
                (supplier_id, revision_id, severity, code, entity_type,
                 entity_id, message, remediation_path, requires_override)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($snapshot->validations as $validation) {
            $validationInsert->execute([
                $supplierId,
                $revisionId,
                $validation->severity,
                $validation->code,
                $validation->entityType,
                $validation->entityId,
                $validation->message,
                $validation->remediationPath,
                $validation->requiresOverride ? 1 : 0,
            ]);
        }
    }

    public function lockApprovedInputs(
        int $supplierId,
        int $revisionId,
        string $periodStart,
        ?int $officeId,
    ): void {
        $officeSql = $officeId === null ? '1 = 1' : 'employment.office_id = ?';
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_inputs input
               JOIN payroll_employments employment
                 ON employment.supplier_id = input.supplier_id
                AND employment.id = input.employment_id
               JOIN payroll_run_employments frozen
                 ON frozen.supplier_id = input.supplier_id
                AND frozen.revision_id = ?
                AND frozen.employment_id = input.employment_id
                SET input.status = "locked",
                    input.row_version = input.row_version + 1
              WHERE input.supplier_id = ?
                AND input.period_start = ?
                AND input.status = "approved"
                AND ' . $officeSql
        );
        $stmt->execute([
            $revisionId,
            $supplierId,
            $periodStart,
            ...($officeId === null ? [] : [$officeId]),
        ]);
    }

    /**
     * @param array<string,mixed> $result
     */
    public function saveCalculation(
        int $supplierId,
        int $revisionId,
        array $result,
        int $actorUserId,
    ): void {
        $json = CanonicalJson::encode($result);
        $hash = hash('sha256', $json);
        $updateRevision = $this->db->pdo()->prepare(
            'UPDATE payroll_run_revisions
                SET status = "calculated",
                    result_snapshot_json = ?,
                    result_snapshot_hash = ?,
                    calculated_by = ?,
                    calculated_at = NOW(),
                    reviewed_by = NULL,
                    reviewed_at = NULL
              WHERE supplier_id = ? AND id = ?
                AND status IN ("snapshot", "calculated", "reviewed")'
        );
        $updateRevision->execute([
            $json,
            $hash,
            $actorUserId,
            $supplierId,
            $revisionId,
        ]);
        if ($updateRevision->rowCount() !== 1) {
            throw new \DomainException('Revizi nelze v aktuálním stavu přepočítat.');
        }

        $employmentUpdate = $this->db->pdo()->prepare(
            'UPDATE payroll_run_employments
                SET result_json = ?, result_hash = ?, status = "calculated"
              WHERE supplier_id = ? AND revision_id = ? AND employment_id = ?'
        );
        $personUpdate = $this->db->pdo()->prepare(
            'UPDATE payroll_run_persons
                SET result_json = ?, result_hash = ?, status = "calculated"
              WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?'
        );
        foreach ($result['people'] ?? [] as $person) {
            if (!is_array($person)) {
                throw new \UnexpectedValueException('Výsledek osoby není platný.');
            }
            foreach ($person['employments'] ?? [] as $employment) {
                if (!is_array($employment)) {
                    throw new \UnexpectedValueException('Výsledek vztahu není platný.');
                }
                $employmentJson = CanonicalJson::encode($employment);
                $employmentUpdate->execute([
                    $employmentJson,
                    hash('sha256', $employmentJson),
                    $supplierId,
                    $revisionId,
                    (int) $employment['employment_id'],
                ]);
                if ($employmentUpdate->rowCount() !== 1) {
                    throw new \RuntimeException('Výsledek vztahu se nepodařilo uložit.');
                }
            }
            $personJson = CanonicalJson::encode($person);
            $personUpdate->execute([
                $personJson,
                hash('sha256', $personJson),
                $supplierId,
                $revisionId,
                (int) $person['employee_id'],
            ]);
            if ($personUpdate->rowCount() !== 1) {
                throw new \RuntimeException('Výsledek osoby se nepodařilo uložit.');
            }
        }
    }

    public function markRevisionReviewed(
        int $supplierId,
        int $revisionId,
        int $actorUserId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_run_revisions
                SET status = "reviewed", reviewed_by = ?, reviewed_at = NOW()
              WHERE supplier_id = ? AND id = ? AND status = "calculated"'
        );
        $stmt->execute([$actorUserId, $supplierId, $revisionId]);
        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Revizi nelze označit za zkontrolovanou.');
        }
    }

    public function markRevisionApproved(
        int $supplierId,
        int $revisionId,
        int $actorUserId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_run_revisions
                SET status = "approved", approved_by = ?, approved_at = NOW()
              WHERE supplier_id = ? AND id = ? AND status = "reviewed"'
        );
        $stmt->execute([$actorUserId, $supplierId, $revisionId]);
        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Revizi nelze schválit.');
        }
    }

    /** @return array<string,mixed> */
    public function updateRun(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $status,
        ?int $currentRevisionNo,
        int $actorUserId,
    ): array {
        $revisionSql = $currentRevisionNo === null
            ? ''
            : ', current_revision_no = ?';
        $params = [$status];
        if ($currentRevisionNo !== null) {
            $params[] = $currentRevisionNo;
        }
        $params = [
            ...$params,
            $actorUserId,
            $supplierId,
            $runId,
            $expectedVersion,
        ];
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_runs
                SET status = ?' . $revisionSql . ',
                    updated_by = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND row_version = ?'
        );
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            $current = $this->find($supplierId, $runId);
            throw new PayrollRunConflictException(
                $current === null ? 0 : (int) $current['row_version'],
            );
        }
        return $this->find($supplierId, $runId)
            ?? throw new \RuntimeException('Aktualizovaný mzdový běh nebyl nalezen.');
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function insertEvent(
        int $supplierId,
        int $runId,
        ?int $revisionId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?int $actorUserId,
        ?string $reason,
        array $metadata,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_events
                (supplier_id, run_id, revision_id, event_type, from_status,
                 to_status, actor_user_id, reason, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $runId,
            $revisionId,
            $eventType,
            $fromStatus,
            $toStatus,
            $actorUserId,
            $reason,
            CanonicalJson::encode($metadata),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $result
     */
    public function insertCommandReceipt(
        int $supplierId,
        int $runId,
        ?int $revisionId,
        string $commandName,
        string $keyHashBinary,
        string $requestHash,
        int $expectedVersion,
        string $fromStatus,
        string $toStatus,
        array $result,
        int $actorUserId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_commands
                (supplier_id, run_id, revision_id, command_name,
                 idempotency_key_hash, request_hash, expected_row_version,
                 from_status, to_status, result_json, actor_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $runId,
            $revisionId,
            $commandName,
            $keyHashBinary,
            $requestHash,
            $expectedVersion,
            $fromStatus,
            $toStatus,
            CanonicalJson::encode($result),
            $actorUserId,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castRun(array $row): array
    {
        foreach ([
            'id',
            'supplier_id',
            'office_scope_id',
            'current_revision_no',
            'row_version',
        ] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach (['office_id', 'created_by', 'updated_by'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castRevision(array $row): array
    {
        foreach (['id', 'supplier_id', 'run_id', 'revision_no'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach ([
            'previous_revision_id',
            'calculated_by',
            'reviewed_by',
            'approved_by',
        ] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        foreach (['input_snapshot_json', 'result_snapshot_json'] as $field) {
            $row[str_replace('_json', '', $field)] = $row[$field] === null
                ? null
                : json_decode(
                    (string) $row[$field],
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
        }
        unset($row['idempotency_key_hash']);
        return $row;
    }

    private static function supportsPaymentMaterialization(mixed $snapshot): bool
    {
        if (!is_array($snapshot) || array_is_list($snapshot)
            || ($snapshot['schema_version'] ?? null) !== 'payroll-run-input.v2'
        ) {
            return false;
        }
        $people = $snapshot['people'] ?? null;
        if (!is_array($people) || !array_is_list($people)) {
            return false;
        }
        foreach ($people as $person) {
            if (!is_array($person) || array_is_list($person)
                || !array_key_exists('payout_accounts', $person)
                || !is_array($person['payout_accounts'])
                || !array_is_list($person['payout_accounts'])
            ) {
                return false;
            }
        }

        return true;
    }
}
