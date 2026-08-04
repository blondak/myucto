<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use JsonSerializable;

final readonly class PayoutAllocationResult implements JsonSerializable
{
    /** @param list<PayoutAllocation> $allocations */
    public function __construct(
        public int $netPayableMinorUnits,
        public array $allocations,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'net_payable_minor_units' => $this->netPayableMinorUnits,
            'allocations' => array_map(
                static fn (PayoutAllocation $allocation): array =>
                    $allocation->jsonSerialize(),
                $this->allocations,
            ),
        ];
    }
}
