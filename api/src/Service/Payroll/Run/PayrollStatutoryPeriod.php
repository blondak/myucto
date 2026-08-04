<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final readonly class PayrollStatutoryPeriod
{
    public function __construct(
        public string $periodStart,
        public string $periodEnd,
        public string $paymentDate,
        public string $taxCalculationDate,
        public string $socialCalculationDate,
        public string $healthCalculationDate,
    ) {}

    /** @return array<string,string> */
    public function toSnapshot(): array
    {
        return [
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'payment_date' => $this->paymentDate,
            'tax_calculation_date' => $this->taxCalculationDate,
            'social_calculation_date' => $this->socialCalculationDate,
            'health_calculation_date' => $this->healthCalculationDate,
        ];
    }
}
