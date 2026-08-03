<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class PayslipLine
{
    private const MAX_MINOR_UNITS = 1_000_000_000_000;

    public function __construct(
        public string $label,
        public int $amountMinorUnits,
    ) {
        if (trim($label) === '' || mb_strlen($label) > 160) {
            throw new \InvalidArgumentException('Payslip line label must not be empty.');
        }

        if ($amountMinorUnits < -self::MAX_MINOR_UNITS || $amountMinorUnits > self::MAX_MINOR_UNITS) {
            throw new \InvalidArgumentException('Payslip line amount is outside the supported range.');
        }
    }

    /** @return array{label:string,amount_minor_units:int} */
    public function toTemplateData(): array
    {
        return [
            'label' => $this->label,
            'amount_minor_units' => $this->amountMinorUnits,
        ];
    }
}
