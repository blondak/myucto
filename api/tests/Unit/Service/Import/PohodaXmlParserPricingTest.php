<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Ceny s DPH (`inv:payVAT`), kurz na více jednotek měny (`typ:amount`) a rozlišení
 * dosazeného variabilního symbolu od skutečného.
 *
 * Všechny tři vady mají společné, že se navenek NEPROJEVÍ chybou — doklad se
 * naimportuje, jen s jiným základem daně, jiným kurzem nebo pod cizím symbolem.
 */
final class PohodaXmlParserPricingTest extends TestCase
{
    private const DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';
    private const INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    private const TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    private function build(
        string $itemXml,
        string $summaryXml = '',
        string $headerExtra = '<inv:symVar>2026001</inv:symVar>',
    ): string {
        $dat = self::DAT;
        $inv = self::INV;
        $typ = self::TYP;

        return <<<XML
        <dat:dataPack xmlns:dat="$dat" xmlns:inv="$inv" xmlns:typ="$typ" ico="12345678">
          <dat:dataPackItem>
            <inv:invoice version="2.0">
              <inv:invoiceHeader>
                <inv:invoiceType>issuedInvoice</inv:invoiceType>
                $headerExtra
                <inv:date>2026-06-15</inv:date>
              </inv:invoiceHeader>
              <inv:invoiceDetail>
                <inv:invoiceItem>
                  <inv:text>Sluzba</inv:text>
                  $itemXml
                </inv:invoiceItem>
              </inv:invoiceDetail>
              $summaryXml
            </inv:invoice>
          </dat:dataPackItem>
        </dat:dataPack>
        XML;
    }

    /**
     * @return array<string,mixed>
     */
    private function parseFirst(string $xml): array
    {
        $res = (new PohodaXmlParser())->parse($xml);
        self::assertNotEmpty($res['invoices']);
        self::assertArrayNotHasKey('__error', $res['invoices'][0]);

        return $res['invoices'][0];
    }

    /**
     * @return array<string,mixed>
     */
    private function firstItem(string $itemXml, string $summaryXml = ''): array
    {
        return $this->parseFirst($this->build($itemXml, $summaryXml))['items'][0];
    }

    // --- payVAT: ceny uvedené včetně DPH -----------------------------------

    /**
     * Jádro opravy. Doklad v cenách s DPH: 121 Kč brutto při 21 % je 100 Kč základu.
     * Dokud se `payVAT` nečetl, šlo do evidence 121 Kč základu a 25,41 Kč daně —
     * nadhodnocení o celou sazbu, a to tiše, protože doklad projde bez chyby.
     */
    public function testGrossUnitPriceIsConvertedToNet(): void
    {
        $item = $this->firstItem(
            '<inv:quantity>1</inv:quantity>'
            . '<inv:payVAT>true</inv:payVAT>'
            . '<inv:rateVAT>high</inv:rateVAT>'
            . '<inv:homeCurrency><typ:unitPrice>121</typ:unitPrice></inv:homeCurrency>'
        );

        self::assertEqualsWithDelta(100.0, $item['unit_price_without_vat'], 0.005);
        self::assertTrue($item['prices_included_vat']);
    }

    /** Zahraniční sazba v cizí měně — u OSS dokladu je to nejčastější tvar. */
    public function testGrossUnitPriceInForeignCurrencyIsConverted(): void
    {
        $item = $this->firstItem(
            '<inv:quantity>1</inv:quantity>'
            . '<inv:payVAT>true</inv:payVAT>'
            . '<inv:rateVAT>historyHigh</inv:rateVAT><inv:percentVAT>23</inv:percentVAT>'
            . '<inv:foreignCurrency><typ:unitPrice>1230</typ:unitPrice></inv:foreignCurrency>',
            '<inv:invoiceSummary><inv:foreignCurrency>'
            . '<typ:currency><typ:ids>PLN</typ:ids></typ:currency><typ:rate>5.80</typ:rate>'
            . '</inv:foreignCurrency></inv:invoiceSummary>'
        );

        self::assertSame(23.0, $item['vat_rate']);
        self::assertEqualsWithDelta(1000.0, $item['unit_price_without_vat'], 0.005);
    }

