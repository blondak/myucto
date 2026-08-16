<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\EpoSupplierBlockBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Adresní parsing pro EPO a ČSSZ — korpus ošklivých českých adres.
 *
 * Do fáze F1 byla tahle logika INLINE uvnitř `fillVetaP()`, tedy nevolatelná, a žila
 * proto ve ČTYŘECH kopiích (DPFO, DPPO, ČSSZ a tady). To není nedbalost volajících,
 * je to strukturální příčina: SSOT, který nejde zavolat, se okopíruje rychleji, než
 * kdyby žádný nebyl — vytváří totiž dojem, že pravidlo je vyřešené. Opakuje se to
 * přesně jako u chyby #200: oprava rozdělení jména dopadla jen na část podání.
 *
 * Sjednocení bylo ZMĚŘENO, ne odvozeno: před refaktorem se všechny čtyři kopie
 * na tomhle korpusu shodovaly (19 z 19), takže sjednocení nic nemění. Test tu shodu
 * fixuje, aby ji budoucí úprava nerozbila potichu.
 */
final class EpoStreetParsingTest extends TestCase
{
    /**
     * Reálné tvary české adresy, ne vymyšlené vzorky. Nejzrádnější je „17. listopadu 220"
     * — číslo je součástí NÁZVU ulice a nesmí se splést s číslem popisným.
     *
     * @return iterable<string, array{array<string,mixed>, string, string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'č.p. i č.o.'          => [['street' => 'Zkušební 123/4'], 'Zkušební', '123', '4'];
        yield 'jen č.p.'             => [['street' => 'Hlavní 12'], 'Hlavní', '12', ''];
        yield 'alfanumerické č.p.'   => [['street' => 'Hlavní 12a'], 'Hlavní', '12a', ''];
        yield 'alfa v č.o.'          => [['street' => 'K Lesu 1234/5b'], 'K Lesu', '1234', '5b'];
        yield 'mezery kolem lomítka' => [['street' => 'Náměstí Míru 5 / 7'], 'Náměstí Míru', '5', '7'];
        yield 'číslo v názvu ulice'  => [['street' => '17. listopadu 220'], '17. listopadu', '220', ''];
        yield 'datum v názvu ulice'  => [['street' => '5. května 1640/65'], '5. května', '1640', '65'];
        yield 'zkratka s tečkou'     => [['street' => 'třída Kpt. Jaroše 1922/3'], 'třída Kpt. Jaroše', '1922', '3'];
        yield 'víceslovná ulice'     => [['street' => 'Nábřeží Kapitána Jaroše 1000'], 'Nábřeží Kapitána Jaroše', '1000', ''];
        yield 'bez čísla'            => [['street' => 'Na Poříčí'], 'Na Poříčí', '', ''];
        yield 'prázdná adresa'       => [['street' => ''], '', '', ''];

        // Vyplněná samostatná čísla mají PŘEDNOST a z ulice se odřízne trailing číslo,
        // aby se nezdvojovalo („Hlavní 12" + pop=12 nesmí dát ulici „Hlavní 12").
        yield 'ruční čísla, ulice s číslem' => [
            ['street' => 'Zkušební 123/4', 'street_number_pop' => '123', 'street_number_orient' => '4'],
            'Zkušební', '123', '4',
        ];
        yield 'ruční č.p., ulice bez čísla' => [
            ['street' => 'Hlavní', 'street_number_pop' => '12'],
            'Hlavní', '12', '',
        ];
        yield 'ruční č.p. duplicitně v ulici' => [
            ['street' => 'Hlavní 12', 'street_number_pop' => '12'],
            'Hlavní', '12', '',
        ];
        yield 'jen č.o. bez č.p.' => [
            ['street' => 'Nová 7', 'street_number_orient' => '9'],
            'Nová', '', '9',
        ];
    }

    /**
     * @param array<string,mixed> $supplier
     */
    #[DataProvider('addresses')]
    public function testParseStreetSplitsCzechAddressForms(
        array $supplier,
        string $ulice,
        string $cpop,
        string $corient,
    ): void {
        self::assertSame([$ulice, $cpop, $corient], EpoSupplierBlockBuilder::parseStreet($supplier));
    }

