<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use JsonSerializable;

final readonly class PayoutAllocation implements JsonSerializable
{
    public function __construct(
        public string $allocationReference,
        public string $destinationKind,
        public ?string $destinationReference,
        public string $allocationKind,
        public int $amountMinorUnits,
        public int $priority,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'allocation_reference' => $this->allocationReference,
            'destination_kind' => $this->destinationKind,
            'destination_reference' => $this->destinationReference,
            'allocation_kind' => $this->allocationKind,
            'amount_minor_units' => $this->amountMinorUnits,
            'priority' => $this->priority,
        ];
    }
}
