<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Výsledek přepočtu schváleného hodinového průměru (MZ-07) na měsíční formy
 * průměrného výdělku podle § 356 zákoníku práce. Čisté částky jsou vyplněné
 * jen tehdy, když si je volající vyžádal a všechny podmínky odst. 3 byly
 * doložené — jinak zůstávají null a odpovědnost nese fail-closed větev.
 */
final readonly class AverageEarningsMonthlyConversion
{
    /**
     * @param list<array{
     *   term_id:int,
     *   effective_from:string,
     *   effective_to:string,
     *   weekly_hours_milli:int,
     *   calendar_days:int
     * }> $weeklyHoursIntervals
     * @param array<string,mixed> $trace
     */
    public function __construct(
        public int $approvedHourlyMinorUnits,
        public int $appliedHourlyMinorUnits,
        public bool $minimumWageFloorApplied,
        public int $weeklyHoursMilli,
        public array $weeklyHoursIntervals,
        public int $grossMonthlyMinorUnits,
        public ?int $socialInsuranceMinorUnits,
        public ?int $healthInsuranceMinorUnits,
        public ?int $advanceTaxMinorUnits,
        public ?int $netMonthlyExactMinorUnits,
        public ?int $netMonthlyMinorUnits,
        public array $trace,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'approved_hourly_minor_units' => $this->approvedHourlyMinorUnits,
            'applied_hourly_minor_units' => $this->appliedHourlyMinorUnits,
            'minimum_wage_floor_applied' => $this->minimumWageFloorApplied,
            'weekly_hours_milli' => $this->weeklyHoursMilli,
            'weekly_hours_intervals' => $this->weeklyHoursIntervals,
            'gross_monthly_minor_units' => $this->grossMonthlyMinorUnits,
            'social_insurance_minor_units' => $this->socialInsuranceMinorUnits,
            'health_insurance_minor_units' => $this->healthInsuranceMinorUnits,
            'advance_tax_minor_units' => $this->advanceTaxMinorUnits,
            'net_monthly_exact_minor_units' =>
                $this->netMonthlyExactMinorUnits,
            'net_monthly_minor_units' => $this->netMonthlyMinorUnits,
            'trace' => $this->trace,
        ];
    }
}
