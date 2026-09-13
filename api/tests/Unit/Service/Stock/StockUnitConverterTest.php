<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Stock;

use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockUnitConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Čistá aritmetika převodu balení (issue #17) — bez databáze.
 */
final class StockUnitConverterTest extends TestCase
{
    /** @return iterable<string, array{string, int, int, string}> */
    public static function ratios(): iterable
    {
        yield '10 kartonů po 8 ks' => ['10', 8, 1, '80.000'];
        yield 'desetinné množství' => ['2.5', 8, 1, '20.000'];
        yield 'záporný řádek zůstane záporný' => ['-2.5', 8, 1, '-20.000'];
        yield 'základní jednotka 1:1' => ['3.125', 1, 1, '3.125'];
        yield 'třetina dolů' => ['1', 1, 3, '0.333'];
        yield 'dvě třetiny nahoru (half-up)' => ['2', 1, 3, '0.667'];
        yield 'poloviční balení' => ['3', 1, 2, '1.500'];
        yield 'čárka jako oddělovač' => ['1,5', 4, 1, '6.000'];
    }

    #[DataProvider('ratios')]
    public function testApplyRatio(string $qty, int $num, int $den, string $expected): void
    {
        self::assertSame($expected, StockUnitConverter::applyRatio($qty, $num, $den));
    }

    public function testApplyRatioOnMoneyScale(): void
    {
        self::assertSame('800.00', StockUnitConverter::applyRatio('100.00', 8, 1, 2));
        self::assertSame('33.33', StockUnitConverter::applyRatio('100', 1, 3, 2));
    }

    public function testPerBaseSpreadsValueOverBaseUnits(): void
    {
        self::assertSame('100.000000', StockUnitConverter::perBase('800', 8, 1));
        self::assertSame('300.000000', StockUnitConverter::perBase('100', 1, 3));
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function factors(): iterable
    {
        yield 'celé číslo' => [8, 1, '8'];
        yield 'polovina' => [1, 2, '0.5'];
        yield 'třetina zaokrouhlená' => [1, 3, '0.333'];
        yield 'čtvrtiny' => [5, 4, '1.25'];
        yield 'velké balení' => [1000, 1, '1000'];
    }

    #[DataProvider('factors')]
    public function testFormatFactor(int $num, int $den, string $expected): void
    {
        self::assertSame($expected, StockUnitConverter::formatFactor($num, $den));
    }

    /** @return iterable<string, array{mixed, array{int,int}}> */
    public static function parsedFactors(): iterable
    {
        yield 'řetězec' => ['8', [8, 1]];
        yield 'int z JSON' => [12, [12, 1]];
        yield 'float z JSON' => [0.5, [1, 2]];
        yield 'desetinný řetězec' => ['1.25', [5, 4]];
        yield 'čárka' => ['2,5', [5, 2]];
        yield 'velký poměr se zkrátí' => ['1500.125', [12001, 8]];
    }

    /** @param array{int,int} $expected */
    #[DataProvider('parsedFactors')]
    public function testParseFactor(mixed $factor, array $expected): void
    {
        self::assertSame($expected, StockUnitConverter::parseFactor($factor));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidFactors(): iterable
    {
        yield 'nula' => ['0'];
        yield 'záporné' => ['-1'];
        yield 'text' => ['abc'];
        yield 'čtyři desetinná místa' => ['1.2345'];
        yield 'prázdné' => [''];
    }

    #[DataProvider('invalidFactors')]
    public function testParseFactorRejectsInvalid(mixed $factor): void
    {
        $this->expectException(StockException::class);
        StockUnitConverter::parseFactor($factor);
    }

    public function testSqlFragmentsUseAliasAndBaseUnitExclusion(): void
    {
        $join = StockUnitConverter::sqlUnitJoin('ii_pk', 'i.supplier_id', 'ii.stock_item_id', 'ii.unit');
        self::assertStringContainsString('ii_pk.unit_code = ii.unit', $join);
        self::assertStringContainsString('ii_pk.unit_code <> ii_pk_si.unit', $join, 'Kód shodný se základní jednotkou se nesmí párovat.');
        self::assertStringContainsString('ii_pk.is_sales_unit = 1', $join, 'Převodní jednotky šarží zůstávají pro doklady 1:1.');
        self::assertStringContainsString('ii_pk_sup.stock_enabled = 1', $join, 'Bez zapnutého skladu se nic nepřepočítává.');
        self::assertStringContainsString('ii_pk_sup.id = i.supplier_id', $join);
        // Řádek bez balení vrací původní výraz beze změny (hodnota i měřítko jako před balením).
        self::assertSame(
            '(CASE WHEN ii_pk.id IS NULL THEN ii.quantity ELSE ROUND(ii.quantity * ii_pk.numerator / ii_pk.denominator, 3) END)',
            StockUnitConverter::sqlToBase('ii.quantity', 'ii_pk'),
        );
    }
}
