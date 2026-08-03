<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

final readonly class PayrollGarnishmentRunResult
{
    public function __construct(
        public int $snapshotId,
        public GarnishmentResult $calculation,
    ) {}
}
