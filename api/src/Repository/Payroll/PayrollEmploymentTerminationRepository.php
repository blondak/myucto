<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Skončení pracovního vztahu: způsob a důvod (jediný zdroj pro A2, potvrzení
 * pro Úřad práce a odstupné) a osoby blízké podle § 328 ZP.
 */
final class PayrollEmploymentTerminationRepository
{
    public function __construct(private readonly Connection $db) {}

    public function available(): bool
    {
        return $this->db->hasTable('payroll_employment_terminations');
    }

    /** @return array<string,mixed>|null */
    public function employment(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, relation_type, status, start_date,
                    actual_start_date, end_date, row_version
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        foreach (['id', 'employee_id', 'row_version'] as $key) {
            $row[$key] = (int) $row[$key];
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $employmentId): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employment_id, termination_method, legal_ground,
                    employee_stated_reason, severance_multiple_override,
                    severance_override_reason, working_time_account_applies,
                    other_income_from, other_payer_applies_protected_amount,
                    work_injury_compensation_payer, work_injury_compensation_paid_on,
                    death_tax_assessment, death_tax_assessed_by,
                    death_tax_assessed_at, row_version, updated_at
               FROM payroll_employment_terminations
              WHERE supplier_id = ? AND employment_id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        foreach (['id', 'employment_id', 'row_version'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['severance_multiple_override'] = $row['severance_multiple_override'] === null
            ? null
            : (int) $row['severance_multiple_override'];
        $row['death_tax_assessed_by'] = $row['death_tax_assessed_by'] === null
            ? null
            : (int) $row['death_tax_assessed_by'];
        $row['working_time_account_applies'] = (bool) $row['working_time_account_applies'];
        $row['other_payer_applies_protected_amount'] = (bool) $row['other_payer_applies_protected_amount'];

        return $row;
    }

    /**
     * Založí nebo přepíše záznam. `$expectedVersion` null = zakládá se;
     * existuje-li už záznam, je to konflikt, ne tiché přepsání.
     *
     * @param array{
     *   termination_method:string,legal_ground:string,
     *   employee_stated_reason:?string,severance_multiple_override:?int,
     *   severance_override_reason:?string,working_time_account_applies:bool,
     *   other_income_from:?string,other_payer_applies_protected_amount:bool
     * } $data
     */
    public function save(
        int $supplierId,
        int $employmentId,
        array $data,
        ?int $expectedVersion,
        ?int $userId,
    ): void {
        $pdo = $this->db->pdo();
        if ($expectedVersion === null) {
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO payroll_employment_terminations
                    (supplier_id, employment_id, termination_method, legal_ground,
                     employee_stated_reason, severance_multiple_override,
                     severance_override_reason, working_time_account_applies,
                     other_income_from, other_payer_applies_protected_amount,
                     created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $employmentId,
                $data['termination_method'],
                $data['legal_ground'],
                $data['employee_stated_reason'],
                $data['severance_multiple_override'],
                $data['severance_override_reason'],
                $data['working_time_account_applies'] ? 1 : 0,
                $data['other_income_from'],
                $data['other_payer_applies_protected_amount'] ? 1 : 0,
                $userId,
                $userId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollEmploymentConflictException(
                    (int) ($this->find($supplierId, $employmentId)['row_version'] ?? 1),
                );
            }

            return;
        }
        // Úmrtí se nedá „přepnout" na jiný způsob s ponechaným daňovým
        // posouzením — to se váže jen ke skončení úmrtím (CHECK v 1909).
        $stmt = $pdo->prepare(
            'UPDATE payroll_employment_terminations
                SET termination_method = ?, legal_ground = ?,
                    employee_stated_reason = ?, severance_multiple_override = ?,
                    severance_override_reason = ?, working_time_account_applies = ?,
                    other_income_from = ?, other_payer_applies_protected_amount = ?,
                    death_tax_assessment = IF(? = "death", death_tax_assessment, NULL),
                    death_tax_assessed_by = IF(? = "death", death_tax_assessed_by, NULL),
                    death_tax_assessed_at = IF(? = "death", death_tax_assessed_at, NULL),
                    updated_by = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND employment_id = ? AND row_version = ?'
        );
        $stmt->execute([
            $data['termination_method'],
            $data['legal_ground'],
            $data['employee_stated_reason'],
            $data['severance_multiple_override'],
            $data['severance_override_reason'],
            $data['working_time_account_applies'] ? 1 : 0,
            $data['other_income_from'],
            $data['other_payer_applies_protected_amount'] ? 1 : 0,
            $data['termination_method'],
            $data['termination_method'],
            $data['termination_method'],
            $userId,
            $supplierId,
            $employmentId,
            $expectedVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new PayrollEmploymentConflictException(
                (int) ($this->find($supplierId, $employmentId)['row_version'] ?? $expectedVersion),
            );
        }
    }

    /**
     * Kdo vyplácí jednorázovou náhradu podle § 271ca ZP a kdy. Zapisuje se
     * jednou, při založení náhrady; změnu řeší zpětvzetí vstupu.
     */
    public function saveWorkInjuryCompensation(
        int $supplierId,
        int $employmentId,
        string $payer,
        string $paidOn,
        ?int $userId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terminations
                SET work_injury_compensation_payer = ?,
                    work_injury_compensation_paid_on = ?,
                    updated_by = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND employment_id = ?'
        );
        $stmt->execute([$payer, $paidOn, $userId, $supplierId, $employmentId]);
        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Záznam o skončení vztahu nebyl nalezen.');
        }
    }

    public function assessDeathTax(
        int $supplierId,
        int $employmentId,
        string $assessment,
        int $expectedVersion,
        ?int $userId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terminations
                SET death_tax_assessment = ?, death_tax_assessed_by = ?,
                    death_tax_assessed_at = NOW(), updated_by = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND employment_id = ? AND row_version = ?
                AND termination_method = "death"'
        );
        $stmt->execute([$assessment, $userId, $userId, $supplierId, $employmentId, $expectedVersion]);
        if ($stmt->rowCount() !== 1) {
            throw new PayrollEmploymentConflictException(
                (int) ($this->find($supplierId, $employmentId)['row_version'] ?? $expectedVersion),
            );
        }
    }

