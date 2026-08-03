<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Calculation;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\Money;
use OverflowException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

final class MoneyTest extends TestCase
{
    public function testItUsesCzkByDefaultAndHasStableCanonicalSerialization(): void
    {
        $money = new Money(12_345);

        self::assertSame(12_345, $money->minorUnits);
        self::assertSame('CZK', $money->currency);
        self::assertSame('{"currency":"CZK","minor_units":12345}', $money->toCanonicalJson());
        self::assertSame($money->toCanonicalJson(), $money->toCanonicalJson());
    }

    public function testArithmeticReturnsNewValuesAndPreservesOriginals(): void
    {
        $left = new Money(1_250);
        $right = new Money(-375);

        self::assertSame(875, $left->add($right)->minorUnits);
        self::assertSame(1_625, $left->subtract($right)->minorUnits);
        self::assertSame(-1_250, $left->negate()->minorUnits);
        self::assertSame(1_250, $left->minorUnits);
        self::assertSame(-375, $right->minorUnits);
    }

    public function testComparisonHandlesPositiveNegativeAndEqualAmounts(): void
    {
        self::assertSame(1, (new Money(1))->compareTo(new Money(-1)));
        self::assertSame(-1, (new Money(-1))->compareTo(new Money(1)));
        self::assertSame(0, (new Money(0))->compareTo(new Money(0)));
    }

    public function testOperationsRejectDifferentCurrencies(): void
    {
        $czk = new Money(100, 'CZK');
        $eur = new Money(100, 'EUR');

        foreach (['add', 'subtract', 'compareTo'] as $method) {
            try {
                $czk->{$method}($eur);
                self::fail("{$method} must reject a different currency.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCurrencyMustAlreadyBeCanonicalIsoCode(): void
    {
        foreach (['czk', 'CZ', 'CZK1', ' EUR', ''] as $currency) {
            try {
                new Money(0, $currency);
                self::fail("Currency {$currency} should be rejected.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testArithmeticOverflowIsRejected(): void
    {
        $operations = [
            static fn (): Money => (new Money(PHP_INT_MAX))->add(new Money(1)),
            static fn (): Money => (new Money(PHP_INT_MIN))->add(new Money(-1)),
            static fn (): Money => (new Money(PHP_INT_MAX))->subtract(new Money(-1)),
            static fn (): Money => (new Money(PHP_INT_MIN))->subtract(new Money(1)),
            static fn (): Money => (new Money(PHP_INT_MIN))->negate(),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Overflowing money arithmetic must be rejected.');
            } catch (OverflowException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testMinorUnitsOnlyAcceptIntegers(): void
    {
        $type = (new ReflectionMethod(Money::class, '__construct'))->getParameters()[0]->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('int', $type->getName());
    }
}
