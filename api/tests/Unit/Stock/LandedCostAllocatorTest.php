<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Stock;

use MyInvoice\Service\Stock\LandedCostAllocator as A;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class LandedCostAllocatorTest extends TestCase
{
    public function testByValueSplitsProportionally(): void
    {
        // Řádky 1000 a 3000 haléřů; doprava 400 → 100 / 300
        $out = A::allocate(
            [['value' => 1000, 'qty' => 1000], ['value' => 3000, 'qty' => 1000]],
            [['amount' => 400, 'allocation' => 'by_value']],
        );
        self::assertSame([100, 300], $out);
        self::assertSame(400, array_sum($out));
    }

    public function testByQtySplitsProportionally(): void
    {
        // Množství 2 a 8 ks; clo 500 → 100 / 400
        $out = A::allocate(
            [['value' => 9999, 'qty' => 2000], ['value' => 1, 'qty' => 8000]],
            [['amount' => 500, 'allocation' => 'by_qty']],
        );
        self::assertSame([100, 400], $out);
        self::assertSame(500, array_sum($out));
    }

    public function testHalerRemainderGoesToHighestValueLine(): void
    {
        // 3 stejné řádky à 1000, náklad 100 → 33.33 každý; zbytek 1 na nejvyšší
        // hodnotu (shoda → index 0). 34 + 33 + 33 = 100.
        $out = A::allocate(
            [['value' => 1000, 'qty' => 1000], ['value' => 1000, 'qty' => 1000], ['value' => 1000, 'qty' => 1000]],
            [['amount' => 100, 'allocation' => 'by_value']],
        );
        self::assertSame(100, array_sum($out));
        self::assertSame([34, 33, 33], $out);
    }

    public function testRemainderTargetsMaxValueNotFirst(): void
    {
        // Řádek s nejvyšší hodnotou je index 1 → dostane zbytek.
        $out = A::allocate(
            [['value' => 1000, 'qty' => 1], ['value' => 5000, 'qty' => 1], ['value' => 1000, 'qty' => 1]],
            [['amount' => 100, 'allocation' => 'by_qty']], // stejné qty → 33/33/33 + zbytek 1
        );
        self::assertSame(100, array_sum($out));
        self::assertSame(1, $out[1] - 33); // extra haléř na nejvyšší hodnotu
    }

    public function testMultipleCostsAccumulate(): void
    {
        $out = A::allocate(
            [['value' => 1000, 'qty' => 1000], ['value' => 1000, 'qty' => 1000]],
            [
                ['amount' => 200, 'allocation' => 'by_value'],
                ['amount' => 100, 'allocation' => 'by_qty'],
            ],
        );
        self::assertSame(300, array_sum($out));
        self::assertSame([150, 150], $out);
    }

    public function testZeroBasisFallsBackToEqualSplit(): void
    {
        $out = A::allocate(
            [['value' => 0, 'qty' => 0], ['value' => 0, 'qty' => 0]],
            [['amount' => 101, 'allocation' => 'by_value']],
        );
        self::assertSame(101, array_sum($out));
        self::assertSame([51, 50], $out);
    }
}
