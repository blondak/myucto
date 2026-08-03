<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Repository\Payroll\PayrollTimeValue;

final class PayrollCalendarFundService
{
    public function __construct(private readonly CzechHolidayCalendar $holidays) {}

    /**
     * @param array<int,int> $weekPattern
     * @param list<array<string,mixed>> $overrides
     * @return array{fund_minutes:int,days:list<array<string,mixed>>}
     */
    public function month(
        string $period,
        array $weekPattern,
        array $overrides = [],
    ): array {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
        if ($start === false || $start->format('Y-m') !== $period) {
            throw new \InvalidArgumentException('period musí být ve formátu YYYY-MM.');
        }
        $end = $start->modify('first day of next month');
        $holidayMap = $this->holidays->forYear((int) $start->format('Y'));
        $overrideMap = [];
        foreach ($overrides as $override) {
            $date = $override['day_date'] ?? null;
            if (is_string($date)) {
                $overrideMap[$date] = $override;
            }
        }

        $days = [];
        $fund = 0;
        for ($date = $start; $date < $end; $date = $date->modify('+1 day')) {
            $iso = $date->format('Y-m-d');
            $weekday = $date->format('N');
            $weekend = (int) $weekday >= 6;
            $holiday = $holidayMap[$iso] ?? null;
            $override = $overrideMap[$iso] ?? null;
            $planned = max(0, (int) ($weekPattern[$weekday] ?? 0));
            $kind = $planned > 0 ? 'workday' : 'non_working';
            if ($holiday !== null) {
                $kind = 'holiday';
                $planned = 0;
            }
            if (is_array($override)) {
                $kind = PayrollTimeValue::string($override['day_kind'] ?? $kind, 'day_kind');
                $planned = max(
                    0,
                    PayrollTimeValue::int(
                        $override['planned_minutes'] ?? $planned,
                        'planned_minutes',
                    ),
                );
                if ($kind === 'holiday') {
                    $holiday = [
                        'code' => PayrollTimeValue::string(
                            $override['holiday_code'] ?? $holiday['code'] ?? 'custom',
                            'holiday_code',
                        ),
                        'name' => PayrollTimeValue::string(
                            $override['holiday_name'] ?? $holiday['name'] ?? 'Svátek',
                            'holiday_name',
                        ),
                    ];
                }
            }

            $fund += $planned;
            $days[] = [
                'date' => $iso,
                'weekday' => (int) $weekday,
                'is_weekend' => $weekend,
                'is_holiday' => $kind === 'holiday',
                'day_kind' => $kind,
                'planned_minutes' => $planned,
                'holiday_code' => $holiday['code'] ?? null,
                'holiday_name' => $holiday['name'] ?? null,
            ];
        }

        return ['fund_minutes' => $fund, 'days' => $days];
    }
}
