<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierVat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kódy DPH PREMIER → zařazení dokladu v MyÚčtu. Kód se páruje podle řádků přiznání,
 * které nese v číselníku, ne podle svého čísla (to si účetní jednotka volí sama).
 */
final class PremierVatTest extends TestCase
{
    /**
     * Číselník s dvěma sadami sloupců řádků: starý tiskopis (R15*) a aktuální (R17*).
     * Staré sloupce nesou záměrně jiné (neznámé) řádky - kdyby převod četl je, kódy by
     * nezařadil.
     */
    private static function vat(): PremierVat
    {
        $rows = [
            self::code('D1', 'Tuzemské plnění 21 %', [1], sale: true, class: 2),
            self::code('D2', 'Tuzemské plnění 12 %', [2], sale: true, class: 1),
            self::code('DM', 'Prodej majetku', [1, 51], sale: true, class: 2),
            self::code('X20', 'Dodání zboží do EU', [20], sale: true),
            self::code('X21', 'Služba do EU', [21], sale: true),
            self::code('X22', 'Vývoz', [22], sale: true),
            self::code('X25', 'Přenesení - dodavatel', [25], sale: true),
            self::code('X26', 'Plnění mimo tuzemsko', [26], sale: true),
            self::code('X50', 'Osvobozené', [50], sale: true),
            self::code('X5051', 'Osvobozené mimo koeficient', [50, 51], sale: true),
            self::code('X1_20', 'Nesmysl', [1, 20], sale: true),
            self::code('NIC', 'Mimo přiznání', [], sale: true),
            self::code('P40', 'Odpočet 21 %', [40], purchase: true, class: 2),
            self::code('P41', 'Odpočet 12 %', [41], purchase: true, class: 1),
            self::code('PK', 'Krácený odpočet', [40], purchase: true, class: 2, reduced: true),
            self::code('PM', 'Pořízení majetku', [40, 47], purchase: true, class: 2),
            self::code('E3', 'Zboží z EU', [3, 43], purchase: true, reverse: true),
            self::code('E4', 'Zboží z EU 12 %', [4, 44], purchase: true, reverse: true),
            self::code('E5', 'Služba z EU', [5, 43], purchase: true, reverse: true),
            self::code('E6', 'Služba z EU 12 %', [6, 44], purchase: true, reverse: true),
            self::code('E5N', 'Služba z EU bez nároku', [5], purchase: true, reverse: true),
            self::code('I7', 'Dovoz zboží', [7, 43], purchase: true, reverse: true),
            self::code('T10', 'Tuzemský přenos - příjemce', [10, 43], purchase: true, reverse: true),
            self::code('Z12', 'Služba ze 3. země', [12, 43], purchase: true, reverse: true),
            self::code('Z12M', 'Služba ze 3. země - majetek', [12, 43, 47], purchase: true, reverse: true),
            self::code('MIX', 'Samovyměření + tuzemsko', [5, 40, 43], purchase: true, reverse: true),
            self::code('TWO', 'Dvě samovyměření', [5, 12, 43], purchase: true, reverse: true),
            self::code('C43', 'Jen odpočet', [43], purchase: true),
            self::code('C42', 'Dovoz celním úřadem', [42], purchase: true),
            self::code('PN', 'Přijaté mimo přiznání', [], purchase: true),
            self::code('S43', 'Vypořádání bez příznaku', [43]),
            self::code('S1', 'Opravy bez příznaku', [1]),
            self::code('S0', 'Bez příznaku a řádků', []),
            self::code('H', 'Historická sazba', [1], sale: true, class: 3, other: 19.0),
            self::code('A5', 'Tuzemsko souhrnně', [1], sale: true, class: 2, kh: 'A.5.'),
            self::code('D1', 'Duplicitní kód - platí první', [50], sale: true),
        ];
        return PremierVat::fromRows($rows);
    }

    /**
     * @param list<int> $lines
     * @return array<string,mixed>
     */
    private static function code(string $code, string $text, array $lines, bool $purchase = false, bool $sale = false, bool $reverse = false,
        bool $reduced = false, int $class = 0, float $other = 0.0, string $kh = ''): array
    {
        return [
            'KOD_DPH' => $code, 'TEXT' => $text, 'SAZBA' => $class, 'JINA_SAZBA' => $other,
            'FA_IN' => $purchase, 'FA_OUT' => $sale, 'IS_REVERS' => $reverse, 'IS_KRACENY' => $reduced,
            'R15' => 99, 'R15B' => 98,
            'R17' => $lines[0] ?? 0, 'R17B' => $lines[1] ?? 0, 'R17C' => $lines[2] ?? 0,
            'TAB_FA' => $kh, 'TAB_FANE' => '',
        ];
    }

