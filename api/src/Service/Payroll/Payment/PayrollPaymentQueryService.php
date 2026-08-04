<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;
use PDO;

final class PayrollPaymentQueryService
{
    private readonly PayrollPaymentBatchQueryService $batchQueries;

    public function __construct(
        private readonly Connection $db,
        ?PayrollPaymentBatchQueryService $batchQueries = null,
    ) {
        $this->batchQueries = $batchQueries
            ?? new PayrollPaymentBatchQueryService(
                $db,
                new CzechBankAccountValidator(),
                new IbanValidator(),
            );
    }

    /** @return list<array<string,mixed>> */
    public function payerOptions(int $supplierId): array
    {
        return $this->batchQueries->payerOptions($supplierId);
    }

    /** @return list<array<string,mixed>> */
    public function batchesForPeriod(
        int $supplierId,
        string $period,
    ): array {
        return $this->batchQueries->batchesForPeriod(
            $supplierId,
            $period,
        );
    }

    /**
     * @return list<array{
     *   id:int,
     *   run_id:int,
     *   revision_id:int,
     *   revision_no:int,
     *   employee_id:?int,
     *   employee_name:?string,
     *   liability_kind:string,
     *   direction:string,
     *   recipient_kind:string,
     *   due_on:string,
     *   currency_code:string,
     *   amount_minor:int,
     *   allocated_minor:int,
     *   settled_minor:int,
     *   state:string,
     *   created_at:string
     * }>
     */
    public function listForPeriod(int $supplierId, string $period): array
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být kladné číslo.');
        }
        if (preg_match('/^(20[0-9]{2}|21[0-9]{2})-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException('Mzdové období musí mít tvar RRRR-MM.');
        }

        $statement = $this->db->pdo()->prepare(
            'SELECT liability.id, revision.run_id, liability.revision_id,
                    revision.revision_no, liability.employee_id,
                    employee.full_name AS employee_name,
                    liability.liability_kind, liability.direction,
                    CASE
                      WHEN liability.recipient_reference LIKE "employee-cash:%"
                        THEN "cash"
                      ELSE "bank"
                    END AS recipient_kind,
                    liability.due_on, liability.currency_code,
                    liability.amount_minor, liability.created_at,
                    (
                      SELECT COALESCE(SUM(allocation.amount_minor), 0)
                        FROM payroll_payment_allocations allocation
                       WHERE allocation.supplier_id = liability.supplier_id
                         AND allocation.liability_id = liability.id
                    ) AS allocated_minor,
                    (
                      SELECT COALESCE(SUM(payment_match.amount_minor), 0)
                        FROM payroll_payment_allocations allocation
                        JOIN payroll_payment_matches payment_match
                          ON payment_match.supplier_id =
                             allocation.supplier_id
                         AND payment_match.allocation_id = allocation.id
                       WHERE allocation.supplier_id = liability.supplier_id
                         AND allocation.liability_id = liability.id
                    ) AS settled_minor
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
               LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = liability.supplier_id
                AND employee.id = liability.employee_id
              WHERE liability.supplier_id = ?
                AND run.period_start = CONCAT(?, "-01")
              ORDER BY liability.due_on, employee.full_name, liability.id'
        );
        $statement->execute([$supplierId, $period]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::row($row);
            $amount = self::integer($row, 'amount_minor');
            $allocated = self::integer($row, 'allocated_minor');
            $settled = self::integer($row, 'settled_minor');
            if ($amount <= 0 || $allocated < 0 || $allocated > $amount
                || $settled < 0 || $settled > $allocated
            ) {
                throw new \UnexpectedValueException(
                    'Součty platebního závazku jsou mimo povolené meze.',
                );
            }
            $employeeId = self::nullableInteger($row, 'employee_id');
            $employeeName = $row['employee_name'] ?? null;
            if ($employeeName !== null && !is_string($employeeName)) {
                throw new \UnexpectedValueException(
                    'Jméno osoby u platebního závazku není text.',
                );
            }
            $result[] = [
                'id' => self::integer($row, 'id'),
                'run_id' => self::integer($row, 'run_id'),
                'revision_id' => self::integer($row, 'revision_id'),
                'revision_no' => self::integer($row, 'revision_no'),
                'employee_id' => $employeeId,
                'employee_name' => $employeeName,
                'liability_kind' => self::text($row, 'liability_kind'),
                'direction' => self::text($row, 'direction'),
                'recipient_kind' => self::text($row, 'recipient_kind'),
                'due_on' => self::text($row, 'due_on'),
                'currency_code' => self::text($row, 'currency_code'),
                'amount_minor' => $amount,
                'allocated_minor' => $allocated,
                'settled_minor' => $settled,
                'state' => self::state($amount, $allocated, $settled),
                'created_at' => self::text($row, 'created_at'),
            ];
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                'Databáze vrátila neplatný platební závazek.',
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Platební závazek nemá textové klíče.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private static function state(int $amount, int $allocated, int $settled): string
    {
        if ($settled === $amount) {
            return 'settled';
        }
        if ($settled > 0) {
            return 'partially_settled';
        }
        if ($allocated === $amount) {
            return 'batched';
        }
        if ($allocated > 0) {
            return 'partially_batched';
        }

        return 'open';
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException("Hodnota {$key} není celé číslo.");
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($validated)) {
            throw new \UnexpectedValueException("Hodnota {$key} není celé číslo.");
        }

        return $validated;
    }

    /** @param array<string,mixed> $row */
    private static function nullableInteger(array $row, string $key): ?int
    {
        return ($row[$key] ?? null) === null ? null : self::integer($row, $key);
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException("Hodnota {$key} není neprázdný text.");
        }

        return $value;
    }
}
