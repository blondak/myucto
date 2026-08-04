<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;

final readonly class PayrollGarnishmentRunIntegration
{
    public function __construct(
        private PayrollGarnishmentPort $calculator,
        private PayrollGarnishmentSnapshotWriter $writer,
    ) {}

    public function calculateAndStore(
        EnforcementPersonMonthRequest $request,
        ?int $revisionId,
        string $idempotencyKey,
    ): PayrollGarnishmentRunResult {
        if ($revisionId !== null && $revisionId <= 0) {
            throw new InvalidArgumentException('Payroll revision ID must be positive.');
        }
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Garnishment run idempotency key is required.');
        }

        $calculation = $this->calculator->calculate($request);
        return $this->storeCalculation(
            $request,
            $calculation,
            $revisionId,
            $idempotencyKey,
        );
    }

    public function storeCalculation(
        EnforcementPersonMonthRequest $request,
        PayrollGarnishmentCalculation $calculation,
        ?int $revisionId,
        string $idempotencyKey,
    ): PayrollGarnishmentRunResult {
        if ($revisionId !== null && $revisionId <= 0) {
            throw new InvalidArgumentException('Payroll revision ID must be positive.');
        }
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Garnishment run idempotency key is required.');
        }
        $snapshotId = $this->writer->store(
            $request,
            $calculation,
            $revisionId,
            $idempotencyKey,
        );

        return new PayrollGarnishmentRunResult($snapshotId, $calculation->result);
    }
}