    /** @return iterable<string,array{0:string,1:?array<string,mixed>}> */
    public static function saleCases(): iterable
    {
        yield 'tuzemsko ř. 1' => ['D1', ['in_return' => true, 'code' => null, 'domestic' => true, 'asset_sale' => false]];
        yield 'tuzemsko ř. 2' => ['D2', ['in_return' => true, 'code' => null, 'domestic' => true, 'asset_sale' => false]];
        yield 'prodej majetku ř. 1 + 51' => ['DM', ['in_return' => true, 'code' => null, 'domestic' => true, 'asset_sale' => true]];
        yield 'zboží do EU' => ['X20', ['in_return' => true, 'code' => '20', 'domestic' => false, 'asset_sale' => false]];
        yield 'služba do EU' => ['X21', ['in_return' => true, 'code' => '22', 'domestic' => false, 'asset_sale' => false]];
        yield 'vývoz' => ['X22', ['in_return' => true, 'code' => '26', 'domestic' => false, 'asset_sale' => false]];
        yield 'tuzemský přenos dodavatel' => ['X25', ['in_return' => true, 'code' => '25s', 'domestic' => false, 'asset_sale' => false]];
        yield 'plnění mimo tuzemsko' => ['X26', ['in_return' => true, 'code' => '26s', 'domestic' => false, 'asset_sale' => false]];
        yield 'osvobozené' => ['X50', ['in_return' => true, 'code' => '3', 'domestic' => false, 'asset_sale' => false]];
        yield 'osvobozené mimo koeficient' => ['X5051', ['in_return' => true, 'code' => '3m', 'domestic' => false, 'asset_sale' => false]];
        yield 'mimo přiznání' => ['NIC', ['in_return' => false, 'code' => null, 'domestic' => false, 'asset_sale' => false]];
        yield 'kombinace řádků' => ['X1_20', null];
        yield 'neznámý kód' => ['???', null];
    }

    /** @param ?array<string,mixed> $expected */
    #[DataProvider('saleCases')]
    public function testSaleClassification(string $code, ?array $expected): void
    {
        self::assertSame($expected, self::vat()->sale($code));
    }

    /** @return iterable<string,array{0:string,1:?array<string,mixed>}> */
    public static function purchaseCases(): iterable
    {
        $domestic = ['in_return' => true, 'deduction' => 'full', 'reverse' => false, 'code' => null, 'fixed_asset' => false];
        $rc = static fn (string $code, string $deduction = 'full', bool $asset = false): array => ['in_return' => true, 'deduction' => $deduction, 'reverse' => true, 'code' => $code, 'fixed_asset' => $asset];
        yield 'tuzemsko ř. 40' => ['P40', $domestic];
        yield 'tuzemsko ř. 41' => ['P41', $domestic];
        yield 'krácený odpočet' => ['PK', array_merge($domestic, ['deduction' => 'reduced'])];
        yield 'pořízení majetku ř. 47' => ['PM', array_merge($domestic, ['fixed_asset' => true])];
        yield 'zboží z EU' => ['E3', $rc('23')];
        yield 'zboží z EU snížená' => ['E4', $rc('23')];
        yield 'služba z EU' => ['E5', $rc('24e')];
        yield 'služba z EU snížená' => ['E6', $rc('24e')];
        yield 'služba z EU bez nároku' => ['E5N', $rc('24e', 'none')];
        yield 'dovoz zboží' => ['I7', $rc('25')];
        yield 'tuzemský přenos příjemce' => ['T10', $rc('5')];
        yield 'služba ze 3. země' => ['Z12', $rc('24')];
        yield 'služba ze 3. země - majetek' => ['Z12M', $rc('24', 'full', true)];
        yield 'mimo přiznání' => ['PN', ['in_return' => false, 'deduction' => 'none', 'reverse' => false, 'code' => null, 'fixed_asset' => false]];
        yield 'samovyměření s tuzemským řádkem' => ['MIX', null];
        yield 'dvě samovyměření' => ['TWO', null];
        yield 'odpočet bez výstupu' => ['C43', null];
        yield 'neznámý řádek (dovoz celním úřadem)' => ['C42', null];
        yield 'neznámý kód' => ['???', null];
    }

    /** @param ?array<string,mixed> $expected */
    #[DataProvider('purchaseCases')]
    public function testPurchaseClassification(string $code, ?array $expected): void
    {
        self::assertSame($expected, self::vat()->purchase($code));
    }