    /**
     * ČSSZ chce číslo popisné a orientační v JEDNOM poli. Prázdné číslo popisné nesmí
     * vyrobit vedoucí lomítko — původní kopie v `CsszPrehledXmlBuilder` psala u dodavatele
     * s vyplněným jen číslem orientačním do podání tvar „/9". Stav, který ARES i ruční
     * zadání umí vyrobit.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function houseNumbers(): iterable
    {
        yield 'obě čísla'   => ['123', '4', '123/4'];
        yield 'jen č.p.'    => ['12', '', '12'];
        yield 'jen č.o.'    => ['', '9', '9'];
        yield 'žádné číslo' => ['', '', ''];
    }

    #[DataProvider('houseNumbers')]
    public function testHouseNumberNeverEmitsLeadingSlash(string $cpop, string $corient, string $expected): void
    {
        self::assertSame($expected, EpoSupplierBlockBuilder::houseNumber($cpop, $corient));
    }

    /**
     * Chybějící SLOUPEC je chyba volajícího a musí spadnout hlasitě.
     *
     * Do fáze F1 helper degradoval TIŠE: atribut se prostě neemitoval a podání odešlo
     * neúplné. Registr SSOT to označil za druhé nejrizikovější místo fáze s poučením
     * „kontrakt musí být vynutitelný, ne slovní" — tenhle test je ta vynutitelnost.
     */
    public function testFillVetaPRejectsIncompleteSupplierRow(): void
    {
        $incomplete = [
            'company_name' => 'Testovací s.r.o.',
            'dic' => 'CZ12345678',
            'taxpayer_type' => 'po',
            // schází mimo jiné street_number_pop / opr_* / sest_*
        ];

        $dom = new \DOMDocument();
        $vetaP = $dom->createElement('VetaP');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/chybí sloupce/');
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $incomplete);
    }

    /**
     * Prázdná HODNOTA naopak legitimní je — dodavatel nemusí mít vyplněný telefon.
     * Bez tohoto rozlišení by kontrakt jen přesunul tichou degradaci do hlasitého,
     * ale falešného poplachu.
     */
    public function testFillVetaPAcceptsCompleteRowWithEmptyValues(): void
    {
        // Pořadí operandů `+` je podstatné: levý vyhrává, takže konkrétní hodnoty musí
        // stát vlevo a prázdná výplň vpravo.
        $supplier = [
            'company_name' => 'Testovací s.r.o.',
            'taxpayer_type' => 'po',
        ] + array_fill_keys(EpoSupplierBlockBuilder::REQUIRED_SUPPLIER_KEYS, '');

        $dom = new \DOMDocument();
        $vetaP = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $supplier);

        self::assertSame('Testovací s.r.o.', $vetaP->getAttribute('zkrobchjm'));
        self::assertFalse($vetaP->hasAttribute('c_telef'), 'Prázdný telefon se neemituje — a nesmí spadnout.');
    }

    /** Seznam požadovaných sloupců musí pokrývat vše, co SELECT fragment nabízí číst. */
    public function testRequiredKeysAreCoveredBySupplierSelect(): void
    {
        $select = EpoSupplierBlockBuilder::supplierSelect();
        $missing = [];

        foreach (EpoSupplierBlockBuilder::REQUIRED_SUPPLIER_KEYS as $key) {
            if (!str_contains($select, $key)) {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing, sprintf(
            'SELECT fragment nenačítá sloupce, které fillVetaP() vyžaduje: %s. '
                . 'Loader by pak vracel řádek, který helper sám odmítne.',
            implode(', ', $missing),
        ));
    }
}
