<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IsdocParser;
use PHPUnit\Framework\TestCase;

/**
 * Kurz cizí měny z ISDOC — `<CurrRate>` DĚLENO `<RefCurrRate>`.
 *
 * ISDOC vede kurz jako zlomek: `CurrRate` je částka v lokální měně a `RefCurrRate`
 * množství cizí měny, kterému odpovídá. Parser dřív četl jen čitatel, takže u měn
 * kotovaných jinak než po jedné jednotce dostal doklad kurz mimo řád.
 *
 * Cena té chyby nebyla kosmetická. Forintový doklad má typicky `CurrRate=1` a
 * `RefCurrRate=14,5688`; se čteným kurzem 1,00 se z 13 520 HUF stalo 13 520 Kč místo
 * 844 Kč. U dokladu vystaveného PŘED registrací do OSS (tedy s českou daní) jde ten
 * základ rovnou na ř. 1 přiznání k DPH — čtrnáctinásobek, a to tiše.
 *
 * Data jsou syntetická.
 */
final class IsdocParserExchangeRateTest extends TestCase
{
    /** @return array<string,mixed> */
    private function parseFirst(string $xml): array
    {
        $res = (new IsdocParser())->parse($xml);
        self::assertNotEmpty($res['invoices']);
        self::assertArrayNotHasKey('__error', $res['invoices'][0]);

        return $res['invoices'][0];
    }

    private function build(string $foreignCurrency, string $currRate, string $refCurrRate): string
    {
        $ref = $refCurrRate === '' ? '' : "<RefCurrRate>{$refCurrRate}</RefCurrRate>";

        return <<<XML
        <Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.2">
          <DocumentType>1</DocumentType>
          <ID>26FX0001</ID>
          <IssueDate>2026-06-15</IssueDate>
          <TaxPointDate>2026-06-15</TaxPointDate>
          <LocalCurrencyCode>CZK</LocalCurrencyCode>
          <ForeignCurrencyCode>{$foreignCurrency}</ForeignCurrencyCode>
          <CurrRate>{$currRate}</CurrRate>
          {$ref}
          <AccountingSupplierParty>
            <Party><PartyIdentification><ID>12345678</ID></PartyIdentification></Party>
          </AccountingSupplierParty>
          <AccountingCustomerParty>
            <Party>
              <PartyName><Name>Vzorový odběratel s.r.o.</Name></PartyName>
              <PostalAddress>
                <CityName>Budapest</CityName>
                <Country><IdentificationCode>HU</IdentificationCode></Country>
              </PostalAddress>
            </Party>
          </AccountingCustomerParty>
          <InvoiceLines>
            <InvoiceLine>
              <Item><Description>Vzorová položka</Description></Item>
              <InvoicedQuantity unitCode="ks">1</InvoicedQuantity>
              <UnitPrice>100</UnitPrice>
              <LineExtensionAmount>100</LineExtensionAmount>
              <LineExtensionAmountCurr>1000</LineExtensionAmountCurr>
              <ClassifiedTaxCategory><Percent>27</Percent></ClassifiedTaxCategory>
            </InvoiceLine>
          </InvoiceLines>
        </Invoice>
        XML;
    }

    private function rate(string $currency, string $currRate, string $refCurrRate = ''): ?float
    {
        $value = $this->parseFirst($this->build($currency, $currRate, $refCurrRate))['exchange_rate'];

        return $value === null ? null : (float) $value;
    }

    /**
     * HLAVNÍ REGRESE — kurz zapsaný obráceně (jednotka lokální měny v čitateli).
     *
     * Přesně tenhle tvar píše forintovým dokladům SuperFaktura. Dřív z něj vyšel
     * kurz 1,00 (1 HUF = 1 Kč) a základ daně přepočtený do korun byl 14× vyšší.
     */
    public function testInvertedRateIsDividedByReference(): void
    {
        self::assertEqualsWithDelta(0.0686, $this->rate('HUF', '1', '14.5688'), 0.00005);
    }

    /** Klasický tvar téhož kurzu — měna kotovaná po stovkách jednotek. */
    public function testHundredUnitQuotationIsDividedByReference(): void
    {
        self::assertEqualsWithDelta(0.0686, $this->rate('HUF', '6.86', '100'), 0.00005);
    }

    /** `RefCurrRate=1` je nejběžnější případ a nesmí se změnou nijak pohnout. */
    public function testUnitReferenceLeavesRateUntouched(): void
    {
        self::assertEqualsWithDelta(5.744, $this->rate('PLN', '5.744', '1'), 0.000001);
    }

    /**
     * Chybějící `RefCurrRate` znamená 1 — XSD mu žádný default nedává a starší
     * exporty ho vůbec nepíšou.
     */
    public function testMissingReferenceMeansOne(): void
    {
        self::assertEqualsWithDelta(24.285, $this->rate('EUR', '24.285'), 0.000001);
    }

    /**
     * Nula a nesmysl v `RefCurrRate` se berou jako 1, ne jako dělení nulou. Kurz
     * z čitatele je pořád použitelný odhad, kdežto pád parseru shodí celý soubor.
     */
    public function testZeroOrGarbageReferenceFallsBackToOne(): void
    {
        self::assertEqualsWithDelta(24.285, $this->rate('EUR', '24.285', '0'), 0.000001);
        self::assertEqualsWithDelta(24.285, $this->rate('EUR', '24.285', 'x'), 0.000001);
    }

    /**
     * Nekladný nebo nečitelný `CurrRate` je NEZNÁMÝ kurz, ne nulový. Nula by se
     * uložila do hlavičky a přepočet dokladu do korun by vyšel na nulu; `null`
     * nechá dosadit kurz ČNB k datu plnění.
     */
    public function testUnusableRateIsUnknownNotZero(): void
    {
        self::assertNull($this->rate('EUR', '0'));
        self::assertNull($this->rate('EUR', '-1'));
        self::assertNull($this->rate('EUR', 'nesmysl'));
    }

    /** Doklad v lokální měně kurz nemá, ať už v souboru stojí cokoli. */
    public function testLocalCurrencyDocumentHasNoRate(): void
    {
        $xml = str_replace(
            '<ForeignCurrencyCode>CZK</ForeignCurrencyCode>',
            '<ForeignCurrencyCode/>',
            $this->build('CZK', '1', '14.5688'),
        );
        self::assertNull($this->parseFirst($xml)['exchange_rate']);
    }
}
