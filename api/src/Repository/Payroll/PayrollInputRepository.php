<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefinitionFactory;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;
use PDOException;

final class PayrollInputRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollComponentDefinitionFactory $definitionFactory,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, string $periodStart): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.*, employee.full_name AS employee_name,
                    employment.code AS employment_code,
                    employment.relation_type,
                    component.code AS component_code,
                    component.name AS component_name,
                    component.component_kind,
                    component.value_kind
               FROM payroll_inputs input
               JOIN payroll_employees employee
                 ON employee.supplier_id = input.supplier_id
                AND employee.id = input.employee_id
               JOIN payroll_employments employment
                 ON employment.supplier_id = input.supplier_id
                AND employment.id = input.employment_id
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ?
                AND input.period_start = ?
              ORDER BY employee.full_name, employment.code, component.code, input.id'
        );
        $stmt->execute([$supplierId, $periodStart]);

        return array_map(
            self::cast(...),
            PayrollTimeValue::rows(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                'payroll_inputs',
            ),
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.*, employee.full_name AS employee_name,
                    employment.code AS employment_code,
                    employment.relation_type,
                    component.code AS component_code,
                    component.name AS component_name,
                    component.component_kind,
                    component.value_kind
               FROM payroll_inputs input
               JOIN payroll_employees employee
                 ON employee.supplier_id = input.supplier_id
                AND employee.id = input.employee_id
               JOIN payroll_employments employment
                 ON employment.supplier_id = input.supplier_id
                AND employment.id = input.employment_id
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false
            ? null
            : self::cast(PayrollTimeValue::row($row, 'payroll_input'));
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $data, ?int $userId): array
    {
        $this->assertValidReferences($supplierId, $data);
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO payroll_inputs
                    (supplier_id, employee_id, employment_id, component_id,
                     period_start, source_period_start, amount_minor,
                     quantity_milliunits, source_kind, external_id,
                     source_snapshot_json, source_snapshot_hash, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $data['employee_id'],
                $data['employment_id'],
                $data['component_id'],
                $data['period_start'],
                $data['source_period_start'],
                $data['amount_minor'],
                $data['quantity_milliunits'],
                $data['source_kind'],
                $data['external_id'],
                $data['source_snapshot_json'] ?? null,
                $data['source_snapshot_hash'] ?? null,
                $userId,
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new \InvalidArgumentException(
                    'Externí mzdový vstup už byl pro tento vztah a měsíc importován.',
                    previous: $e,
                );
            }
            throw $e;
        }

        return $this->find($supplierId, (int) $this->db->pdo()->lastInsertId())
            ?? throw new \RuntimeException('Mzdový vstup se nepodařilo načíst.');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function update(
        int $supplierId,
        int $id,
        array $data,
        int $expectedVersion,
    ): ?array {
        $this->assertValidReferences($supplierId, $data);
        $current = $this->find($supplierId, $id);
        if ($current === null) {
            return null;
        }
        if ($current['status'] !== 'draft') {
            throw new \DomainException('Upravit lze jen rozpracovaný mzdový vstup.');
        }
        $currentVersion = PayrollTimeValue::int(
            $current['row_version'] ?? null,
            'row_version',
        );
        if ($currentVersion !== $expectedVersion) {
            throw new PayrollInputConflictException($currentVersion);
        }
        try {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE payroll_inputs
                    SET employee_id = ?, employment_id = ?, component_id = ?,
                        period_start = ?, source_period_start = ?, amount_minor = ?,
                        quantity_milliunits = ?, source_kind = ?, external_id = ?,
                        source_snapshot_json = ?, source_snapshot_hash = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?
                    AND status = "draft"'
            );
            $stmt->execute([
                $data['employee_id'],
                $data['employment_id'],
                $data['component_id'],
                $data['period_start'],
                $data['source_period_start'],
                $data['amount_minor'],
                $data['quantity_milliunits'],
                $data['source_kind'],
                $data['external_id'],
                $data['source_snapshot_json'] ?? null,
                $data['source_snapshot_hash'] ?? null,
                $supplierId,
                $id,
                $expectedVersion,
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new \InvalidArgumentException(
                    'Externí mzdový vstup už byl pro tento vztah a měsíc importován.',
                    previous: $e,
                );
            }
            throw $e;
        }
        if ($stmt->rowCount() !== 1) {
            $latest = $this->find($supplierId, $id);
            throw new PayrollInputConflictException(
                $latest === null
                    ? $expectedVersion
                    : PayrollTimeValue::int(
                        $latest['row_version'] ?? null,
                        'row_version',
                    ),
            );
        }
        return $this->find($supplierId, $id);
    }

    /** @return array<string,mixed>|null */
    public function approve(
        int $supplierId,
        int $id,
        int $expectedVersion,
        ?int $userId,
    ): ?array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT input.id AS input_id,
                        input.row_version AS input_row_version,
                        input.status AS input_status,
                        input.employee_id,
                        input.component_id,
                        input.amount_minor,
                        input.period_start,
                        component.code,
                        component.name,
                        component.component_kind,
                        component.value_kind,
                        component.frequency_kind,
                        component.tax_treatment,
                        component.social_participation_treatment,
                        component.social_treatment,
                        component.health_participation_treatment,
                        component.health_treatment,
                        component.average_earning_treatment,
                        component.enforcement_treatment,
                        component.jmhz_treatment,
                        component.statistics_treatment,
                        component.accounting_debit_code,
                        component.accounting_credit_code,
                        component.annual_limit_minor,
                        component.valid_from,
                        component.valid_to,
                        component.row_version AS component_row_version
                   FROM payroll_inputs input
                   JOIN payroll_component_definitions component
                     ON component.supplier_id = input.supplier_id
                    AND component.id = input.component_id
                  WHERE input.supplier_id = ? AND input.id = ?
                  FOR UPDATE'
            );
            $stmt->execute([$supplierId, $id]);
            $raw = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($raw === false) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return null;
            }
            $row = PayrollTimeValue::row($raw, 'payroll_input_approval');
            $currentVersion = PayrollTimeValue::int(
                $row['input_row_version'] ?? null,
                'input_row_version',
            );
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollInputConflictException($currentVersion);
            }
            if (PayrollTimeValue::string(
                $row['input_status'] ?? null,
                'input_status',
            ) !== 'draft') {
                throw new PayrollInputApprovalException(
                    'input_state_conflict',
                    'Schválit lze jen rozpracovaný mzdový vstup.',
                );
            }

            $definition = $this->definitionFactory->fromArray($row);
            try {
                $definition->impact(new \MyInvoice\Service\Payroll\Calculation\Money(
                    PayrollTimeValue::int($row['amount_minor'] ?? null, 'amount_minor'),
                ));
            } catch (\DomainException $e) {
                throw new PayrollInputApprovalException(
                    'input_requires_manual_review',
                    $e->getMessage(),
                );
            }
            if ($definition->annualLimitMinor !== null) {
                $this->lockEmployee(
                    $pdo,
                    $supplierId,
                    PayrollTimeValue::int($row['employee_id'] ?? null, 'employee_id'),
                );
                $used = $this->annualBenefitTotal(
                    $supplierId,
                    PayrollTimeValue::int($row['employee_id'] ?? null, 'employee_id'),
                    PayrollTimeValue::int($row['component_id'] ?? null, 'component_id'),
                    (int) substr(
                        PayrollTimeValue::string(
                            $row['period_start'] ?? null,
                            'period_start',
                        ),
                        0,
                        4,
                    ),
                );
                if ($used + max(
                    0,
                    PayrollTimeValue::int($row['amount_minor'] ?? null, 'amount_minor'),
                )
                    > $definition->annualLimitMinor
                ) {
                    throw new PayrollInputApprovalException(
                        'benefit_limit_exceeded',
                        'Schválením by byl překročen roční limit benefitu.',
                    );
                }
            }
            $snapshot = [
                ...$definition->snapshot(),
                'component_id' => PayrollTimeValue::int(
                    $row['component_id'] ?? null,
                    'component_id',
                ),
                'component_row_version' => PayrollTimeValue::int(
                    $row['component_row_version'] ?? null,
                    'component_row_version',
                ),
                'valid_from' => PayrollTimeValue::string(
                    $row['valid_from'] ?? null,
                    'valid_from',
                ),
                'valid_to' => $row['valid_to'] === null
                    ? null
                    : PayrollTimeValue::string($row['valid_to'], 'valid_to'),
            ];
            $json = CanonicalJson::encode($snapshot);
            $hash = hash('sha256', $json, true);

            $update = $pdo->prepare(
                'UPDATE payroll_inputs
                    SET status = "approved",
                        component_snapshot_json = ?,
                        component_snapshot_hash = ?,
                        approved_by = ?,
                        approved_at = NOW(),
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?
                    AND status = "draft"'
            );
            $update->execute([
                $json,
                $hash,
                $userId,
                $supplierId,
                $id,
                $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new PayrollInputConflictException($currentVersion);
            }
            if ($definition->kind->isBenefit()) {
                $pdo->prepare(
                    'INSERT IGNORE INTO payroll_benefit_accumulators
                        (supplier_id, employee_id, component_id, input_id,
                         tax_year, amount_minor)
                     VALUES (?, ?, ?, ?, YEAR(?), ?)'
                )->execute([
                    $supplierId,
                    PayrollTimeValue::int($row['employee_id'] ?? null, 'employee_id'),
                    PayrollTimeValue::int($row['component_id'] ?? null, 'component_id'),
                    $id,
                    PayrollTimeValue::string($row['period_start'] ?? null, 'period_start'),
                    PayrollTimeValue::int($row['amount_minor'] ?? null, 'amount_minor'),
                ]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            throw $e;
        }

        return $this->find($supplierId, $id);
    }

    public function annualBenefitTotal(
        int $supplierId,
        int $employeeId,
        int $componentId,
        int $year,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(amount_minor), 0)
               FROM payroll_benefit_accumulators
              WHERE supplier_id = ?
                AND employee_id = ?
                AND component_id = ?
                AND tax_year = ?
                AND status = "active"'
        );
        $stmt->execute([$supplierId, $employeeId, $componentId, $year]);
        return PayrollTimeValue::int(
            $stmt->fetchColumn(),
            'annual_benefit_total',
        );
    }

    /** @param array<string,mixed> $data */
    public function assertValidReferences(int $supplierId, array $data): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_employments employment
               JOIN payroll_component_definitions component
                 ON component.supplier_id = employment.supplier_id
                AND component.id = ?
                AND component.is_active = 1
                AND component.valid_from <= ?
                AND (component.valid_to IS NULL OR component.valid_to >= ?)
              WHERE employment.supplier_id = ?
                AND employment.id = ?
                AND employment.employee_id = ?'
        );
        $stmt->execute([
            $data['component_id'],
            $data['period_start'],
            $data['period_start'],
            $supplierId,
            $data['employment_id'],
            $data['employee_id'],
        ]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException(
                'Zaměstnanec, vztah nebo účinná mzdová složka nepatří této firmě.'
            );
        }
    }

    private function lockEmployee(PDO $pdo, int $supplierId, int $employeeId): void
    {
        $stmt = $pdo->prepare(
            'SELECT id
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employeeId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException(
                'Zaměstnanec nepatří této firmě.'
            );
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        foreach ([
            'id',
            'supplier_id',
            'employee_id',
            'employment_id',
            'component_id',
            'amount_minor',
            'quantity_milliunits',
            'import_id',
            'row_version',
            'created_by',
            'approved_by',
        ] as $key) {
            if (($row[$key] ?? null) !== null) {
                $row[$key] = PayrollTimeValue::int($row[$key], $key);
            }
        }
        unset($row['component_snapshot_hash'], $row['source_snapshot_hash']);
        return $row;
    }

}