    /**
     * `typ:price` je dle XSD netto v obou režimech a nese i řádkovou slevu, kterou
     * jednotková cena nezná (100 ks × 121 brutto se slevou 10 % = 9 000 základu,
     * ne 10 000). Dělení samotné jednotkové ceny by slevu zahodilo.
     */
    public function testNetLineTotalWinsOverDividingTheUnitPrice(): void
    {
        $item = $this->firstItem(
            '<inv:quantity>100</inv:quantity>'
            . '<inv:payVAT>true</inv:payVAT>'
            . '<inv:rateVAT>high</inv:rateVAT>'
            . '<inv:discountPercentage>10</inv:discountPercentage>'
            . '<inv:homeCurrency>'
            . '<typ:unitPrice>121</typ:unitPrice>'
            . '<typ:price>9000.00</typ:price>'
            . '<typ:priceVAT>1890.00</typ:priceVAT>'
            . '</inv:homeCurrency>'
        );

        self::assertEqualsWithDelta(90.0, $item['unit_price_without_vat'], 0.005);
    }

    /** Producent, který do `price` zapsal totéž brutto, nesmí přepočet zrušit. */
    public function testGrossLineTotalIsIgnoredAndRateIsUsedInstead(): void
    {
        $item = $this->firstItem(
            '<inv:quantity>2</inv:quantity>'
            . '<inv:payVAT>true</inv:payVAT>'
            . '<inv:rateVAT>high</inv:rateVAT>'
            . '<inv:homeCurrency>'
            . '<typ:unitPrice>121</typ:unitPrice>'
            . '<typ:price>242.00</typ:price>'
            . '</inv:homeCurrency>'
        );

        self::assertEqualsWithDelta(100.0, $item['unit_price_without_vat'], 0.005);
    }

    /** Nulový `price` je placeholder, ne řádek zdarma — dělením by částka zmizela. */
    public function testZeroLineTotalDoesNotWipeThePrice(): void
    {
        $item = $this->firstItem(
            '<inv:quantity>1</inv:quantity>'
            . '<inv:payVAT>true</inv:payVAT>'
            . '<inv:rateVAT>high</inv:rateVAT>'
            . '<inv:homeCurrency><typ:unitPrice>121</typ:unitPrice><typ:price>0.00</typ:price></inv:homeCurrency>'
        );

        self::assertEqualsWithDelta(100.0, $item['unit_price_without_vat'], 0.005);
    }

    /** Osvobozené plnění: není co odečítat, brutto = netto. */
    public function testGrossPricingAtZeroRateKeepsTheAmount(): void
    {
        $item = $this->firstItem(
            '<inv:quantity>1</inv:quantity>'
            . '<inv:payVAT>true</inv:payVAT>'
            . '<inv:rateVAT>none</inv:rateVAT>'
            . '<inv:homeCurrency><typ:unitPrice>121</typ:unitPrice></inv:homeCurrency>'
        );

        self::assertSame(121.0, $item['unit_price_without_vat']);
    }

    /**
     * Regrese: chybějící i explicitně vypnutý `payVAT` znamená ceny bez DPH
     * (XSD default) — cena se nesmí sáhnout. Uhodnout tady `true` by rozbilo
     * doklady, které jsou dnes v pořádku, včetně round-tripu vlastního exportu.
     */
    public function testPricesWithoutVatAreLeftUntouched(): void
    {
        foreach (['', '<inv:payVAT>false</inv:payVAT>'] as $payVat) {
            $item = $this->firstItem(
                '<inv:quantity>1</inv:quantity>'
                . $payVat
                . '<inv:rateVAT>high</inv:rateVAT>'
                . '<inv:homeCurrency><typ:unitPrice>100</typ:unitPrice></inv:homeCurrency>'
            );

            self::assertSame(100.0, $item['unit_price_without_vat'], $payVat);
            self::assertFalse($item['prices_included_vat'], $payVat);
        }
    }

