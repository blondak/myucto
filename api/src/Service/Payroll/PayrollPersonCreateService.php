<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Repository\PayrollEmployeeRepository;
use MyInvoice\Service\ActivityLogger;

final class PayrollPersonCreateService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollPersonCreateValidator $validator,
        private readonly PayrollEmployeeRepository $employees,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollPeopleRepository $people,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(
        int $supplierId,
        array $input,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $validated = $this->validator->validate($input);

        return $this->transactional(function () use (
            $supplierId,
            $validated,
            $userId,
            $ip,
            $userAgent,
        ): array {
            $employeeId = $this->employees->insert($supplierId, $validated['employee']);
            $this->db->pdo()->prepare(
                "INSERT INTO payroll_employee_profiles
                    (supplier_id, employee_id, profile_status)
                 VALUES (?, ?, 'setup')"
            )->execute([$supplierId, $employeeId]);

            $employment = $validated['employment'];
            $employment['code'] = 'ZAM-' . $employeeId;
            $this->employments->create(
                $supplierId,
                $employeeId,
                $employment,
                $userId,
                $ip,
                $userAgent,
            );
            $this->activityLogger->log(
                'payroll.person.created',
                $userId,
                'payroll_employee',
                $employeeId,
                [
                    'relation_type' => $employment['relation_type'],
                    'planned_start_on' => $employment['terms']['planned_start_on'],
                ],
                $ip,
                $userAgent,
                $supplierId,
            );

            return $this->people->findForTenant($supplierId, $employeeId)
                ?? throw new \LogicException('Nově založený zaměstnanec nebyl nalezen.');
        });
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_person_create');
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_person_create');
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_person_create');
                $pdo->exec('RELEASE SAVEPOINT payroll_person_create');
            }
            throw $e;
        }
    }
}
