<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

final class MoneyRateCalculator
{
    public function multiply(
        Money $input,
        DecimalRate $rate,
        RoundingMode $roundingMode,
        string $label,
    ): MoneyRateCalculationResult {
        $step = CalculationStep::calculate(
            $label,
            $input->minorUnits,
            $rate,
            $roundingMode,
        );

        return new MoneyRateCalculationResult(
            new Money($step->outputMinorUnits, $input->currency),
            $step,
        );
    }
}