    /** `typ:boolean` je textový enum, ale producenti posílají i 1/0 a velká písmena. */
    public function testBooleanVariantsOfPayVat(): void
    {
        foreach (['true', 'TRUE', ' true ', '1'] as $raw) {
            $item = $this->firstItem(
                '<inv:quantity>1</inv:quantity>'
                . "<inv:payVAT>$raw</inv:payVAT>"
                . '<inv:rateVAT>high</inv:rateVAT>'
                . '<inv:homeCurrency><typ:unitPrice>121</typ:unitPrice></inv:homeCurrency>'
            );
            self::assertEqualsWithDelta(100.0, $item['unit_price_without_vat'], 0.005, $raw);
        }

        foreach (['false', 'FALSE', '0', 'ano'] as $raw) {
            $item = $this->firstItem(
                '<inv:quantity>1</inv:quantity>'
                . "<inv:payVAT>$raw</inv:payVAT>"
                . '<inv:rateVAT>high</inv:rateVAT>'
                . '<inv:homeCurrency><typ:unitPrice>121</typ:unitPrice></inv:homeCurrency>'
            );
            self::assertSame(121.0, $item['unit_price_without_vat'], $raw);
        }
    }

    // --- Kurz na více jednotek měny ----------------------------------------

    private function foreignSummary(string $rateXml): string
    {
        return '<inv:invoiceSummary><inv:foreignCurrency>'
            . '<typ:currency><typ:ids>HUF</typ:ids></typ:currency>'
            . $rateXml
            . '</inv:foreignCurrency></inv:invoiceSummary>';
    }

    private function itemXml(): string
    {
        return '<inv:quantity>1</inv:quantity><inv:rateVAT>none</inv:rateVAT>'
            . '<inv:foreignCurrency><typ:unitPrice>1000</typ:unitPrice></inv:foreignCurrency>';
    }

    /**
     * HUF se kotuje na 100 jednotek: `rate=63.50` + `amount=100` je 0,635 Kč za
     * forint. Bez dělení šel do evidence stonásobný kurz — a s ním stonásobný
     * základ daně v korunách.
     */
    public function testRateIsDividedByAmount(): void
    {
        $inv = $this->parseFirst($this->build(
            $this->itemXml(),
            $this->foreignSummary('<typ:rate>63.50</typ:rate><typ:amount>100</typ:amount>')
        ));

        self::assertSame('HUF', $inv['currency']);
        self::assertEqualsWithDelta(0.635, $inv['exchange_rate'], 1e-9);
        self::assertSame(100, $inv['exchange_rate_amount']);
    }

    /**
     * Chybějící `amount` = 1. XSD mu default nedává, takže jiný výklad nemá ani
     * Pohoda, a náš vlastní exportér zapisuje 1 vždy.
     */
    public function testMissingAmountMeansOne(): void
    {
        $inv = $this->parseFirst($this->build(
            $this->itemXml(),
            $this->foreignSummary('<typ:rate>24.36</typ:rate>')
        ));

        self::assertEqualsWithDelta(24.36, $inv['exchange_rate'], 1e-9);
        self::assertNull($inv['exchange_rate_amount']);
    }

    /** Nula ani nesmysl v `amount` nesmí shodit dělení. */
    public function testUnusableAmountFallsBackToOne(): void
    {
        foreach (['<typ:amount>0</typ:amount>', '<typ:amount>-100</typ:amount>', '<typ:amount>x</typ:amount>'] as $amount) {
            $inv = $this->parseFirst($this->build(
                $this->itemXml(),
                $this->foreignSummary('<typ:rate>24.36</typ:rate>' . $amount)
            ));

            self::assertEqualsWithDelta(24.36, $inv['exchange_rate'], 1e-9, $amount);
        }
    }

    /** Nepoužitelný kurz je `null`, ne 0.0 — nula by se chovala jako platný kurz. */
    public function testUnusableRateIsNull(): void
    {
        foreach (['', '<typ:rate>0</typ:rate>', '<typ:rate>-5</typ:rate>', '<typ:rate>abc</typ:rate>'] as $rate) {
            $inv = $this->parseFirst($this->build($this->itemXml(), $this->foreignSummary($rate)));

            self::assertSame('HUF', $inv['currency'], $rate);
            self::assertNull($inv['exchange_rate'], $rate);
        }
    }

