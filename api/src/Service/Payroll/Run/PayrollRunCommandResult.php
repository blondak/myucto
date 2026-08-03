<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final readonly class PayrollRunCommandResult
{
    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed>|null $revision
     */
    public function __construct(
        public PayrollRunCommand $command,
        public PayrollRunStatus $from,
        public PayrollRunStatus $to,
        public array $run,
        public ?array $revision,
        public bool $idempotentReplay,
    ) {}
}
