<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\PayrollEmploymentAccountingClassifier;
use MyInvoice\Service\Payroll\PayrollEmploymentLifecycle;
use PDO;

/**
 * @phpstan-import-type TermsInput from \MyInvoice\Service\Payroll\PayrollEmploymentValidator
 * @phpstan-import-type EmploymentCreateInput from \MyInvoice\Service\Payroll\PayrollEmploymentValidator
 */
final class PayrollEmploymentRepository
{
    /** @var array<string,list<string>> */
    private const CHECKLISTS = [
        'onboarding' => [
            'employment_contract',
            'health_insurance_registration',
            'social_jmhz_registration',
            'tax_declaration',
        ],
        'change' => [
            'contract_amendment',
            'health_insurance_change',
            'social_jmhz_change',
        ],
        'offboarding' => [
            'termination_document',
            'health_insurance_deregistration',
            'social_jmhz_deregistration',
            'enforcement_insolvency_review',
            'later_income_review',
        ],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentLifecycle $lifecycle,
        private readonly PayrollEmploymentAccountingClassifier $accounting,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /** @return list<array<string,mixed>> */
    public function listForEmployee(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.employee_id, employment.office_id,
                    office.code AS office_code, office.name AS office_name,
                    employment.code, employment.relation_type, employment.status,
                    employment.is_primary, employment.start_date,
                    employment.actual_start_date, employment.end_date,
                    employment.archived_at, employment.is_legacy_projection,
                    employment.monthly_gross_minor, employment.row_version
               FROM payroll_employments employment
               LEFT JOIN payroll_offices office
                 ON office.supplier_id = employment.supplier_id
                AND office.id = employment.office_id
              WHERE employment.supplier_id = ? AND employment.employee_id = ?
              ORDER BY employment.is_primary DESC,
                       employment.is_legacy_projection DESC,
                       employment.start_date ASC,
                       employment.id ASC'
        );
        $stmt->execute([$supplierId, $employeeId]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = $this->row($fetched);
            $employmentId = (int) $row['id'];
            $relationType = (string) $row['relation_type'];
            $result[] = [
                'id' => $employmentId,
                'employee_id' => (int) $row['employee_id'],
                'office_id' => $row['office_id'] === null ? null : (int) $row['office_id'],
                'office_code' => $row['office_code'] === null ? null : (string) $row['office_code'],
                'office_name' => $row['office_name'] === null ? null : (string) $row['office_name'],
                'code' => (string) $row['code'],
                'relation_type' => $relationType,
                'status' => (string) $row['status'],
                'is_primary' => (bool) $row['is_primary'],
                'start_date' => $row['start_date'] === null ? null : (string) $row['start_date'],
                'actual_start_date' => $row['actual_start_date'] === null
                    ? null
                    : (string) $row['actual_start_date'],
                'end_date' => $row['end_date'] === null ? null : (string) $row['end_date'],
                'archived_at' => $row['archived_at'] === null ? null : (string) $row['archived_at'],
                'is_legacy_projection' => (bool) $row['is_legacy_projection'],
                'monthly_gross_minor' => $row['monthly_gross_minor'] === null
                    ? null
                    : (int) $row['monthly_gross_minor'],
                'row_version' => (int) $row['row_version'],
                'allowed_transitions' => $this->lifecycle->allowedTargets((string) $row['status']),
                'accounting' => ($this->accounting)($relationType),
                'terms' => $this->terms($supplierId, $employmentId),
                'checklist' => $this->checklist($supplierId, $employmentId),
                'timeline' => $this->events($supplierId, $employmentId),
            ];
        }
        return $result;
    }