    /** Blok bez kódu měny není cizoměnový doklad. */
    public function testForeignBlockWithoutCurrencyCodeStaysCzk(): void
    {
        $inv = $this->parseFirst($this->build(
            '<inv:quantity>1</inv:quantity><inv:rateVAT>none</inv:rateVAT>'
            . '<inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>',
            '<inv:invoiceSummary><inv:foreignCurrency><typ:rate>24.36</typ:rate></inv:foreignCurrency></inv:invoiceSummary>'
        ));

        self::assertSame('CZK', $inv['currency']);
        self::assertNull($inv['exchange_rate']);
    }

    // --- Dosazený variabilní symbol vs. skutečný ---------------------------

    private function header(string $extra): string
    {
        return $this->build(
            '<inv:quantity>1</inv:quantity><inv:rateVAT>high</inv:rateVAT>'
            . '<inv:homeCurrency><typ:unitPrice>100</typ:unitPrice></inv:homeCurrency>',
            '',
            $extra,
        );
    }

    /** Skutečný symVar: shoda v DB je duplicita, ne kolize. */
    public function testRealSymVarIsNotFlaggedAsSubstituted(): void
    {
        $inv = $this->parseFirst($this->header(
            '<inv:symVar>2026001</inv:symVar>'
            . '<inv:number><typ:numberRequested>26FV0042</typ:numberRequested></inv:number>'
        ));

        self::assertSame('2026001', $inv['varsymbol']);
        self::assertFalse($inv['varsymbol_substituted']);
        self::assertSame('26FV0042', $inv['document_number']);
    }

    /**
     * GUID nahrazený číslem dokladu: shoda v DB může být CIZÍ doklad, jehož skutečný
     * VS se s naším dosazeným trefil. Import to musí odlišit od duplicity a má na to
     * mít původní hodnotu i číslo dokladu.
     */
    public function testSubstitutedVarsymbolCarriesCollisionEvidence(): void
    {
        $guid = '3f2a91c4-77b5-4d0e-9a12-5c8b6e0d4a31';
        $inv = $this->parseFirst($this->header(
            "<inv:symVar>$guid</inv:symVar>"
            . '<inv:number><typ:numberRequested>26FV0042</typ:numberRequested></inv:number>'
        ));

        self::assertSame('26FV0042', $inv['varsymbol']);
        self::assertSame('number', $inv['varsymbol_source']);
        self::assertTrue($inv['varsymbol_substituted']);
        self::assertSame($guid, $inv['varsymbol_original']);
        self::assertSame('26FV0042', $inv['document_number']);
    }

    /**
     * Prázdný symVar: nahrazovat nebylo co (`varsymbol_original` zůstává null), ale
     * DOSAZOVALI jsme — riziko kolize je stejné, takže příznak musí být zapnutý.
     */
    public function testEmptySymVarIsStillASubstitution(): void
    {
        $inv = $this->parseFirst($this->header(
            '<inv:number><typ:numberRequested>26FV0043</typ:numberRequested></inv:number>'
        ));

        self::assertSame('26FV0043', $inv['varsymbol']);
        self::assertTrue($inv['varsymbol_substituted']);
        self::assertNull($inv['varsymbol_original']);
    }

    /** Sanitizovaný tvar čísla dokladu je dosazený taky. */
    public function testSanitizedNumberIsASubstitution(): void
    {
        $inv = $this->parseFirst($this->header(
            '<inv:symVar>nejaky zcela neplatny symbol</inv:symVar>'
            . '<inv:number><typ:numberRequested>2026/0123</typ:numberRequested></inv:number>'
        ));

        self::assertSame('2026-0123', $inv['varsymbol']);
        self::assertSame('number_sanitized', $inv['varsymbol_source']);
        self::assertTrue($inv['varsymbol_substituted']);
        self::assertSame('2026/0123', $inv['document_number']);
    }

    /** Nebylo čím nahradit — původní hodnota projde dál a dosazení se nehlásí. */
    public function testPassedThroughInvalidSymVarIsNotASubstitution(): void
    {
        $guid = '3f2a91c4-77b5-4d0e-9a12-5c8b6e0d4a31';
        $inv = $this->parseFirst($this->header("<inv:symVar>$guid</inv:symVar>"));

        self::assertSame($guid, $inv['varsymbol']);
        self::assertFalse($inv['varsymbol_substituted']);
        self::assertNull($inv['document_number']);
    }
}