    /**
     * Předchozí pracovní poměry téhož zaměstnance u téhož zaměstnavatele
     * pro § 67 odst. 2 ZP. Dohody se nezapočítávají — odstupné je institut
     * pracovního poměru.
     *
     * @return list<array{start:string,end:string}>
     */
    public function previousEmployments(int $supplierId, int $employeeId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(actual_start_date, start_date) AS start_on, end_date
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ? AND id <> ?
                AND relation_type IN ("employment", "small_scale_employment")
                AND status IN ("ended", "archived")
                AND end_date IS NOT NULL
                AND COALESCE(actual_start_date, start_date) IS NOT NULL
              ORDER BY end_date DESC'
        );
        $stmt->execute([$supplierId, $employeeId, $employmentId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = ['start' => (string) $row['start_on'], 'end' => (string) $row['end_date']];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function survivors(int $supplierId, int $employmentId): array
    {
        if (!$this->db->hasTable('payroll_employment_survivors')) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, full_name, relationship, shared_household, bank_account, note
               FROM payroll_employment_survivors
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY FIELD(relationship, "spouse_partner", "child", "parent"), id'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int) $row['id'];
            $row['shared_household'] = (bool) $row['shared_household'];
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array{full_name:string,relationship:string,shared_household:bool,bank_account:?string,note:?string} $data */
    public function addSurvivor(int $supplierId, int $employmentId, array $data, ?int $userId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_survivors
                (supplier_id, employment_id, full_name, relationship,
                 shared_household, bank_account, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $employmentId,
            $data['full_name'],
            $data['relationship'],
            $data['shared_household'] ? 1 : 0,
            $data['bank_account'],
            $data['note'],
            $userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function deleteSurvivor(int $supplierId, int $employmentId, int $survivorId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM payroll_employment_survivors
              WHERE supplier_id = ? AND employment_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId, $survivorId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Živé srážky, které po úmrtí zaměstnance nelze prostě nechat běžet:
     * exekuce a insolvence (správce nebo soud se musí dozvědět o úmrtí)
     * a dohody o srážkách (peněžitá práva zaměstnavatele smrtí zanikají,
     * § 328 odst. 2 ZP). Aplikace je sama neukončuje — to patří modulu
     * srážek; tady se jen spočítají, aby je karta skončení uměla ukázat.
     *
     * @return array{enforcement_cases:int,deduction_agreements:int}
     */
    public function activeDeductions(int $supplierId, int $employeeId): array
    {
        $result = ['enforcement_cases' => 0, 'deduction_agreements' => 0];
        if ($this->db->hasTable('payroll_enforcement_cases')) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT COUNT(*) FROM payroll_enforcement_cases
                  WHERE supplier_id = ? AND employee_id = ?
                    AND status NOT IN ("paid", "stopped")'
            );
            $stmt->execute([$supplierId, $employeeId]);
            $result['enforcement_cases'] = (int) $stmt->fetchColumn();
        }
        if ($this->db->hasTable('payroll_deduction_agreements')) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT COUNT(*) FROM payroll_deduction_agreements
                  WHERE supplier_id = ? AND employee_id = ?
                    AND status IN ("active", "paused")'
            );
            $stmt->execute([$supplierId, $employeeId]);
            $result['deduction_agreements'] = (int) $stmt->fetchColumn();
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    public function liveInput(int $supplierId, int $employmentId, string $sourceKind, string $externalId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.id, input.period_start, input.amount_minor,
                    input.quantity_milliunits, input.status, input.row_version,
                    input.source_snapshot_json, component.code AS component_code
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.source_kind = ? AND input.external_id = ?
                AND input.status <> "cancelled"
              ORDER BY input.id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $sourceKind, $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        foreach (['id', 'amount_minor', 'row_version'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['quantity_milliunits'] = $row['quantity_milliunits'] === null
            ? null
            : (int) $row['quantity_milliunits'];

        return $row;
    }

    /**
     * Živé vstupy, jejichž `external_id` začíná prefixem, podle external_id.
     *
     * @return array<string,array<string,mixed>>
     */
    public function liveInputsWithPrefix(int $supplierId, int $employmentId, string $prefix): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.id, input.period_start, input.amount_minor,
                    input.quantity_milliunits, input.status, input.row_version,
                    input.source_kind, input.external_id
               FROM payroll_inputs input
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.external_id LIKE ? AND input.status <> "cancelled"
              ORDER BY input.id'
        );
        $stmt->execute([$supplierId, $employmentId, $prefix . '%']);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (['id', 'amount_minor', 'row_version'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['quantity_milliunits'] = $row['quantity_milliunits'] === null
                ? null
                : (int) $row['quantity_milliunits'];
            $rows[(string) $row['external_id']] = $row;
        }

        return $rows;
    }

    public function componentId(int $supplierId, string $code, string $periodStart): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ? AND is_active = 1
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code, $periodStart, $periodStart]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Má rok v knize dovolené určený nárok? Bez něj zůstatek nic neznamená. */
    public function hasLeaveEntitlement(int $supplierId, int $employmentId, int $year): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_leave_ledger
              WHERE supplier_id = ? AND employment_id = ? AND leave_year = ?
                AND entry_type IN ("entitlement", "carryover")
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $year]);

        return $stmt->fetchColumn() !== false;
    }

    /** Položka knihy dovolené, kterou zapsalo vyrovnání při skončení. */
    public function settlementLedgerEntry(int $supplierId, int $employmentId, int $year, string $reasonPrefix): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ledger.id
               FROM payroll_leave_ledger ledger
              WHERE ledger.supplier_id = ? AND ledger.employment_id = ?
                AND ledger.leave_year = ? AND ledger.entry_type = "payout"
                AND ledger.reason LIKE ?
                AND NOT EXISTS (
                    SELECT 1 FROM payroll_leave_ledger reversal
                     WHERE reversal.supplier_id = ledger.supplier_id
                       AND reversal.reversal_of_id = ledger.id
                )
              ORDER BY ledger.id DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $year, $reasonPrefix . '%']);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function insertLedgerReversal(int $supplierId, int $ledgerId, string $reason, ?int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment_id, leave_year, minutes_delta
               FROM payroll_leave_ledger WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $ledgerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \OutOfBoundsException('Položka knihy dovolené nebyla nalezena.');
        }
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_leave_ledger
                (supplier_id, employment_id, leave_year, effective_date, entry_type,
                 minutes_delta, reversal_of_id, reason, support_status, source_hash, created_by)
             VALUES (?, ?, ?, CURDATE(), "reversal", ?, ?, ?, "supported", UNHEX(SHA2(?, 256)), ?)'
        )->execute([
            $supplierId,
            (int) $row['employment_id'],
            (int) $row['leave_year'],
            -(int) $row['minutes_delta'],
            $ledgerId,
            $reason,
            'termination-leave-reversal:' . $ledgerId,
            $userId,
        ]);
    }
}