    public function testHighestLineColumnSetWins(): void
    {
        $vat = self::vat();
        self::assertSame([5, 43], $vat->lines('E5'), 'R15/R15B (starý tiskopis) se ignorují.');
        self::assertSame([], $vat->lines('NIC'));

        // Jen starý tiskopis v záloze: platí R15*.
        $old = PremierVat::fromRows([['KOD_DPH' => 'A', 'R15' => 40, 'R15B' => 47, 'R14' => 99, 'FA_IN' => true, 'SAZBA' => 2]]);
        self::assertSame([40, 47], $old->lines('A'));
        self::assertTrue($old->purchase('A')['fixed_asset']);

        // Číselník bez sloupců řádků: kód je známý, ale mimo přiznání.
        $none = PremierVat::fromRows([['KOD_DPH' => 'A', 'FA_OUT' => true]]);
        self::assertSame([], $none->lines('A'));
        self::assertFalse($none->sale('A')['in_return']);
        self::assertSame([], PremierVat::fromRows([])->lines('A'));
    }

    public function testFirstDefinitionOfDuplicateCodeWins(): void
    {
        self::assertSame([1], self::vat()->lines('D1'));
        self::assertSame('Tuzemské plnění 21 %', self::vat()->name('D1'));
    }

    public function testRateClass(): void
    {
        $vat = self::vat();
        self::assertSame(PremierVat::RATE_BASE, $vat->rateClass('D1'));
        self::assertSame(PremierVat::RATE_REDUCED, $vat->rateClass('D2'));
        self::assertSame(PremierVat::RATE_REDUCED, $vat->rateClass('P41'));
        // Bez třídy v číselníku rozhodne řádek samovyměření / odpočtu.
        self::assertSame(PremierVat::RATE_BASE, $vat->rateClass('E5'));
        self::assertSame(PremierVat::RATE_REDUCED, $vat->rateClass('E6'));
        self::assertSame(PremierVat::RATE_BASE, $vat->rateClass('S1'));
        self::assertNull($vat->rateClass('X20'));
        // Třída 3 = jiná (historická) sazba: třídu určí řádek, sazbu nese JINA_SAZBA.
        self::assertSame(PremierVat::RATE_BASE, $vat->rateClass('H'));
        self::assertSame(19.0, $vat->otherRate('H'));
        self::assertSame(0.0, $vat->otherRate('D1'));
        self::assertNull($vat->rateClass('???'));
    }

    public function testDirectionAndMetadata(): void
    {
        $vat = self::vat();
        self::assertTrue($vat->isPurchase('P40'));
        self::assertFalse($vat->isPurchase('D1'));
        self::assertTrue($vat->isPurchase('S43'), 'Bez příznaku rozhodnou odpočtové řádky 40-47.');
        self::assertFalse($vat->isPurchase('S1'));
        self::assertNull($vat->isPurchase('S0'));
        self::assertNull($vat->isPurchase('???'));

        self::assertTrue($vat->known('P40'));
        self::assertFalse($vat->known('???'));
        self::assertSame('???', $vat->name('???'));
        self::assertTrue($vat->forcesSummaryKh('A5'), 'Oddíl KH se čte i s tečkou („A.5.").');
        self::assertFalse($vat->forcesSummaryKh('D1'));
    }

    /** @return iterable<string,array{0:string,1:string,2:float}> */
    public static function rateCases(): iterable
    {
        yield 'základní 2025' => [PremierVat::RATE_BASE, '2025-06-30', 21.0];
        yield 'základní od 2013' => [PremierVat::RATE_BASE, '2013-01-01', 21.0];
        yield 'základní 2012' => [PremierVat::RATE_BASE, '2012-12-31', 20.0];
        yield 'základní od 2010' => [PremierVat::RATE_BASE, '2010-01-01', 20.0];
        yield 'základní 2009' => [PremierVat::RATE_BASE, '2009-12-31', 19.0];
        yield 'snížená od 2024' => [PremierVat::RATE_REDUCED, '2024-01-01', 12.0];
        yield 'snížená 2023' => [PremierVat::RATE_REDUCED, '2023-12-31', 15.0];
        yield 'snížená od 2013' => [PremierVat::RATE_REDUCED, '2013-01-01', 15.0];
        yield 'snížená 2012' => [PremierVat::RATE_REDUCED, '2012-06-01', 14.0];
        yield 'snížená 2010' => [PremierVat::RATE_REDUCED, '2010-06-01', 10.0];
        yield 'snížená 2008' => [PremierVat::RATE_REDUCED, '2008-06-01', 9.0];
        yield 'snížená 2007' => [PremierVat::RATE_REDUCED, '2007-12-31', 5.0];
    }

    #[DataProvider('rateCases')]
    public function testRateForDate(string $class, string $date, float $expected): void
    {
        self::assertSame($expected, PremierVat::rateFor($class, $date));
    }
}
