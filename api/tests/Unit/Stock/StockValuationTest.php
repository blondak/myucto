<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Stock;

use MyInvoice\Service\Stock\StockValuation as V;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vážený klouzavý průměr — haléřová aritmetika, zdroj pravdy value_total,
 * výdej celého zůstatku bez sedimentu (spec §3.1, §8.1).
 */
#[Group('unit')]
final class StockValuationTest extends TestCase
{
    public function testReceiptAccumulatesValueAndQty(): void
    {
        // Příjem 10 ks à 100 Kč
        $r = V::receipt(0, 0, V::qtyToT(10), V::valueToC(10 * 100));
        self::assertSame(10000, $r['qtyT']);
        self::assertSame(100000, $r['valueC']);
        self::assertSame(100_000000, $r['avgMicro']); // 100.000000

        // Příjem 5 ks à 130 Kč → avg 110.000000
        $r2 = V::receipt($r['qtyT'], $r['valueC'], V::qtyToT(5), V::valueToC(5 * 130));
        self::assertSame(15000, $r2['qtyT']);
        self::assertSame(165000, $r2['valueC']);
        self::assertSame(110_000000, $r2['avgMicro']);
    }

    public function testIssueUsesMovingAverage(): void
    {
        $r = V::receipt(0, 0, V::qtyToT(15), 165000); // 15 ks, 1650.00, avg 110
        $i = V::issue($r['qtyT'], $r['valueC'], V::qtyToT(4));
        self::assertSame(44000, $i['lineValueC']); // round(165000*4/15)=44000 → 440.00
        self::assertSame(11000, $i['qtyT']);
        self::assertSame(121000, $i['valueC']);
    }

    public function testWholeBalanceIssueLeavesNoSediment(): void
    {
        // Nezaokrouhlitelná průměrka: 3 ks za 1000 haléřů (avg 3.3333…)
        $r = V::receipt(0, 0, V::qtyToT(3), 1000);
        $i1 = V::issue($r['qtyT'], $r['valueC'], V::qtyToT(1));
        self::assertSame(333, $i1['lineValueC']); // round(1000/3)=333
        self::assertSame(667, $i1['valueC']);

        // Výdej zbytku (2 ks) = přesně zbylá hodnota, stav 0/0
        $i2 = V::issue($i1['qtyT'], $i1['valueC'], V::qtyToT(2));
        self::assertSame(667, $i2['lineValueC']);
        self::assertSame(0, $i2['qtyT']);
        self::assertSame(0, $i2['valueC']);
        self::assertSame(0, $i2['avgMicro']);
    }

    public function testThousandMovementsNoDrift(): void
    {
        // 1000 párů příjem/výdej — value_total zdroj pravdy nesmí driftovat do minusu
        // ani nabalit sediment. Po každém úplném výdeji je stav přesně 0/0.
        $qtyT = 0;
        $valueC = 0;
        for ($n = 1; $n <= 1000; $n++) {
            $unit = 100 + ($n % 7); // kolísavá cena
            $rec = V::receipt($qtyT, $valueC, V::qtyToT(7), V::valueToC(7 * $unit));
            $qtyT = $rec['qtyT'];
            $valueC = $rec['valueC'];
            // částečný výdej 3 ks
            $iss = V::issue($qtyT, $valueC, V::qtyToT(3));
            $qtyT = $iss['qtyT'];
            $valueC = $iss['valueC'];
            self::assertGreaterThanOrEqual(0, $valueC);
            self::assertGreaterThanOrEqual(0, $qtyT);
        }
        // Vyskladnit vše — musí dát přesně 0/0
        if ($qtyT > 0) {
            $fin = V::issue($qtyT, $valueC, $qtyT);
            self::assertSame(0, $fin['qtyT']);
            self::assertSame(0, $fin['valueC']);
        }
    }

    public function testDecimalConversionsAreExact(): void
    {
        self::assertSame('1210.00', V::cToDecimal(121000));
        self::assertSame('15.000', V::tToDecimal(15000));
        self::assertSame('110.000000', V::microToDecimal(110_000000));
        self::assertSame('0.00', V::cToDecimal(0));
        self::assertSame(12500, V::qtyToT('12.500'));
        self::assertSame(9999, V::valueToC('99.99'));
    }

    public function testIssueRejectsOverdraw(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        V::issue(V::qtyToT(2), 20000, V::qtyToT(3));
    }
}
