<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final readonly class PayrollRunTransition
{
    public function __construct(
        public PayrollRunStatus $from,
        public PayrollRunStatus $to,
        public PayrollRunCommand $command,
    ) {}
}
