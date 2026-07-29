<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank\Detect;

interface BankTransactionDetector
{
    public function key(): string;

    public function tier(): int;

    /** @param array<string,mixed> $tx */
    public function detect(int $supplierId, array $tx): ?DetectionResult;
}
