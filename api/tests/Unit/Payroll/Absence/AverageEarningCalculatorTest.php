<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Absence;

use MyInvoice\Service\Payroll\Absence\AverageEarningCalculator;
use PHPUnit\Framework\TestCase;

final class AverageEarningCalculatorTest extends TestCase
{
    public function testActualAverageUsesWorkedMinutesAndMinorUnits(): void
    {
        $result = (new AverageEarningCalculator())->calculate(
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
        (new AverageEarningCalculator())->calculate(2_000_000, 0, 1_200, 20);
    }

    public function testProbableAverageRequiresAndPreservesRationale(): void
    {
        $result = (new AverageEarningCalculator())->calculate(
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
}
