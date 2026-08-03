<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Calculation;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

final class DecimalRateTest extends TestCase
{
    #[DataProvider('validRates')]
    public function testItParsesExactRates(
        string $input,
        int $numerator,
        int $scale,
        int $denominator,
        string $canonical,
    ): void {
        $rate = DecimalRate::fromString($input);

        self::assertSame($numerator, $rate->numerator);
        self::assertSame($scale, $rate->scale);
        self::assertSame($denominator, $rate->denominator);
        self::assertSame($canonical, $rate->toCanonicalString());
    }

    /** @return array<string, array{string, int, int, int, string}> */
    public static function validRates(): array
    {
        return [
            'zero' => ['0', 0, 0, 1, '0'],
            'negative zero' => ['-0.000', 0, 0, 1, '0'],
            'seven point one percent' => ['0.071', 71, 3, 1_000, '0.071'],
            'fifteen' => ['15', 15, 0, 1, '15'],
            'one point five' => ['1.5', 15, 1, 10, '1.5'],
            'trailing zeroes' => ['1.5000', 15, 1, 10, '1.5'],
            'negative' => ['-0.125', -125, 3, 1_000, '-0.125'],
            'minimum integer' => [(string) PHP_INT_MIN, PHP_INT_MIN, 0, 1, (string) PHP_INT_MIN],
        ];
    }

    #[DataProvider('invalidRates')]
    public function testItRejectsInvalidFormats(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecimalRate::fromString($input);
    }

    /** @return array<string, array{string}> */
    public static function invalidRates(): array
    {
        return [
            'empty' => [''],
            'whitespace' => [' 0.15'],
            'trailing whitespace' => ['0.15 '],
            'plus sign' => ['+0.15'],
            'leading point' => ['.15'],
            'trailing point' => ['15.'],
            'exponent' => ['1.5e-1'],
            'comma' => ['0,15'],
            'leading zero' => ['00.15'],
            'double sign' => ['--1'],
            'word' => ['fifteen'],
        ];
    }

    public function testCanonicalSerializationIsByteStable(): void
    {
        $rate = DecimalRate::fromString('0.0710');
        $expected = '{"decimal":"0.071","numerator":71,"scale":3,"denominator":1000}';

        self::assertSame($expected, $rate->toCanonicalJson());
        self::assertSame($expected, $rate->toCanonicalJson());
    }

    public function testOutOfRangeNumeratorAndScaleAreRejected(): void
    {
        foreach ([
            (string) PHP_INT_MAX . '0',
            (string) PHP_INT_MIN . '0',
            '0.0000000000000000001',
        ] as $rate) {
            try {
                DecimalRate::fromString($rate);
                self::fail("Rate {$rate} must not be silently truncated.");
            } catch (OverflowException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRateFactoryOnlyAcceptsStrings(): void
    {
        $type = (new ReflectionMethod(DecimalRate::class, 'fromString'))->getParameters()[0]->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('string', $type->getName());
    }
}
