<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use InvalidArgumentException;
use JsonSerializable;
use OverflowException;

final readonly class CalculationStep implements JsonSerializable
{
    private function __construct(
        public string $label,
        public int $inputMinorUnits,
        public DecimalRate $rate,
        public int $unroundedNumerator,
        public int $unroundedDenominator,
        public RoundingMode $roundingMode,
        public int $outputMinorUnits,
    ) {}

    public static function calculate(
        string $label,
        int $inputMinorUnits,
        DecimalRate $rate,
        RoundingMode $roundingMode,
    ): self {
        if (trim($label) === '') {
            throw new InvalidArgumentException('Calculation step label must not be empty.');
        }

        $numerator = self::multiplyExactly($inputMinorUnits, $rate->numerator);
        $output = $roundingMode->roundFraction($numerator, $rate->denominator);

        return new self(
            $label,
            $inputMinorUnits,
            $rate,
            $numerator,
            $rate->denominator,
            $roundingMode,
            $output,
        );
    }

    /**
     * @return array{
     *   label:string,
     *   input_minor_units:int,
     *   rate:array{decimal:string,numerator:int,scale:int,denominator:int},
     *   unrounded_numerator:int,
     *   unrounded_denominator:int,
     *   rounding_mode:string,
     *   output_minor_units:int
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'label' => $this->label,
            'input_minor_units' => $this->inputMinorUnits,
            'rate' => $this->rate->jsonSerialize(),
            'unrounded_numerator' => $this->unroundedNumerator,
            'unrounded_denominator' => $this->unroundedDenominator,
            'rounding_mode' => $this->roundingMode->value,
            'output_minor_units' => $this->outputMinorUnits,
        ];
    }

    public function toCanonicalJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }

    private static function multiplyExactly(int $left, int $right): int
    {
        if ($left === 0 || $right === 0) {
            return 0;
        }

        $overflows = match (true) {
            $left > 0 && $right > 0 => $left > intdiv(PHP_INT_MAX, $right),
            $left > 0 => $right < intdiv(PHP_INT_MIN, $left),
            $right > 0 => $left < intdiv(PHP_INT_MIN, $right),
            default => $left < intdiv(PHP_INT_MAX, $right),
        };

        if ($overflows) {
            throw new OverflowException('Unrounded calculation exceeds the integer range.');
        }

        return $left * $right;
    }
}
