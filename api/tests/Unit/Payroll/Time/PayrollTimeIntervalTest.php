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
