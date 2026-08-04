<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use JsonSerializable;

final readonly class PayrollDeductionResult implements JsonSerializable
{
    public function __construct(
        public string $deductionReference,
        public int $priority,
        public int $requestedMinorUnits,
        public int $appliedMinorUnits,
        public int $unappliedMinorUnits,
        public bool $active,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'deduction_reference' => $this->deductionReference,
            'priority' => $this->priority,
            'requested_minor_units' => $this->requestedMinorUnits,
            'applied_minor_units' => $this->appliedMinorUnits,
            'unapplied_minor_units' => $this->unappliedMinorUnits,
            'active' => $this->active,
        ];
    }
}
