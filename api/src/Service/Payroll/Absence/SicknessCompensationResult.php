<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

final readonly class SicknessCompensationResult
{
    /**
     * @param list<array<string,int|string|null>> $segments
     * @param array<string,int|string> $trace
     */
    public function __construct(
        public int $reducedHourlyMinor,
        public int $compensationMinor,
        public string $supportStatus,
        public string $rulesetId,
        public string $rulesetHash,
        public array $segments,
        public array $trace,
    ) {}
}
