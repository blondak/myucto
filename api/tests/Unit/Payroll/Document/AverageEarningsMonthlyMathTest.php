<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\AverageEarningsMonthlyMath;
use PHPUnit\Framework\TestCase;

final class AverageEarningsMonthlyMathTest extends TestCase
{
    public function testGrossMonthlyUsesLabourCodeCoefficient(): void
    {
        // 250 Kč/h × 40 h × 4,348 = 43 480 Kč
        self::assertSame(
            4_348_000,
            AverageEarningsMonthlyMath::grossMonthlyMinorUnits(25_000, 40_000),
        );
    }

    public function testGrossMonthlyRoundsHalfUpToMinorUnit(): void
    {
        // 100,01 Kč/h × 37,5 h × 4,348 = 16 306,6305 Kč → 1 630 663 haléřů
        self::assertSame(
            1_630_663,
            AverageEarningsMonthlyMath::grossMonthlyMinorUnits(10_001, 37_500),
        );
    }

    public function testWeeklyHoursAreWeightedByCalendarDaysAndRoundedUp(): void
    {
        // 40 h po 30 dnů a 20 h po 61 dnů: (40×30 + 20×61) / 91 = 26,593406…
        self::assertSame(
            26_594,
            AverageEarningsMonthlyMath::weightedWeeklyHoursMilli([
                ['weekly_hours_milli' => 40_000, 'calendar_days' => 30],
                ['weekly_hours_milli' => 20_000, 'calendar_days' => 61],
            ]),
        );
    }

    public function testSingleIntervalKeepsExactWeeklyHours(): void
    {
        self::assertSame(
            37_500,
            AverageEarningsMonthlyMath::weightedWeeklyHoursMilli([
                ['weekly_hours_milli' => 37_500, 'calendar_days' => 91],
            ]),
        );
    }

    public function testEmptyEvidenceHasNoWeeklyHours(): void
    {
        self::assertNull(AverageEarningsMonthlyMath::weightedWeeklyHoursMilli([]));
    }

    public function testWeeklyHoursParsingRejectsNonNumericAndZero(): void
    {
        self::assertSame(40_000, AverageEarningsMonthlyMath::weeklyHoursMilli('40.00'));
        self::assertSame(37_500, AverageEarningsMonthlyMath::weeklyHoursMilli('37.5'));
        self::assertNull(AverageEarningsMonthlyMath::weeklyHoursMilli('0.00'));
        self::assertNull(AverageEarningsMonthlyMath::weeklyHoursMilli('-40.00'));
        self::assertNull(AverageEarningsMonthlyMath::weeklyHoursMilli('čtyřicet'));
    }

    public function testNetIsReportedInWholeCrowns(): void
    {
        self::assertSame(
            3_500_000,
            AverageEarningsMonthlyMath::roundHalfUpToWholeCzk(3_499_950),
        );
        self::assertSame(
            3_499_900,
            AverageEarningsMonthlyMath::roundHalfUpToWholeCzk(3_499_949),
        );
    }

    public function testCalendarDaysAreInclusive(): void
    {
        self::assertSame(
            91,
            AverageEarningsMonthlyMath::calendarDays('2026-04-01', '2026-06-30'),
        );
    }
}
