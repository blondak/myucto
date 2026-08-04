<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\LeaveEntitlementCalculator;
use PHPUnit\Framework\TestCase;

final class LeaveEntitlementCalculatorTest extends TestCase
{
    public function testFullYearEmploymentEntitlementIsRoundedUpToWholeHours(): void
    {
        $result = (new LeaveEntitlementCalculator())->calculate(
            'employment',
            2_400,
            4,
            365,
            124_800,
            'Započitatelné doby ručně ověřeny.',
        );

        self::assertSame(52, $result->workedWeekMultiples);
        self::assertSame(9_600, $result->entitlementMinutes);
        self::assertSame('manual_review', $result->supportStatus);
    }

    public function testAgreementUsesStatutoryFictionalTwentyHourWeek(): void
    {
        $result = (new LeaveEntitlementCalculator())->calculate(
            'dpp',
            2_400,
            4,
            365,
            62_400,
            'DPP a náhradní doby ručně ověřeny.',
        );

        self::assertSame(1_200, $result->weeklyMinutes);
        self::assertSame(4_800, $result->entitlementMinutes);
    }

    public function testLessThanFourWeeksFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LeaveEntitlementCalculator())->calculate(
            'employment',
            2_400,
            4,
            27,
            9_600,
            'Kontrola.',
        );
    }

    public function testWorkedTimeCannotCreateMoreThanFullYearEntitlement(): void
    {
        $result = (new LeaveEntitlementCalculator())->calculate(
            'employment',
            2_400,
            4,
            365,
            134_400,
            'Přesčas a náhradní doby ručně ověřeny.',
        );

        self::assertSame(52, $result->workedWeekMultiples);
        self::assertSame(9_600, $result->entitlementMinutes);
    }
}
