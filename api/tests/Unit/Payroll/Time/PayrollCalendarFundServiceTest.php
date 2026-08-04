<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Payroll\Time\PayrollCalendarFundService;
use PHPUnit\Framework\TestCase;

final class PayrollCalendarFundServiceTest extends TestCase
{
    public function testCzechHolidaysReduceWeekdayFund(): void
    {
        $service = new PayrollCalendarFundService(new CzechHolidayCalendar());
        $result = $service->month('2026-05', $this->regularWeek());

        self::assertSame(19 * 480, $result['fund_minutes']);
        $holidays = array_values(array_filter(
            $result['days'],
            static fn (array $day): bool => $day['is_holiday'],
        ));
        self::assertSame(['2026-05-01', '2026-05-08'], array_column($holidays, 'date'));
    }

    public function testHolidayOnWeekendKeepsBothFlagsWithoutAddingFund(): void
    {
        $service = new PayrollCalendarFundService(new CzechHolidayCalendar());
        $result = $service->month('2026-07', $this->regularWeek());
        $day = array_values(array_filter(
            $result['days'],
            static fn (array $item): bool => $item['date'] === '2026-07-05',
        ))[0];

        self::assertTrue($day['is_holiday']);
        self::assertTrue($day['is_weekend']);
        self::assertSame(0, $day['planned_minutes']);
    }

    public function testExplicitOverrideCanScheduleWorkOnHoliday(): void
    {
        $service = new PayrollCalendarFundService(new CzechHolidayCalendar());
        $result = $service->month('2026-05', $this->regularWeek(), [[
            'day_date' => '2026-05-01',
            'day_kind' => 'workday',
            'planned_minutes' => 240,
        ]]);

        self::assertSame(19 * 480 + 240, $result['fund_minutes']);
    }

    /** @return array<string,int> */
    private function regularWeek(): array
    {
        return ['1' => 480, '2' => 480, '3' => 480, '4' => 480, '5' => 480, '6' => 0, '7' => 0];
    }
}
