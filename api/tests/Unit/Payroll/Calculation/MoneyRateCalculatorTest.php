<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Calculation;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\Money;
use MyInvoice\Service\Payroll\Calculation\MoneyRateCalculator;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyRateCalculatorTest extends TestCase
{
    #[DataProvider('roundingCases')]
    public function testAllRoundingModesHandlePositiveAndNegativeFractions(
        RoundingMode $mode,
        int $positive,
        int $negative,
    ): void {
        self::assertSame($positive, $mode->roundFraction(5, 2));
        self::assertSame($negative, $mode->roundFraction(-5, 2));
        self::assertSame(0, $mode->roundFraction(0, 2));
    }

    /** @return array<string, array{RoundingMode, int, int}> */
    public static function roundingCases(): array
    {
        return [
            'half up' => [RoundingMode::HalfUp, 3, -3],
            'floor' => [RoundingMode::Floor, 2, -3],
            'ceil' => [RoundingMode::Ceil, 3, -2],
            'toward zero' => [RoundingMode::TowardZero, 2, -2],
            'away from zero' => [RoundingMode::AwayFromZero, 3, -3],
        ];
    }

    public function testHalfUpOnlyRoundsAtOrAboveHalf(): void
    {
        self::assertSame(2, RoundingMode::HalfUp->roundFraction(24, 10));
        self::assertSame(3, RoundingMode::HalfUp->roundFraction(25, 10));
        self::assertSame(-2, RoundingMode::HalfUp->roundFraction(-24, 10));
        self::assertSame(-3, RoundingMode::HalfUp->roundFraction(-25, 10));
    }

    public function testItCalculatesFifteenPercentAndReturnsReproducibleTrace(): void
    {
        $result = (new MoneyRateCalculator())->multiply(
            new Money(10_005),
            DecimalRate::fromString('0.15'),
            RoundingMode::HalfUp,
            'employee tax',
        );

        self::assertSame(1_501, $result->money->minorUnits);
        self::assertSame('CZK', $result->money->currency);
        self::assertSame('employee tax', $result->step->label);
        self::assertSame(10_005, $result->step->inputMinorUnits);
        self::assertSame(150_075, $result->step->unroundedNumerator);
        self::assertSame(100, $result->step->unroundedDenominator);
        self::assertSame(RoundingMode::HalfUp, $result->step->roundingMode);
        self::assertSame(1_501, $result->step->outputMinorUnits);
    }

    public function testItCalculatesSevenPointOnePercentForNegativeCorrection(): void
    {
        $result = (new MoneyRateCalculator())->multiply(
            new Money(-10_005),
            DecimalRate::fromString('0.071'),
            RoundingMode::Floor,
            'insurance correction',
        );

        self::assertSame(-711, $result->money->minorUnits);
        self::assertSame(-710_355, $result->step->unroundedNumerator);
        self::assertSame(1_000, $result->step->unroundedDenominator);
    }

    public function testZeroRateProducesZeroWithoutOverflow(): void
    {
        $result = (new MoneyRateCalculator())->multiply(
            new Money(PHP_INT_MAX),
            DecimalRate::fromString('0'),
            RoundingMode::AwayFromZero,
            'zero',
        );

        self::assertSame(0, $result->money->minorUnits);
        self::assertSame(0, $result->step->unroundedNumerator);
    }

    public function testTraceCanonicalSerializationIsByteStable(): void
    {
        $result = (new MoneyRateCalculator())->multiply(
            new Money(-10_005),
            DecimalRate::fromString('0.0710'),
            RoundingMode::Floor,
            'insurance correction',
        );
        $expected = '{"label":"insurance correction","input_minor_units":-10005,"rate":{"decimal":"0.071","numerator":71,"scale":3,"denominator":1000},"unrounded_numerator":-710355,"unrounded_denominator":1000,"rounding_mode":"floor","output_minor_units":-711}';

        self::assertSame($expected, $result->step->toCanonicalJson());
        self::assertSame($expected, $result->step->toCanonicalJson());
    }

    public function testInvalidRoundingDenominatorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RoundingMode::HalfUp->roundFraction(1, 0);
    }

    public function testUnroundedIntermediateOverflowIsRejected(): void
    {
        $cases = [
            [PHP_INT_MAX, '2'],
            [PHP_INT_MIN, '2'],
            [PHP_INT_MIN, '-1'],
            [-1, (string) PHP_INT_MIN],
        ];

        foreach ($cases as [$minorUnits, $rate]) {
            try {
                (new MoneyRateCalculator())->multiply(
                    new Money($minorUnits),
                    DecimalRate::fromString($rate),
                    RoundingMode::TowardZero,
                    'overflow',
                );
                self::fail('Overflowing intermediate must be rejected.');
            } catch (OverflowException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testMinimumIntegerIntermediateIsPreservedExactly(): void
    {
        $result = (new MoneyRateCalculator())->multiply(
            new Money(1),
            DecimalRate::fromString((string) PHP_INT_MIN),
            RoundingMode::TowardZero,
            'minimum',
        );

        self::assertSame(PHP_INT_MIN, $result->step->unroundedNumerator);
        self::assertSame(PHP_INT_MIN, $result->money->minorUnits);
    }
}
