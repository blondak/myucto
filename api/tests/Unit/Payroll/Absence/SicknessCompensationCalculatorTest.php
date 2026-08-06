<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\SicknessCompensationCalculator;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Tests\Fixtures\Payroll\ShiftedYearPayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

final class SicknessCompensationCalculatorTest extends TestCase
{
    public function testFirstBoundaryAndCompensationRateUseIntegerHalfUpRounding(): void
    {
        $result = $this->calculator()->calculate(
            '2026-06-15',
            28_578,
            [[
                'shift_id' => 10,
                'local_date' => '2026-06-15',
                'planned_minutes' => 60,
                'eligible_minutes' => 60,
            ]],
        );

        self::assertSame(25_720, $result->reducedHourlyMinor);
        self::assertSame(15_432, $result->compensationMinor);
        self::assertSame('manual_review', $result->supportStatus);
    }

    public function testAllThreeReductionBandsAreAppliedPerPublishedShift(): void
    {
        $result = $this->calculator()->calculate(
            '2026-06-15',
            50_000,
            [[
                'shift_id' => 11,
                'local_date' => '2026-06-15',
                'planned_minutes' => 480,
                'eligible_minutes' => 480,
            ]],
        );

        self::assertSame(36_431, $result->reducedHourlyMinor);
        self::assertSame(174_869, $result->compensationMinor);
        self::assertSame(6_000, $result->trace['compensation_basis_points']);
    }

    public function testMissingPublishedShiftFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator()->calculate('2026-06-15', 50_000, []);
    }

    public function testCompensationWindowIsTracedFromRuleset(): void
    {
        $result = $this->calculator()->calculate(
            '2026-06-15',
            50_000,
            [[
                'shift_id' => 12,
                'local_date' => '2026-06-15',
                'planned_minutes' => 480,
                'eligible_minutes' => 480,
            ]],
        );

        self::assertSame(14, $result->trace['window_calendar_days']);
    }

    public function testDateWithoutRulesetFailsClosed(): void
    {
        $this->expectException(\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException::class);
        $this->calculator()->calculate(
            '2027-06-15',
            50_000,
            [[
                'shift_id' => 13,
                'local_date' => '2027-06-15',
                'planned_minutes' => 480,
                'eligible_minutes' => 480,
            ]],
        );
    }

    public function testCalendarShiftWorksAsSoonAsNextYearRulesetExists(): void
    {
        $calculator = new SicknessCompensationCalculator(
            ShiftedYearPayrollRulesetFixture::provider(2027),
        );

        $result = $calculator->calculate(
            '2027-06-15',
            50_000,
            [[
                'shift_id' => 14,
                'local_date' => '2027-06-15',
                'planned_minutes' => 480,
                'eligible_minutes' => 480,
            ]],
        );

        self::assertSame(36_431, $result->reducedHourlyMinor);
        self::assertSame(174_869, $result->compensationMinor);
    }

    private function calculator(): SicknessCompensationCalculator
    {
        return new SicknessCompensationCalculator(CzechPayrollRulesets2026::provider());
    }
}
