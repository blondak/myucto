<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use InvalidArgumentException;

final readonly class AnnualTaxAccumulatorInput
{
    public function __construct(
        public int $year,
        public int $completedMonths,
        public int $advanceBaseMinorUnits,
        public int $withholdingBaseMinorUnits,
        public int $advanceTaxMinorUnits,
        public int $withholdingTaxMinorUnits,
        public int $appliedNonRefundableCreditsMinorUnits,
        public int $appliedChildCreditMinorUnits,
        public int $taxBonusMinorUnits,
        public int $bonusQualifyingIncomeMinorUnits,
    ) {
        if ($year < 2000 || $year > 9999) {
            throw new InvalidArgumentException('Annual tax accumulator year is invalid.');
        }
        if ($completedMonths < 0 || $completedMonths > 11) {
            throw new InvalidArgumentException('Annual tax accumulator month count is invalid.');
        }
        foreach (get_object_vars($this) as $name => $value) {
            if (
                $name !== 'year'
                && $name !== 'completedMonths'
                && is_int($value)
                && $value < 0
            ) {
                throw new InvalidArgumentException("Annual tax accumulator {$name} cannot be negative.");
            }
        }
    }

    public static function empty(int $year): self
    {
        return new self($year, 0, 0, 0, 0, 0, 0, 0, 0, 0);
    }
}
