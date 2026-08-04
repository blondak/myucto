<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

final readonly class MoneyRateCalculationResult
{
    public function __construct(
        public Money $money,
        public CalculationStep $step,
    ) {}
}
