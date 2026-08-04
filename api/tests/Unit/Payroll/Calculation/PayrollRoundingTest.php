<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Calculation;

use MyInvoice\Service\Payroll\Calculation\PayrollRounding;
use PHPUnit\Framework\TestCase;

final class PayrollRoundingTest extends TestCase
{
    public function testRoundsStatutoryPositiveAmountsUpToRequiredUnit(): void
    {
        self::assertSame(12_300, PayrollRounding::ceilToCzk(12_201));
        self::assertSame(12_300, PayrollRounding::ceilToCzk(12_300));
        self::assertSame(50_000, PayrollRounding::ceilToHundredCzk(45_801));
    }

    public function testRoundsExactFractionSumOnlyOnce(): void
    {
        self::assertSame(2_635_900, PayrollRounding::ceilFractionSumToMultiple([
            ['numerator' => 14_690_100 * 15, 'denominator' => 100],
            ['numerator' => 1_879_900 * 23, 'denominator' => 100],
        ], 100));
    }
}
