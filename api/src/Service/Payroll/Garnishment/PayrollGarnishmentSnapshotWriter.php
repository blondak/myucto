<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

interface PayrollGarnishmentSnapshotWriter
{
    public function store(
        EnforcementPersonMonthRequest $request,
        PayrollGarnishmentCalculation $calculation,
        ?int $revisionId,
        string $idempotencyKey,
    ): int;
}