    /** @param EmploymentCreateInput $data
     *  @return array<string,mixed>
     */
    public function create(
        int $supplierId,
        int $employeeId,
        array $data,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->transaction(function () use (
            $supplierId,
            $employeeId,
            $data,
            $userId,
            $ip,
            $userAgent,
        ): array {
            $this->lockEmployee($supplierId, $employeeId);
            $this->assertOffice($supplierId, $data['terms']['office_id']);
            $this->assertPrimaryAvailable($supplierId, $employeeId, $data['terms']['is_primary'], null);

            $stmt = $this->db->pdo()->prepare(
                "INSERT INTO payroll_employments
                    (supplier_id, employee_id, office_id, code, relation_type,
                     status, is_primary, start_date, actual_start_date, end_date,
                     monthly_gross_minor, is_legacy_projection, row_version)
                 VALUES (?, ?, ?, ?, ?, 'planned', ?, ?, ?, ?, ?, 0, 1)"
            );
            $stmt->execute([
                $supplierId,
                $employeeId,
                $data['terms']['office_id'],
                $data['code'],
                $data['relation_type'],
                (int) $data['terms']['is_primary'],
                $data['terms']['planned_start_on'],
                $data['terms']['actual_start_on'],
                $data['terms']['fixed_term_end_on'],
                $data['monthly_gross_minor'],
            ]);
            $employmentId = (int) $this->db->pdo()->lastInsertId();
            $this->insertTerms($supplierId, $employmentId, $data['terms'], $userId);
            $this->insertEvent(
                $supplierId,
                $employmentId,
                'created',
                null,
                'planned',
                $data['terms']['effective_from'],
                $data['terms']['change_reason'],
                ['relation_type' => ['from' => null, 'to' => $data['relation_type']]],
                $userId,
            );
            $this->ensureChecklist(
                $supplierId,
                $employmentId,
                'onboarding',
                $data['terms']['planned_start_on'],
            );
            $this->activityLogger->log(
                'payroll.employment.created',
                $userId,
                'payroll_employment',
                $employmentId,
                [
                    'employee_id' => $employeeId,
                    'relation_type' => $data['relation_type'],
                    'status' => 'planned',
                    'effective_from' => $data['terms']['effective_from'],
                ],
                $ip,
                $userAgent,
                $supplierId,
            );

            return $this->find($supplierId, $employeeId, $employmentId);
        });
    }

