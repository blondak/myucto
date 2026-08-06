<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\AverageEarningCalculator;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Tests\Fixtures\Payroll\ShiftedYearPayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

final class AverageEarningCalculatorTest extends TestCase
{
    public function testActualAverageUsesWorkedMinutesAndMinorUnits(): void
    {
        $result = $this->calculator()->calculate(
            '2026-04-01',
            12_000_000,
            0,
            9_600,
            60,
        );

        self::assertSame('actual', $result->sourceKind);
        self::assertSame(75_000, $result->averageHourlyMinor);
        self::assertSame('manual_review', $result->supportStatus);
    }

    public function testFewerThanTwentyOneDaysFailsClosedWithoutProbableEarning(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator()->calculate('2026-04-01', 2_000_000, 0, 1_200, 20);
    }

    public function testProbableAverageRequiresAndPreservesRationale(): void
    {
        $result = $this->calculator()->calculate(
            '2026-04-01',
            2_000_000,
            0,
            1_200,
            20,
            42_500,
            'Srovnatelná práce a sjednaná mzda.',
        );

        self::assertSame('probable', $result->sourceKind);
        self::assertSame(42_500, $result->averageHourlyMinor);
        self::assertSame('Srovnatelná práce a sjednaná mzda.', $result->trace['rationale']);
    }

    public function testMinimumWorkedDaysComesFromRulesetNotFromLiteral(): void
    {
        $result = $this->calculator()->calculate('2026-04-01', 12_000_000, 0, 9_600, 60);

        self::assertSame(21, $result->trace['minimum_worked_days']);
    }

    public function testYearWithoutRulesetFailsClosed(): void
    {
        $this->expectException(\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException::class);
        $this->calculator()->calculate('2027-04-01', 12_000_000, 0, 9_600, 60);
    }

    public function testCalendarShiftWorksAsSoonAsNextYearRulesetExists(): void
    {
        $calculator = new AverageEarningCalculator(
            ShiftedYearPayrollRulesetFixture::provider(2027),
        );

        $result = $calculator->calculate('2027-04-01', 12_000_000, 0, 9_600, 60);

        self::assertSame('actual', $result->sourceKind);
        self::assertSame(75_000, $result->averageHourlyMinor);
    }

    private function calculator(): AverageEarningCalculator
    {
        return new AverageEarningCalculator(CzechPayrollRulesets2026::provider());
    }
}
