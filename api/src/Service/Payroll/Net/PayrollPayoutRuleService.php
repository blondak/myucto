<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use MyInvoice\Repository\Payroll\PayrollPayoutRuleRepository;

/**
 * Zápisová cesta k výplatním pravidlům.
 *
 * Existuje proto, aby validace neseděla v Action (kde by ji obešel jakýkoli jiný
 * volající — import, CLI, budoucí bulk operace) a zároveň nebyla v repozitáři
 * (který nesmí znát doménová pravidla).
 *
 * Pořadí uvnitř transakce je závazné:
 *   1. zamkni CELOU sadu pravidel osoby (FOR UPDATE),
 *   2. teprve nad zamčenou sadou validuj,
 *   3. zapiš.
 * Bez kroku 1 by dva souběžné požadavky nezávisle prošly kontrolou „právě jeden
 * zbytek" a rozdíl by odchytil až unikátní index migrace 1378.
 */
final class PayrollPayoutRuleService
{
    public function __construct(
        private readonly PayrollPayoutRuleRepository $repository,
        private readonly PayrollPayoutRuleValidator $validator,
    ) {}

    /** @return list<array<string,mixed>> */
    public function listForEmployee(int $supplierId, int $employeeId): array
    {
        $this->repository->assertEmployee($supplierId, $employeeId);

        return $this->repository->listForEmployee($supplierId, $employeeId);
    }

    /** @return array<string,mixed> */
    public function create(
        int $supplierId,
        int $employeeId,
        PayrollPayoutRuleInput $input,
        ?string $allocationReference = null,
    ): array {
        return $this->repository->transaction(function () use (
            $supplierId,
            $employeeId,
            $input,
            $allocationReference,
        ): array {
            $this->repository->assertEmployee($supplierId, $employeeId);
            $current = $this->repository->lockForEmployee($supplierId, $employeeId);
            $this->validator->assertWritable(
                $supplierId,
                $employeeId,
                $input,
                $current,
            );

            return $this->repository->create(
                $supplierId,
                $employeeId,
                $input,
                $allocationReference,
            );
        });
    }

    /** @return array<string,mixed> */
    public function update(
        int $supplierId,
        int $employeeId,
        int $ruleId,
        PayrollPayoutRuleInput $input,
        int $rowVersion,
    ): array {
        return $this->repository->transaction(function () use (
            $supplierId,
            $employeeId,
            $ruleId,
            $input,
            $rowVersion,
        ): array {
            $this->repository->assertEmployee($supplierId, $employeeId);
            $current = $this->repository->lockForEmployee($supplierId, $employeeId);
            if ($this->byId($current, $ruleId) === null) {
                throw new \OutOfBoundsException(
                    'Výplatní pravidlo nebylo nalezeno.',
                );
            }
            $this->validator->assertWritable(
                $supplierId,
                $employeeId,
                $input,
                $current,
                $ruleId,
            );

            return $this->repository->update(
                $supplierId,
                $employeeId,
                $ruleId,
                $input,
                $rowVersion,
            );
        });
    }

    /** @return array<string,mixed> */
    public function deactivate(
        int $supplierId,
        int $employeeId,
        int $ruleId,
        int $rowVersion,
    ): array {
        return $this->repository->transaction(function () use (
            $supplierId,
            $employeeId,
            $ruleId,
            $rowVersion,
        ): array {
            $this->repository->assertEmployee($supplierId, $employeeId);
            $this->repository->lockForEmployee($supplierId, $employeeId);

            return $this->repository->deactivate(
                $supplierId,
                $employeeId,
                $ruleId,
                $rowVersion,
            );
        });
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @return array<string,mixed>|null
     */
    private function byId(array $rules, int $ruleId): ?array
    {
        foreach ($rules as $rule) {
            if ($rule['id'] === $ruleId) {
                return $rule;
            }
        }

        return null;
    }
}
