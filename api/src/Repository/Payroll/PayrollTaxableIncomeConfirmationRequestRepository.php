<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Deadline\PayrollChecklistDeadlinePolicy;
use PDO;

/**
 * Žádosti zaměstnance o potvrzení o zdanitelných příjmech (§ 38j odst. 3 ZDP).
 *
 * Plátce vystaví potvrzení do deseti dnů od žádosti kdykoli, nejen při
 * skončení vztahu. Dřív šel den žádosti zapsat jen k položce výstupního
 * checklistu, takže žádost u trvajícího vztahu neměla termín nikde.
 *
 * Termín počítá {@see PayrollChecklistDeadlinePolicy::taxableIncomeConfirmationOnRequest()}
 * (tentýž jako u položky checklistu). Žádost je vyřízená, když od jejího dne
 * vzniklo potvrzení osobě ({@see PayrollChecklistEvidenceSql::taxableIncomeCertificateIssued()}),
 * nebo když ji účetní označí jako vyřízenou ručně (potvrzení předané mimo
 * aplikaci).
 */
final class PayrollTaxableIncomeConfirmationRequestRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollChecklistDeadlinePolicy $policy = new PayrollChecklistDeadlinePolicy(),
    ) {}

    /**
     * @return list<array{id:int,employee_id:int,employment_id:?int,requested_on:string,income_year:int,due_on:string,deadline_source:string,status:string,completed_on:?string,completion_kind:?string,note:?string,row_version:int}>|null
     */
    public function list(int $supplierId, int $employeeId): ?array
    {
        if (!$this->employeeExists($supplierId, $employeeId)) {
            return null;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT request.*, ' . $this->issuedExpression() . ' AS certificate_issued
               FROM payroll_taxable_income_confirmation_requests request
              WHERE request.supplier_id = ? AND request.employee_id = ?
              ORDER BY request.requested_on DESC, request.id DESC'
        );
        $statement->execute([$supplierId, $employeeId]);

        return array_map(fn (array $row): array => $this->present($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string,mixed> $input
     * @return list<array<string,mixed>>
     */
    public function create(int $supplierId, int $employeeId, array $input, int $actorId): array
    {
        if (!$this->employeeExists($supplierId, $employeeId)) {
            throw new \OutOfBoundsException('Zaměstnanec nenalezen.');
        }
        $requestedOn = $this->date($input['requested_on'] ?? null, 'Den žádosti');
        if ($requestedOn > (new DateTimeImmutable('today'))->format('Y-m-d')) {
            throw new \InvalidArgumentException('Den žádosti nemůže být v budoucnosti.');
        }
        $year = filter_var($input['income_year'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 2000, 'max_range' => (int) substr($requestedOn, 0, 4)],
        ]);
        if (!is_int($year)) {
            throw new \InvalidArgumentException('Rok příjmů musí být rok, za který už příjmy plynuly (nejpozději rok žádosti).');
        }
        $employmentId = $input['employment_id'] ?? null;
        if ($employmentId !== null && $employmentId !== '') {
            $employmentId = filter_var($employmentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($employmentId) || !$this->employmentBelongs($supplierId, $employeeId, $employmentId)) {
                throw new \InvalidArgumentException('Pracovní vztah nepatří zaměstnanci.');
            }
        } else {
            $employmentId = null;
        }
        $note = $input['note'] ?? null;
        if ($note !== null && (!is_string($note) || mb_strlen(trim($note), 'UTF-8') > 500)) {
            throw new \InvalidArgumentException('Poznámka může mít nejvýš 500 znaků.');
        }
        $note = $note === null || trim($note) === '' ? null : trim($note);
        $deadline = $this->policy->taxableIncomeConfirmationOnRequest($requestedOn);

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_taxable_income_confirmation_requests
                (supplier_id, employee_id, employment_id, requested_on, income_year,
                 due_on, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $employeeId,
            $employmentId,
            $requestedOn,
            $year,
            $deadline->dueOn,
            $note,
            $actorId,
        ]);

        return $this->list($supplierId, $employeeId) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function complete(int $supplierId, int $employeeId, int $requestId, mixed $completedOn, int $actorId): array
    {
        $request = $this->find($supplierId, $employeeId, $requestId);
        $completedOn = $this->date($completedOn, 'Den vyřízení');
        if ($completedOn < (string) $request['requested_on']) {
            throw new \InvalidArgumentException('Žádost nelze vyřídit dřív, než byla podána.');
        }
        $this->db->pdo()->prepare(
            'UPDATE payroll_taxable_income_confirmation_requests
                SET completed_on = ?, completion_kind = "manual", completed_by = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND employee_id = ? AND id = ? AND completed_on IS NULL'
        )->execute([$completedOn, $actorId, $supplierId, $employeeId, $requestId]);

        return $this->list($supplierId, $employeeId) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function remove(int $supplierId, int $employeeId, int $requestId): array
    {
        $this->find($supplierId, $employeeId, $requestId);
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_taxable_income_confirmation_requests
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        )->execute([$supplierId, $employeeId, $requestId]);

        return $this->list($supplierId, $employeeId) ?? [];
    }

    /**
     * Nevyřízené žádosti s termínem v okně — pramen hlídače termínů.
     *
     * @return list<array{request_id:int,employee_id:int,employment_id:?int,full_name:string,requested_on:string,income_year:int,due_on:string}>
     */
    public function openDeadlines(int $supplierId, string $from, string $to): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT request.id AS request_id, request.employee_id, request.employment_id,
                    employee.full_name, request.requested_on, request.income_year, request.due_on
               FROM payroll_taxable_income_confirmation_requests request
               JOIN payroll_employees employee
                 ON employee.supplier_id = request.supplier_id
                AND employee.id = request.employee_id
              WHERE request.supplier_id = ?
                AND request.completed_on IS NULL
                AND request.due_on BETWEEN ? AND ?
                AND NOT ' . $this->issuedExpression() . '
              ORDER BY request.due_on, request.id'
        );
        $statement->execute([$supplierId, $from, $to]);

        return array_map(static fn (array $row): array => [
            'request_id' => (int) $row['request_id'],
            'employee_id' => (int) $row['employee_id'],
            'employment_id' => $row['employment_id'] === null ? null : (int) $row['employment_id'],
            'full_name' => (string) $row['full_name'],
            'requested_on' => (string) $row['requested_on'],
            'income_year' => (int) $row['income_year'],
            'due_on' => (string) $row['due_on'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function issuedExpression(): string
    {
        return PayrollChecklistEvidenceSql::taxableIncomeCertificateIssued(
            'request.supplier_id',
            'request.employee_id',
            'request.requested_on',
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,employee_id:int,employment_id:?int,requested_on:string,income_year:int,due_on:string,deadline_source:string,status:string,completed_on:?string,completion_kind:?string,note:?string,row_version:int}
     */
    private function present(array $row): array
    {
        $completedOn = $row['completed_on'] === null ? null : (string) $row['completed_on'];
        $issued = (bool) $row['certificate_issued'];
        $status = match (true) {
            $completedOn !== null => 'completed',
            $issued => 'certificate_issued',
            default => 'open',
        };

        return [
            'id' => (int) $row['id'],
            'employee_id' => (int) $row['employee_id'],
            'employment_id' => $row['employment_id'] === null ? null : (int) $row['employment_id'],
            'requested_on' => (string) $row['requested_on'],
            'income_year' => (int) $row['income_year'],
            'due_on' => (string) $row['due_on'],
            'deadline_source' => (string) $this->policy
                ->taxableIncomeConfirmationOnRequest((string) $row['requested_on'])->source,
            'status' => $status,
            'completed_on' => $completedOn,
            'completion_kind' => $row['completion_kind'] === null ? null : (string) $row['completion_kind'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'row_version' => (int) $row['row_version'],
        ];
    }

    /** @return array<string,mixed> */
    private function find(int $supplierId, int $employeeId, int $requestId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_taxable_income_confirmation_requests
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $employeeId, $requestId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \OutOfBoundsException('Žádost o potvrzení nenalezena.');
        }

        return $row;
    }

    private function employeeExists(int $supplierId, int $employeeId): bool
    {
        $statement = $this->db->pdo()->prepare('SELECT 1 FROM payroll_employees WHERE supplier_id = ? AND id = ?');
        $statement->execute([$supplierId, $employeeId]);

        return $statement->fetchColumn() !== false;
    }

    private function employmentBelongs(int $supplierId, int $employeeId, int $employmentId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_employments WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $employeeId, $employmentId]);

        return $statement->fetchColumn() !== false;
    }

    private function date(mixed $value, string $label): string
    {
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("„{$label}“ musí být datum ve tvaru DD. MM. RRRR.");
        }

        return $value;
    }
}
