<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Service\Payroll\Time\PayrollTimeInterval;
use PHPUnit\Framework\TestCase;

final class PayrollTimeIntervalTest extends TestCase
{
    public function testIntervalAcrossMidnightUsesRealInstants(): void
    {
        $interval = PayrollTimeInterval::fromIso(
            '2026-08-03T22:00:00+02:00',
            '2026-08-04T02:00:00+02:00',
            'Europe/Prague',
        );

        self::assertSame(240, $interval->durationMinutes);
        self::assertSame('2026-08-03 20:00:00', $interval->startsAtUtc);
        self::assertSame('2026-08-04 00:00:00', $interval->endsAtUtc);
    }

    public function testSpringDstGapCountsOnlyElapsedHour(): void
    {
        $interval = PayrollTimeInterval::fromIso(
            '2026-03-29T01:30:00+01:00',
            '2026-03-29T03:30:00+02:00',
            'Europe/Prague',
        );

        self::assertSame(60, $interval->durationMinutes);
    }

    public function testAutumnDstOverlapCanBeExpressedWithoutAmbiguity(): void
    {
        $interval = PayrollTimeInterval::fromIso(
            '2026-10-25T02:30:00+02:00',
            '2026-10-25T02:30:00+01:00',
            'Europe/Prague',
        );

        self::assertSame(60, $interval->durationMinutes);
    }

    public function testShiftWithEndOneDayLaterIsRejected(): void
    {
        // „Uložit a další den" posunul konec o den navíc: 08:00 → druhý den 16:30.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('24 hodin');
        PayrollTimeInterval::fromIso(
            '2026-09-15T08:00:00+02:00',
            '2026-09-16T16:30:00+02:00',
            'Europe/Prague',
        );
    }

    public function testNightShiftUpToTwentyFourHoursIsAccepted(): void
    {
        $interval = PayrollTimeInterval::fromIso(
            '2026-09-15T08:00:00+02:00',
            '2026-09-16T08:00:00+02:00',
            'Europe/Prague',
        );

        self::assertSame(1440, $interval->durationMinutes);
    }

    public function testExplicitLongerCapStillAppliesForTrips(): void
    {
        $interval = PayrollTimeInterval::fromIso(
            '2026-09-15T08:00:00+02:00',
            '2026-09-18T16:30:00+02:00',
            'Europe/Prague',
            21,
        );

        self::assertSame(3 * 1440 + 510, $interval->durationMinutes);
    }

    public function testOffsetThatDoesNotBelongToTimezoneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollTimeInterval::fromIso(
            '2026-03-29T03:30:00+01:00',
            '2026-03-29T04:30:00+02:00',
            'Europe/Prague',
        );
    }
}
