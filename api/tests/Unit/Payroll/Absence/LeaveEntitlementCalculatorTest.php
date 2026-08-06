<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\LeaveEntitlementCalculator;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Tests\Fixtures\Payroll\ShiftedYearPayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

final class LeaveEntitlementCalculatorTest extends TestCase
{
    public function testFullYearEmploymentEntitlementIsRoundedUpToWholeHours(): void
    {
        $result = $this->calculator()->calculate(
            '2026-01-01',
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
        $result = $this->calculator()->calculate(
            '2026-01-01',
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
        $this->calculator()->calculate(
            '2026-01-01',
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
        $result = $this->calculator()->calculate(
            '2026-01-01',
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

    public function testEntitlementBelowStatutoryMinimumWeeksFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('zákonné minimum 4 týdny');
        $this->calculator()->calculate(
            '2026-01-01',
            'employment',
            2_400,
            1,
            365,
            124_800,
            'Pokus o podlimitní výměru.',
        );
    }

    public function testStatutoryParametersComeFromRulesetNotFromLiterals(): void
    {
        $trace = $this->calculator()->calculate(
            '2026-01-01',
            'employment',
            2_400,
            4,
            365,
            124_800,
            'Kontrola stopy.',
        )->trace;

        self::assertSame(4, $trace['statutory_minimum_weeks']);
        self::assertSame(28, $trace['minimum_continuous_calendar_days']);
        self::assertSame(52, $trace['weeks_per_year']);
    }

    public function testYearWithoutRulesetFailsClosed(): void
    {
        $this->expectException(\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException::class);
        $this->calculator()->calculate(
            '2027-01-01',
            'employment',
            2_400,
            4,
            365,
            124_800,
            'Rok bez rulesetu.',
        );
    }

    public function testCalendarShiftWorksAsSoonAsNextYearRulesetExists(): void
    {
        $calculator = new LeaveEntitlementCalculator(
            ShiftedYearPayrollRulesetFixture::provider(2027),
        );

        $result = $calculator->calculate(
            '2027-01-01',
            'employment',
            2_400,
            4,
            365,
            124_800,
            'Nárok pro rok s rulesetem.',
        );

        self::assertSame(52, $result->workedWeekMultiples);
        self::assertSame(9_600, $result->entitlementMinutes);
    }

    private function calculator(): LeaveEntitlementCalculator
    {
        return new LeaveEntitlementCalculator(CzechPayrollRulesets2026::provider());
    }
}
