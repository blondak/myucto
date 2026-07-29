<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

final class PolicyInput
{
    /** @param array<string,mixed>|null $rule */
    public function __construct(
        public readonly string $operationType,
        public readonly string $source,
        public readonly ?float $confidence,
        public readonly float $amountCzk,
        public readonly string $currency,
        public readonly string $entryDate,
        public readonly string $debitAccountCode,
        public readonly string $creditAccountCode,
        public readonly ?array $rule = null,
        public readonly ?string $detectorKey = null,
        public readonly bool $autoAllowed = true,
        public readonly bool $crossCurrency = false,
        public readonly bool $duplicateSuspect = false,
        public readonly bool $anomaly = false,
    ) {}
}
