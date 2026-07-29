<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank\Detect;

final class DetectionResult
{
    public function __construct(
        public readonly string $operationType,
        public readonly string $source,
        public readonly float $confidence,
        public readonly string $debitAccountCode,
        public readonly string $creditAccountCode,
        public readonly ?string $description = null,
        public readonly ?int $scheduleId = null,
        public readonly ?string $note = null,
        public readonly bool $autoAllowed = true,
        public readonly string $detectorKey = 'tax_remittance',
    ) {}
}
