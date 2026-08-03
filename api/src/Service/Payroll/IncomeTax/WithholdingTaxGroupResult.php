<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use JsonSerializable;
use MyInvoice\Service\Payroll\Calculation\CalculationStep;

final readonly class WithholdingTaxGroupResult implements JsonSerializable
{
    public function __construct(
        public string $group,
        public int $baseMinorUnits,
        public int $taxMinorUnits,
        public CalculationStep $rateStep,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'group' => $this->group,
            'base_minor_units' => $this->baseMinorUnits,
            'tax_minor_units' => $this->taxMinorUnits,
            'rate_step' => $this->rateStep->jsonSerialize(),
        ];
    }
}