    /** @param TermsInput $data
     *  @return array<string,mixed>
     */
    public function addTerms(
        int $supplierId,
        int $employmentId,
        array $data,
        int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        bool $replaceMonthlyGross = false,
        ?int $monthlyGrossMinor = null,
    ): array {
        return $this->transaction(function () use (
            $supplierId,
            $employmentId,
            $data,
            $expectedVersion,
            $userId,
            $ip,
            $userAgent,
            $replaceMonthlyGross,
            $monthlyGrossMinor,
        ): array {
            $employment = $this->lockEmployment($supplierId, $employmentId, $expectedVersion);
            $employeeId = (int) $employment['employee_id'];
            $status = (string) $employment['status'];
            if (in_array($status, ['ended', 'archived', 'no_show'], true)) {
                throw new \DomainException(
                    'U ukončeného, archivovaného nebo nenastoupeného vztahu nelze přidat novou verzi podmínek.'
                );
            }
            $actualStart = $employment['actual_start_date'] === null
                ? null
                : (string) $employment['actual_start_date'];
            if ($actualStart !== null
                && $data['actual_start_on'] !== null
                && $data['actual_start_on'] !== $actualStart) {
                throw new \DomainException(
                    'Skutečné datum nástupu nelze změnit novou verzí smluvních podmínek.'
                );
            }
            if ($actualStart !== null) {
                $data['actual_start_on'] = $actualStart;
            }
            $this->lockEmployee($supplierId, $employeeId);
            $this->assertOffice($supplierId, $data['office_id']);
            $this->assertPrimaryAvailable($supplierId, $employeeId, $data['is_primary'], $employmentId);

            $previous = $this->latestTermsForUpdate($supplierId, $employmentId);
            if ($previous !== null && $data['effective_from'] <= (string) $previous['effective_from']) {
                throw new \DomainException(
                    'Nová smluvní verze musí začínat později než dosud poslední verze.'
                );
            }
            if ($previous !== null) {
                $previousEnd = (new \DateTimeImmutable($data['effective_from']))
                    ->modify('-1 day')
                    ->format('Y-m-d');
                $this->db->pdo()->prepare(
                    'UPDATE payroll_employment_terms
                        SET effective_to = ?, row_version = row_version + 1
                      WHERE supplier_id = ? AND id = ?'
                )->execute([$previousEnd, $supplierId, (int) $previous['id']]);
            }

            $this->insertTerms($supplierId, $employmentId, $data, $userId);
            $update = $this->db->pdo()->prepare(
                'UPDATE payroll_employments
                    SET office_id = ?, is_primary = ?, start_date = ?,
                        actual_start_date = ?, end_date = ?,
                        monthly_gross_minor =
                            CASE WHEN ? = 1 THEN ? ELSE monthly_gross_minor END,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $update->execute([
                $data['office_id'],
                (int) $data['is_primary'],
                $data['planned_start_on'],
                $data['actual_start_on'],
                $data['fixed_term_end_on'],
                (int) $replaceMonthlyGross,
                $monthlyGrossMinor,
                $supplierId,
                $employmentId,
                $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new PayrollEmploymentConflictException($expectedVersion);
            }

            $diff = $this->diff($previous, $data);
            if ($replaceMonthlyGross
                && $employment['monthly_gross_minor'] !== $monthlyGrossMinor
            ) {
                $diff['monthly_gross_minor'] = [
                    'from' => $employment['monthly_gross_minor'],
                    'to' => $monthlyGrossMinor,
                ];
            }
            $this->insertEvent(
                $supplierId,
                $employmentId,
                'terms_changed',
                null,
                null,
                $data['effective_from'],
                $data['change_reason'],
                $diff,
                $userId,
            );
            $this->ensureChecklist($supplierId, $employmentId, 'change', $data['effective_from']);
            $this->activityLogger->log(
                'payroll.employment.terms_changed',
                $userId,
                'payroll_employment',
                $employmentId,
                [
                    'effective_from' => $data['effective_from'],
                    'changed_fields' => array_keys($diff),
                ],
                $ip,
                $userAgent,
                $supplierId,
            );

            return $this->find($supplierId, $employeeId, $employmentId);
        });
    }

    /** @return array<string,mixed> */
    public function transition(
        int $supplierId,
        int $employmentId,
        string $target,
        int $expectedVersion,
        string $effectiveOn,
        ?string $note,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->transaction(function () use (
            $supplierId,
            $employmentId,
            $target,
            $expectedVersion,
            $effectiveOn,
            $note,
            $userId,
            $ip,
            $userAgent,
        ): array {
            $employment = $this->lockEmployment($supplierId, $employmentId, $expectedVersion);
            $from = (string) $employment['status'];
            $this->lifecycle->assertTransition($from, $target);
            $this->assertTransitionDate($employment, $target, $effectiveOn);

            $assignments = ['status = ?', 'row_version = row_version + 1'];
            $values = [$target];
            if ($target === 'active' && $employment['actual_start_date'] === null) {
                $assignments[] = 'actual_start_date = ?';
                $values[] = $effectiveOn;
            }
            if (in_array($target, ['ended', 'no_show'], true)) {
                $assignments[] = 'end_date = ?';
                $values[] = $effectiveOn;
                $assignments[] = 'is_primary = 0';
            }
            if ($target === 'archived') {
                $assignments[] = 'archived_at = CURRENT_TIMESTAMP';
                $assignments[] = 'is_primary = 0';
            }
            $values[] = $supplierId;
            $values[] = $employmentId;
            $values[] = $expectedVersion;
            $update = $this->db->pdo()->prepare(
                'UPDATE payroll_employments SET ' . implode(', ', $assignments)
                . ' WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $update->execute($values);
            if ($update->rowCount() !== 1) {
                throw new PayrollEmploymentConflictException($expectedVersion);
            }

            $this->insertEvent(
                $supplierId,
                $employmentId,
                'status_changed',
                $from,
                $target,
                $effectiveOn,
                $note,
                ['status' => ['from' => $from, 'to' => $target]],
                $userId,
            );
            if ($target === 'ended') {
                $this->ensureChecklist($supplierId, $employmentId, 'offboarding', $effectiveOn);
            }
            $this->activityLogger->log(
                'payroll.employment.status_changed',
                $userId,
                'payroll_employment',
                $employmentId,
                [
                    'from_status' => $from,
                    'to_status' => $target,
                    'effective_on' => $effectiveOn,
                ],
                $ip,
                $userAgent,
                $supplierId,
            );

            return $this->find(
                $supplierId,
                (int) $employment['employee_id'],
                $employmentId,
            );
        });
    }

    /** @return array<string,mixed> */
    public function updateChecklist(
        int $supplierId,
        int $employmentId,
        string $itemKey,
        int $expectedVersion,
        string $status,
        ?string $note,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->transaction(function () use (
            $supplierId,
            $employmentId,
            $itemKey,
            $expectedVersion,
            $status,
            $note,
            $userId,
            $ip,
            $userAgent,
        ): array {
            $employment = $this->lockEmployment($supplierId, $employmentId, null);
            $item = $this->db->pdo()->prepare(
                'SELECT id, status, row_version
                   FROM payroll_employment_checklist_items
                  WHERE supplier_id = ? AND employment_id = ? AND item_key = ?
                  FOR UPDATE'
            );
            $item->execute([$supplierId, $employmentId, $itemKey]);
            $fetched = $item->fetch(PDO::FETCH_ASSOC);
            if ($fetched === false) {
                throw new PayrollEmploymentNotFoundException('Položka checklistu nebyla nalezena.');
            }
            $current = $this->row($fetched);
            if ((int) $current['row_version'] !== $expectedVersion) {
                throw new PayrollEmploymentConflictException((int) $current['row_version']);
            }
            $update = $this->db->pdo()->prepare(
                "UPDATE payroll_employment_checklist_items
                    SET status = ?, note = ?,
                        completed_at = CASE WHEN ? = 'completed' THEN CURRENT_TIMESTAMP ELSE NULL END,
                        completed_by = CASE WHEN ? = 'completed' THEN ? ELSE NULL END,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?"
            );
            $update->execute([
                $status,
                $note,
                $status,
                $status,
                $userId,
                $supplierId,
                (int) $current['id'],
                $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new PayrollEmploymentConflictException($expectedVersion);
            }

            $this->insertEvent(
                $supplierId,
                $employmentId,
                'checklist_changed',
                null,
                null,
                (new \DateTimeImmutable('today'))->format('Y-m-d'),
                $note,
                [
                    $itemKey => [
                        'from' => (string) $current['status'],
                        'to' => $status,
                    ],
                ],
                $userId,
            );
            $this->activityLogger->log(
                'payroll.employment.checklist_changed',
                $userId,
                'payroll_employment',
                $employmentId,
                ['item_key' => $itemKey, 'status' => $status],
                $ip,
                $userAgent,
                $supplierId,
            );

            return $this->find(
                $supplierId,
                (int) $employment['employee_id'],
                $employmentId,
            );
        });
    }

    /** @return array<string,mixed> */
    private function find(int $supplierId, int $employeeId, int $employmentId): array
    {
        foreach ($this->listForEmployee($supplierId, $employeeId) as $employment) {
            if ($employment['id'] === $employmentId) {
                return $employment;
            }
        }
        throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
    }

    /** @return list<array<string,mixed>> */
    private function terms(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT terms.id, terms.office_id, office.code AS office_code,
                    terms.effective_from, terms.effective_to,
                    terms.contract_signed_on, terms.planned_start_on,
                    terms.actual_start_on, terms.fixed_term_end_on,
                    terms.weekly_hours, terms.workload_basis_points,
                    terms.work_place, terms.regular_workplace,
                    terms.jmhz_workplace_municipality_code,
                    terms.jmhz_workplace_country_code,
                    terms.jmhz_external_codebook_overlay_key,
                    terms.jmhz_external_codebook_manifest_sha256,
                    terms.jmhz_apz_contribution_status,
                    terms.jmhz_apz_instrument_code,
                    terms.jmhz_functional_benefits_status,
                    terms.jmhz_temporary_assignment_status,
                    terms.cz_isco_code, terms.activity_code,
                    terms.social_insurance_participation,
                    terms.health_insurance_participation, terms.tax_regime,
                    terms.foreign_legislation_country_code,
                    terms.a1_certificate_until, terms.risky_work,
                    terms.tax_declaration_signed, terms.is_primary,
                    terms.change_reason, terms.row_version, terms.created_at
               FROM payroll_employment_terms terms
               LEFT JOIN payroll_offices office
                 ON office.supplier_id = terms.supplier_id
                AND office.id = terms.office_id
              WHERE terms.supplier_id = ? AND terms.employment_id = ?
              ORDER BY terms.effective_from DESC, terms.id DESC'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->cast($this->row($row));
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function checklist(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, phase, item_key, status, due_date, completed_at,
                    note, row_version, created_at, updated_at
               FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY FIELD(phase, \'onboarding\', \'change\', \'offboarding\'),
                       due_date ASC, item_key ASC'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->cast($this->row($row));
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function events(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, event_type, from_status, to_status, effective_on,
                    note, diff_json, created_at
               FROM payroll_employment_events
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_on DESC, id DESC'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $events = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = $this->row($fetched);
            $diff = $row['diff_json'] === null
                ? null
                : json_decode((string) $row['diff_json'], true, 512, JSON_THROW_ON_ERROR);
            $events[] = [
                'id' => (int) $row['id'],
                'event_type' => (string) $row['event_type'],
                'from_status' => $row['from_status'] === null ? null : (string) $row['from_status'],
                'to_status' => $row['to_status'] === null ? null : (string) $row['to_status'],
                'effective_on' => (string) $row['effective_on'],
                'note' => $row['note'] === null ? null : (string) $row['note'],
                'diff' => $diff,
                'created_at' => (string) $row['created_at'],
            ];
        }
        return $events;
    }

    /** @param TermsInput $data */
    private function insertTerms(
        int $supplierId,
        int $employmentId,
        array $data,
        ?int $userId,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 contract_signed_on, planned_start_on, actual_start_on,
                 fixed_term_end_on, weekly_hours, workload_basis_points,
                 work_place, regular_workplace, cz_isco_code, activity_code,
                 jmhz_workplace_municipality_code,
                 jmhz_workplace_country_code,
                 jmhz_external_codebook_overlay_key,
                 jmhz_external_codebook_manifest_sha256,
                 jmhz_apz_contribution_status, jmhz_apz_instrument_code,
                 jmhz_functional_benefits_status,
                 jmhz_temporary_assignment_status,
                 social_insurance_participation, health_insurance_participation,
                 tax_regime, foreign_legislation_country_code,
                 a1_certificate_until, risky_work, tax_declaration_signed,
                 is_primary, change_reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $employmentId,
            $data['office_id'],
            $data['effective_from'],
            $data['contract_signed_on'],
            $data['planned_start_on'],
            $data['actual_start_on'],
            $data['fixed_term_end_on'],
            $data['weekly_hours'],
            $data['workload_basis_points'],
            $data['work_place'],
            $data['regular_workplace'],
            $data['cz_isco_code'],
            $data['activity_code'],
            $data['jmhz_workplace_municipality_code'],
            $data['jmhz_workplace_country_code'],
            $data['jmhz_external_codebook_overlay_key'],
            $data['jmhz_external_codebook_manifest_sha256'],
            $data['jmhz_apz_contribution_status'],
            $data['jmhz_apz_instrument_code'],
            $data['jmhz_functional_benefits_status'],
            $data['jmhz_temporary_assignment_status'],
            $data['social_insurance_participation'],
            $data['health_insurance_participation'],
            $data['tax_regime'],
            $data['foreign_legislation_country_code'],
            $data['a1_certificate_until'],
            (int) $data['risky_work'],
            (int) $data['tax_declaration_signed'],
            (int) $data['is_primary'],
            $data['change_reason'],
            $userId,
        ]);
    }

    /**
     * @param array<string,string|int|bool|null>|null $previous
     * @param TermsInput $current
     * @return array<string,array{from:mixed,to:mixed}>
     */
    private function diff(?array $previous, array $current): array
    {
        $diff = [];
        foreach ($current as $key => $value) {
            if (in_array($key, ['change_reason'], true)) {
                continue;
            }
            $old = $previous[$key] ?? null;
            if ($old !== $value && (string) $old !== (string) $value) {
                $diff[$key] = ['from' => $old, 'to' => $value];
            }
        }
        return $diff;
    }

    /** @return array<string,string|int|bool|null>|null */
    private function latestTermsForUpdate(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($this->row($row));
    }

    /** @param array<string,mixed> $diff */
    private function insertEvent(
        int $supplierId,
        int $employmentId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        string $effectiveOn,
        ?string $note,
        array $diff,
        ?int $userId,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_events
                (supplier_id, employment_id, event_type, from_status, to_status,
                 effective_on, note, diff_json, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $employmentId,
            $eventType,
            $fromStatus,
            $toStatus,
            $effectiveOn,
            $note,
            json_encode($diff, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $userId,
        ]);
    }

    private function ensureChecklist(
        int $supplierId,
        int $employmentId,
        string $phase,
        string $dueDate,
    ): void {
        $insert = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO payroll_employment_checklist_items
                (supplier_id, employment_id, phase, item_key, due_date)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach (self::CHECKLISTS[$phase] as $itemKey) {
            $insert->execute([$supplierId, $employmentId, $phase, $itemKey, $dueDate]);
        }
    }

    private function lockEmployee(int $supplierId, int $employeeId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employeeId]);
        if ($stmt->fetchColumn() === false) {
            throw new PayrollEmploymentNotFoundException('Zaměstnanec nebyl nalezen.');
        }
    }

    /** @return array<string,string|int|bool|null> */
    private function lockEmployment(
        int $supplierId,
        int $employmentId,
        ?int $expectedVersion,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, status, is_primary, start_date,
                    actual_start_date, end_date, monthly_gross_minor, row_version
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fetched === false) {
            throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
        }
        $row = $this->cast($this->row($fetched));
        if ($expectedVersion !== null && (int) $row['row_version'] !== $expectedVersion) {
            throw new PayrollEmploymentConflictException((int) $row['row_version']);
        }
        return $row;
    }

    private function assertOffice(int $supplierId, ?int $officeId): void
    {
        if ($officeId === null) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_offices
              WHERE supplier_id = ? AND id = ? AND is_active = 1'
        );
        $stmt->execute([$supplierId, $officeId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('Mzdová účtárna neexistuje nebo není aktivní.');
        }
    }

    private function assertPrimaryAvailable(
        int $supplierId,
        int $employeeId,
        bool $isPrimary,
        ?int $exceptEmploymentId,
    ): void {
        if (!$isPrimary) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT id
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ? AND is_primary = 1
                AND status IN ('planned', 'preregistered', 'active', 'suspended')
                AND (? IS NULL OR id <> ?)
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $employeeId, $exceptEmploymentId, $exceptEmploymentId]);
        if ($stmt->fetchColumn() !== false) {
            throw new \DomainException('Osoba už má jiný primární pracovní vztah.');
        }
    }

    /** @param array<string,string|int|bool|null> $employment */
    private function assertTransitionDate(array $employment, string $target, string $effectiveOn): void
    {
        $start = $employment['actual_start_date'] ?? $employment['start_date'];
        if (in_array($target, ['ended', 'no_show'], true)
            && $start !== null
            && $effectiveOn < (string) $start) {
            throw new \DomainException('Datum skončení nesmí předcházet nástupu.');
        }
        if ($target === 'archived' && $employment['end_date'] !== null
            && $effectiveOn < (string) $employment['end_date']) {
            throw new \DomainException('Archivace nesmí předcházet skončení vztahu.');
        }
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_employment_change');
        }
        try {
            $result = $callback();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_employment_change');
            }
            return $result;
        } catch (\Throwable $e) {
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_employment_change');
                $pdo->exec('RELEASE SAVEPOINT payroll_employment_change');
            }
            throw $e;
        }
    }

    /** @param array<string,string|int|bool|null> $row
     *  @return array<string,string|int|bool|null>
     */
    private function cast(array $row): array
    {
        $ints = [
            'id',
            'employee_id',
            'office_id',
            'monthly_gross_minor',
            'workload_basis_points',
            'row_version',
        ];
        $bools = ['is_primary', 'is_legacy_projection', 'risky_work', 'tax_declaration_signed'];
        foreach ($ints as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach ($bools as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (bool) $row[$key];
            }
        }
        return $row;
    }

    /** @return array<string,string|int|bool|null> */
    private function row(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Databáze vrátila neplatný řádek pracovního vztahu.');
        }
        $row = [];
        foreach ($value as $key => $cell) {
            if (!is_string($key)
                || (!is_string($cell) && !is_int($cell) && !is_bool($cell) && $cell !== null)
            ) {
                throw new \UnexpectedValueException('Databáze vrátila neplatnou hodnotu pracovního vztahu.');
            }
            $row[$key] = $cell;
        }
        return $row;
    }
}
