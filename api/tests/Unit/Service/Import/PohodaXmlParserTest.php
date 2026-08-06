<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\TestCase;

final class PohodaXmlParserTest extends TestCase
{
    private PohodaXmlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PohodaXmlParser();
    }

    private function minimalPohoda(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
              xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
              xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
              ico="21370362" version="2.0">
  <dat:dataPackItem id="i1">
    <inv:invoice>
      <inv:invoiceHeader>
        <inv:invoiceType>issuedInvoice</inv:invoiceType>
        <inv:symVar>2605002</inv:symVar>
        <inv:date>2026-05-02</inv:date>
        <inv:dateTax>2026-05-02</inv:dateTax>
        <inv:dateDue>2026-05-16</inv:dateDue>
        <inv:text>Faktura test</inv:text>
        <inv:partnerIdentity>
          <typ:address>
            <typ:company>Klient X s.r.o.</typ:company>
            <typ:ico>12345678</typ:ico>
            <typ:email>klient@example.com</typ:email>
          </typ:address>
        </inv:partnerIdentity>
      </inv:invoiceHeader>
      <inv:invoiceDetail>
        <inv:invoiceItem>
          <inv:text>Programování</inv:text>
          <inv:quantity>5</inv:quantity>
          <inv:unit>hod</inv:unit>
          <inv:rateVAT>high</inv:rateVAT>
          <inv:homeCurrency>
            <typ:unitPrice>2000</typ:unitPrice>
          </inv:homeCurrency>
        </inv:invoiceItem>
      </inv:invoiceDetail>
    </inv:invoice>
  </dat:dataPackItem>
</dat:dataPack>
XML;
    }

    public function testHappyPath(): void
    {
        $result = $this->parser->parse($this->minimalPohoda());
        self::assertSame('21370362', $result['supplier_ic']);
        self::assertCount(1, $result['invoices']);

        $inv = $result['invoices'][0];
        self::assertSame('invoice', $inv['invoice_type']);
        self::assertSame('2605002', $inv['varsymbol']);
        self::assertSame('2026-05-02', $inv['issue_date']);
        self::assertSame('2026-05-16', $inv['due_date']);
        self::assertSame('CZK', $inv['currency']);
        self::assertNull($inv['exchange_rate']);
        self::assertSame('Klient X s.r.o.', $inv['client']['company_name']);
        self::assertSame('klient@example.com', $inv['client']['email']);
        self::assertCount(1, $inv['items']);
        self::assertSame(2000.0, $inv['items'][0]['unit_price_without_vat']);
        // Doklad nese jen enum `high` a nemá rekapitulaci, ze které by šlo procento
        // dopočítat — sazba tedy určená NENÍ (viz testCurrentScaleEnumWithoutPercentOrRecapIsUnknown).
        self::assertNull($inv['items'][0]['vat_rate']);
        self::assertSame('standard', $inv['items'][0]['vat_rate_level']);
        self::assertSame(5.0, $inv['items'][0]['quantity']);
    }

    /**
     * Uživatelský export z Pohody (VydFaktury.xml) — root je `responsePack`
     * s `listInvoice` a fakturami v `lst:invoice`, hlavička dál v `inv:`.
     */
    private function responsePackExport(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsp:responsePack version="2.0" id="Usr01" state="ok" ico="05687691"
        xmlns:rsp="http://www.stormware.cz/schema/version_2/response.xsd"
        xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
        xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd"
        xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd">
  <rsp:responsePackItem version="2.0" id="Usr01" state="ok">
    <lst:listInvoice version="2.0" state="ok">
      <lst:invoice version="2.0">
        <inv:invoiceHeader>
          <inv:invoiceType>issuedInvoice</inv:invoiceType>
          <inv:number><typ:numberRequested>26FV001</typ:numberRequested></inv:number>
          <inv:symVar>26001</inv:symVar>
          <inv:date>2026-01-04</inv:date>
          <inv:dateTax>2026-01-04</inv:dateTax>
          <inv:dateDue>2026-01-18</inv:dateDue>
          <inv:classificationVAT><typ:id>165</typ:id><typ:ids>UD</typ:ids></inv:classificationVAT>
          <inv:text>Fakturujeme Vam zbozi:</inv:text>
          <inv:partnerIdentity>
            <typ:address>
              <typ:company>AR SERVIS s.r.o.</typ:company>
              <typ:city>Jindrichuv Hradec</typ:city>
              <typ:ico>42408393</typ:ico>
              <typ:dic>CZ42408393</typ:dic>
            </typ:address>
          </inv:partnerIdentity>
        </inv:invoiceHeader>
        <inv:invoiceDetail>
          <inv:invoiceItem>
            <inv:text>HDD Western Digital</inv:text>
            <inv:quantity>2.0</inv:quantity>
            <inv:unit>ks</inv:unit>
            <inv:rateVAT value="21">high</inv:rateVAT>
            <inv:homeCurrency>
              <typ:unitPrice>3086</typ:unitPrice>
              <typ:price>6172</typ:price>
              <typ:priceVAT>1296.12</typ:priceVAT>
            </inv:homeCurrency>
          </inv:invoiceItem>
        </inv:invoiceDetail>
        <inv:invoiceSummary>
          <inv:homeCurrency>
            <typ:priceHigh>6172</typ:priceHigh>
            <typ:priceHighVAT rate="21">1296.12</typ:priceHighVAT>
          </inv:homeCurrency>
        </inv:invoiceSummary>
      </lst:invoice>
    </lst:listInvoice>
  </rsp:responsePackItem>
</rsp:responsePack>
XML;
    }

    public function testResponsePackExportParsed(): void
    {
        $result = $this->parser->parse($this->responsePackExport());
        self::assertSame('05687691', $result['supplier_ic']);
        self::assertCount(1, $result['invoices']);

        $inv = $result['invoices'][0];
        self::assertSame('invoice', $inv['invoice_type']);
        self::assertSame('26001', $inv['varsymbol']);
        self::assertSame('2026-01-04', $inv['issue_date']);
        self::assertSame('2026-01-18', $inv['due_date']);
        self::assertFalse($inv['reverse_charge']);
        self::assertSame('AR SERVIS s.r.o.', $inv['client']['company_name']);
        self::assertSame('42408393', $inv['client']['ic']);
        self::assertCount(1, $inv['items']);
        self::assertSame(3086.0, $inv['items'][0]['unit_price_without_vat']);
        self::assertSame(21.0, $inv['items'][0]['vat_rate']);
        self::assertSame(2.0, $inv['items'][0]['quantity']);
        // Rekapitulace DPH z summary (homeCurrency) — high sazba.
        self::assertArrayHasKey('21.00', $inv['vat_recap']);
        self::assertEqualsWithDelta(6172.0, $inv['vat_recap']['21.00']['base'], 0.01);
        self::assertEqualsWithDelta(1296.12, $inv['vat_recap']['21.00']['vat'], 0.01);
    }

    public function testProformaTypeMapping(): void
    {
        $xml = str_replace(
            '<inv:invoiceType>issuedInvoice</inv:invoiceType>',
            '<inv:invoiceType>issuedAdvanceInvoice</inv:invoiceType>',
            $this->minimalPohoda()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('proforma', $result['invoices'][0]['invoice_type']);
    }

    public function testCreditNoteMapping(): void
    {
        $xml = str_replace(
            '<inv:invoiceType>issuedInvoice</inv:invoiceType>',
            '<inv:invoiceType>issuedCreditNotice</inv:invoiceType>',
            $this->minimalPohoda()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('credit_note', $result['invoices'][0]['invoice_type']);
    }

    /**
     * `issuedCorrectiveTax` je OPRAVNÝ DAŇOVÝ DOKLAD podle § 42 ZDPH — to, čemu se
     * běžně říká dobropis, a typ, pod kterým opravné doklady vyváží SuperFaktura
     * i sama Pohoda. `issuedCreditNotice` je jen jeho nedaňová varianta, takže
     * pokrytím jedné z nich byla druhá tiše vynechaná.
     *
     * Dokud padal do `default`, přišel opravný doklad do systému jako ŘÁDNÁ faktura:
     * kladná daň na výstupu místo záporné, jiná sekce kontrolního hlášení a mimo
     * veškerou mechaniku oprav (v OSS včetně opravné věty za minulé období). U migrace
     * s 99 opravnými doklady jde o rozdíl v celé jedné straně přiznání.
     */
    public function testCorrectiveTaxDocumentIsCreditNote(): void
    {
        $xml = str_replace(
            '<inv:invoiceType>issuedInvoice</inv:invoiceType>',
            '<inv:invoiceType>issuedCorrectiveTax</inv:invoiceType>',
            $this->minimalPohoda()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('credit_note', $result['invoices'][0]['invoice_type']);
    }

    /**
     * Vrubopis zůstává fakturou schválně — zvyšuje závazek, takže by mu otočení
     * znamének z {@see \MyInvoice\Service\Import\InvoiceImportService} obrátilo
     * daň na špatnou stranu.
     */
    public function testDebitNoteStaysInvoice(): void
    {
        $xml = str_replace(
            '<inv:invoiceType>issuedInvoice</inv:invoiceType>',
            '<inv:invoiceType>issuedDebitNote</inv:invoiceType>',
            $this->minimalPohoda()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('invoice', $result['invoices'][0]['invoice_type']);
    }

    public function testRejectsDoctype(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE pwn [<!ENTITY x SYSTEM "file:///etc/passwd">]>
<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
              ico="21370362" version="2.0"/>
XML;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DOCTYPE/i');
        $this->parser->parse($xml);
    }

    public function testRejectsNonDataPackRoot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->parser->parse('<?xml version="1.0"?><foo/>');
    }

    public function testMalformedXmlThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->parser->parse('not really xml');
    }

    public function testForeignCurrencyDetected(): void
    {
        $xml = str_replace(
            '</inv:invoiceDetail>',
            '</inv:invoiceDetail>
      <inv:invoiceSummary>
        <inv:foreignCurrency>
          <typ:currency><typ:ids>EUR</typ:ids></typ:currency>
          <typ:rate>24.36</typ:rate>
        </inv:foreignCurrency>
      </inv:invoiceSummary>',
            $this->minimalPohoda()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('EUR', $result['invoices'][0]['currency']);
        self::assertEqualsWithDelta(24.36, $result['invoices'][0]['exchange_rate'], 1e-6);
    }

    // --- Sazba položky -----------------------------------------------------

    /** Sazbový element v {@see self::minimalPohoda()} nahrazený jiným tvarem. */
    private function withItemRate(string $rateXml): string
    {
        return str_replace('<inv:rateVAT>high</inv:rateVAT>', $rateXml, $this->minimalPohoda());
    }

    private function itemRate(string $rateXml): ?float
    {
        $inv = $this->parser->parse($this->withItemRate($rateXml))['invoices'][0];
        self::assertArrayNotHasKey('__error', $inv);

        return $inv['items'][0]['vat_rate'];
    }

    /**
     * Jádro opravy: Pohoda enum zahraniční sazbu neumí, takže producent
     * (SuperFaktura) pošle `historyHigh` a skutečných 23 % v `percentVAT`.
     * Dokud se `percentVAT` nečetl, spadl `historyHigh` do větve `default => 0.0`
     * a cizí daň z dokladu zmizela úplně — plnění se naimportovalo jako osvobozené.
     */
    public function testPercentVatWinsOverHistoryHighEnum(): void
    {
        self::assertSame(
            23.0,
            $this->itemRate('<inv:rateVAT>historyHigh</inv:rateVAT><inv:percentVAT>23</inv:percentVAT>'),
            'percentVAT má přednost před enumem — ani 0 (starý default), ani 21 (česká high)',
        );
    }

    /**
     * Enum bez procenta a bez rekapitulace sazbu NEURČUJE. Dřív se za `high` dosazovala
     * aktuální česká sazba a bylo to nejmenší zlo jen zdánlivě: doklad pro polského
     * spotřebitele dostal 21 %, číselník je jako českou sazbu POTVRDIL, invariant proti
     * úniku ho pustil jako tuzemský a polská daň skončila na ř. 1 českého přiznání.
     * Parser proto vrací `null` a syrový enum s úrovní, ze kterých dosadí procento až
     * vrstva, která má zemi dodavatele a číselník k datu plnění.
     */
    public function testCurrentScaleEnumWithoutPercentOrRecapIsUnknown(): void
    {
        self::assertNull($this->itemRate('<inv:rateVAT>high</inv:rateVAT>'));
        self::assertNull($this->itemRate('<inv:rateVAT>low</inv:rateVAT>'));
        self::assertNull($this->itemRate('<inv:rateVAT>low2</inv:rateVAT>'));
    }

    /** Úroveň enumu se pojmenuje hodnotami číselníku, ať se dá položit jako dotaz. */
    public function testEnumLevelIsExposedForTheImportLayer(): void
    {
        $item = $this->parser->parse($this->withItemRate('<inv:rateVAT>high</inv:rateVAT>'))
            ['invoices'][0]['items'][0];

        self::assertNull($item['vat_rate']);
        self::assertSame('unresolved', $item['vat_rate_source']);
        self::assertSame('high', $item['vat_rate_enum']);
        self::assertSame('standard', $item['vat_rate_level']);
    }

    /**
     * `history*` sazbovou úroveň neurčuje ani k datu plnění — znamená doslova „tahle
     * sazba už neplatí", takže se z ní nesmí stát ani dotaz do číselníku.
     */
    public function testHistoryEnumsCarryNoLevel(): void
    {
        foreach (['historyHigh', 'historyLow', 'uplneNeznamyKod'] as $code) {
            $item = $this->parser->parse($this->withItemRate("<inv:rateVAT>$code</inv:rateVAT>"))
                ['invoices'][0]['items'][0];

            self::assertNull($item['vat_rate'], $code);
            self::assertSame($code, $item['vat_rate_enum'], $code);
            self::assertNull($item['vat_rate_level'], $code);
        }
    }

    /**
     * `third` je dle XSD slovenská 3. sazba a v českých souborech odpovídá 3. přihrádce
     * summary — je to tedy sazbová ÚROVEŇ, ne osvobození. Nula by z něj udělala
     * osvobozené plnění, které invariant proti úniku vůbec neprověřuje.
     */
    public function testThirdRateEnumIsNotTreatedAsExempt(): void
    {
        $item = $this->parser->parse($this->withItemRate('<inv:rateVAT>third</inv:rateVAT>'))
            ['invoices'][0]['items'][0];

        self::assertNull($item['vat_rate']);
        self::assertSame('second_reduced', $item['vat_rate_level']);
    }

    /** Osvobozené plnění a chybějící element zůstávají na nule (deriver → ZeroRate). */
    public function testExemptEnumsStayAtZero(): void
    {
        self::assertSame(0.0, $this->itemRate('<inv:rateVAT>none</inv:rateVAT>'));
        self::assertSame(0.0, $this->itemRate('<inv:rateVAT>nonSubsume</inv:rateVAT>'));
        self::assertSame(0.0, $this->itemRate(''));
    }

    /**
     * Sazby s desetinnou částí (FI 25,5 %, IE 13,5 %, SI 9,5 %) se nesmí zaokrouhlit
     * ani spadnout na nejbližší českou úroveň — z procenta se páruje `vat_rate_id`
     * a jeho posun by položku navázal na cizí sazbu.
     */
    public function testDecimalPercentVatIsPreserved(): void
    {
        self::assertSame(25.5, $this->itemRate('<inv:rateVAT>historyHigh</inv:rateVAT><inv:percentVAT>25.5</inv:percentVAT>'));
        self::assertSame(13.5, $this->itemRate('<inv:rateVAT>historyLow</inv:rateVAT><inv:percentVAT>13.5</inv:percentVAT>'));
        self::assertSame(9.5, $this->itemRate('<inv:rateVAT>historyLow</inv:rateVAT><inv:percentVAT>9.5</inv:percentVAT>'));
    }

    /**
     * Nepoužitelný `percentVAT` se ignoruje — zvlášť nula: `percentVAT=0` nad položkou
     * označenou `high` je artefakt producenta, ne osvobození, a jedna vadná hodnota by
     * jinak vynulovala daň na celém balíku. Sazba tím ale ZNÁMÁ nebude: enum ji sám
     * neurčuje, takže výsledek je `null`, ne 0 a ne 21.
     */
    public function testUnusablePercentVatDoesNotBecomeZero(): void
    {
        $variants = [
            '<inv:percentVAT/>',
            '<inv:percentVAT>abc</inv:percentVAT>',
            '<inv:percentVAT>0</inv:percentVAT>',
            '<inv:percentVAT>-5</inv:percentVAT>',
            '<inv:percentVAT>120</inv:percentVAT>',
        ];
        foreach ($variants as $percentEl) {
            self::assertNull($this->itemRate('<inv:rateVAT>high</inv:rateVAT>' . $percentEl), $percentEl);
        }
    }

    public function testPercentVatSurroundedByWhitespaceIsRead(): void
    {
        self::assertSame(23.0, $this->itemRate(
            "<inv:rateVAT>historyHigh</inv:rateVAT><inv:percentVAT>\n      23\n    </inv:percentVAT>"
        ));
    }

    // --- Variabilní symbol -------------------------------------------------

    /** Hlavička v {@see self::minimalPohoda()} s jiným tvarem symVar/number. */
    private function withHeaderSymbols(string $headerXml): string
    {
        return str_replace('<inv:symVar>2605002</inv:symVar>', $headerXml, $this->minimalPohoda());
    }

    /** Regrese: platný symVar se nesmí nahrazovat ani hlásit jako náhrada. */
    public function testValidSymVarIsKeptAndNotReported(): void
    {
        $inv = $this->parser->parse($this->minimalPohoda())['invoices'][0];

        self::assertSame('2605002', $inv['varsymbol']);
        self::assertSame('symVar', $inv['varsymbol_source']);
        self::assertNull($inv['varsymbol_original']);
    }

    /**
     * 1058 z 1670 migrovaných dokladů má v `symVar` interní GUID producenta. Ten
     * neprojde {@see PohodaXmlParser::VARSYMBOL_PATTERN} a import doklad odmítal;
     * fallback na číslo dokladu ho zachrání a náhradu ohlásí, aby se dal doklad
     * pod původní hodnotou dohledat.
     */
    public function testGuidSymVarFallsBackToDocumentNumber(): void
    {
        $guid = '3f2a91c4-77b5-4d0e-9a12-5c8b6e0d4a31';
        $inv = $this->parser->parse($this->withHeaderSymbols(
            "<inv:symVar>$guid</inv:symVar>"
            . '<inv:number><typ:numberRequested>26FV0007</typ:numberRequested></inv:number>'
        ))['invoices'][0];

        self::assertArrayNotHasKey('__error', $inv);
        self::assertSame('26FV0007', $inv['varsymbol']);
        self::assertSame('number', $inv['varsymbol_source']);
        self::assertSame($guid, $inv['varsymbol_original']);
        self::assertTrue(PohodaXmlParser::isAcceptableVarsymbol($inv['varsymbol']));
    }

    /** Regrese: bez symVar i bez čísla dokladu není co dosadit → čitelná chyba. */
    public function testMissingSymVarAndNumberRejectsInvoice(): void
    {
        $inv = $this->parser->parse($this->withHeaderSymbols(''))['invoices'][0];

        self::assertArrayHasKey('__error', $inv);
        self::assertStringContainsString('varsymbol', $inv['__error']);
    }

    // --- Zákaznický tvar dokladu (SuperFaktura → OSS) ----------------------

    /**
     * Doklad v tom tvaru, v jakém chodí ze SuperFaktury: PLN, GUID místo VS,
     * `historyHigh` + `percentVAT` a 23 % schovaných v české přihrádce `High`.
     */
    private function ossPlnInvoice(
        string $summaryBuckets = '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT>230.00</typ:priceHighVAT>',
        string $currency = 'PLN',
    ): string {
        return <<<XML
        <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                      xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                      xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                      ico="21370362" version="2.0">
          <dat:dataPackItem id="i1">
            <inv:invoice version="2.0">
              <inv:invoiceHeader>
                <inv:invoiceType>issuedInvoice</inv:invoiceType>
                <inv:symVar>3f2a91c4-77b5-4d0e-9a12-5c8b6e0d4a31</inv:symVar>
                <inv:number><typ:numberRequested>26OSS0042</typ:numberRequested></inv:number>
                <inv:date>2026-06-15</inv:date>
                <inv:dateTax>2026-06-15</inv:dateTax>
                <inv:dateDue>2026-06-29</inv:dateDue>
                <inv:classificationVAT><typ:ids>inland</typ:ids></inv:classificationVAT>
                <inv:partnerIdentity>
                  <typ:address>
                    <typ:company>Testowy Odbiorca sp. z o.o.</typ:company>
                    <typ:city>Warszawa</typ:city>
                    <typ:country><typ:ids>PL</typ:ids></typ:country>
                  </typ:address>
                </inv:partnerIdentity>
              </inv:invoiceHeader>
              <inv:invoiceDetail>
                <inv:invoiceItem>
                  <inv:text>Licence software</inv:text>
                  <inv:quantity>1</inv:quantity>
                  <inv:unit>ks</inv:unit>
                  <inv:rateVAT>historyHigh</inv:rateVAT>
                  <inv:percentVAT>23</inv:percentVAT>
                  <inv:foreignCurrency><typ:unitPrice>1000</typ:unitPrice></inv:foreignCurrency>
                </inv:invoiceItem>
              </inv:invoiceDetail>
              <inv:invoiceSummary>
                <inv:foreignCurrency>
                  <typ:currency><typ:ids>$currency</typ:ids></typ:currency>
                  <typ:rate>5.80</typ:rate>
                  $summaryBuckets
                </inv:foreignCurrency>
              </inv:invoiceSummary>
            </inv:invoice>
          </dat:dataPackItem>
        </dat:dataPack>
        XML;
    }

    /**
     * Všechny tři vstupy, na kterých derivace OSS stojí, naráz: skutečné procento,
     * použitelný variabilní symbol a země klienta. `classificationVATType=inland`
     * u OSS dokladu je no-op — čteme jen `classificationVAT` kvůli PDP (`PN*`).
     */
    public function testSuperfakturaStyleOssInvoiceIsFullyReadable(): void
    {
        $inv = $this->parser->parse($this->ossPlnInvoice())['invoices'][0];

        self::assertArrayNotHasKey('__error', $inv);
        self::assertSame('PLN', $inv['currency']);
        self::assertEqualsWithDelta(5.80, $inv['exchange_rate'], 1e-6);
        self::assertFalse($inv['reverse_charge']);

        self::assertSame('26OSS0042', $inv['varsymbol']);
        self::assertSame('number', $inv['varsymbol_source']);
        self::assertSame('3f2a91c4-77b5-4d0e-9a12-5c8b6e0d4a31', $inv['varsymbol_original']);

        self::assertSame('PL', $inv['client']['country_iso2']);
        self::assertNull($inv['client']['dic'], 'bez DIČ = B2C indicie pro derivaci OSS');

        self::assertSame(23.0, $inv['items'][0]['vat_rate']);
        self::assertSame(1000.0, $inv['items'][0]['unit_price_without_vat']);

        // 23 % leží v přihrádce `High`; české čtení by z něj udělalo 21 %.
        self::assertSame(['23.00'], array_keys($inv['vat_recap']));
        self::assertSame(['base' => 1000.00, 'vat' => 230.00], $inv['vat_recap']['23.00']);
    }

    /**
     * Přihrádka jen s částkou DPH: dopočet z částek nejde, takže rozhodne
     * deklarovaný `@rate` — a musí být silnější než český název přihrádky.
     */
    public function testDeclaredRateAttributeDecidesWhenAmountsCannotConfirm(): void
    {
        $inv = $this->parser->parse($this->ossPlnInvoice(
            '<typ:priceHighVAT rate="23">230.00</typ:priceHighVAT>'
        ))['invoices'][0];

        self::assertSame(['23.00'], array_keys($inv['vat_recap']));
        self::assertSame(['base' => 0.0, 'vat' => 230.00], $inv['vat_recap']['23.00']);
    }

    /** Desetinná sazba nesmí spadnout na kotvu přihrádky ani rozbít tvar klíče. */
    public function testDecimalRateInRecapKeepsItsOwnBucket(): void
    {
        $inv = $this->parser->parse($this->ossPlnInvoice(
            '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT>95.00</typ:priceHighVAT>',
            'EUR',
        ))['invoices'][0];

        self::assertSame('EUR', $inv['currency']);
        self::assertSame(['9.50'], array_keys($inv['vat_recap']));
    }
}
