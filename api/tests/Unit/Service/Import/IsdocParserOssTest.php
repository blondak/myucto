<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IsdocParser;
use PHPUnit\Framework\TestCase;

/**
 * ISDOC a zahraniční (OSS) sazby.
 *
 * Na rozdíl od Pohody nese ISDOC skutečné procento přímo
 * v `ClassifiedTaxCategory/Percent`, takže se nikdy neztratilo — zákazníkovo
 * „v sumaci se 23 % zobrazuje správně" platí právě pro tuhle cestu. Testy to
 * zamykají jako regresi: derivace OSS při importu stojí na tom, že parser dodá
 * skutečnou sazbu i zemi a DIČ klienta, ze kterých se pozná B2C plnění do jiného
 * členského státu. Kdyby se procento cestou srovnalo na českou škálu, uzavře se
 * díra v Pohodě a ISDOC se rozbije tiše.
 */
final class IsdocParserOssTest extends TestCase
{
    private IsdocParser $parser;

    protected function setUp(): void
    {
        $this->parser = new IsdocParser();
    }

    /**
     * Doklad v PLN pro polského spotřebitele; syntetická protistrana.
     */
    private function build(
        string $classifiedTaxCategory = '<ClassifiedTaxCategory><Percent>23</Percent></ClassifiedTaxCategory>',
        string $customerExtra = '',
        string $taxTotal = '',
    ): string {
        return <<<XML
        <Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.2">
          <DocumentType>1</DocumentType>
          <ID>26OSS0042</ID>
          <IssueDate>2026-06-15</IssueDate>
          <TaxPointDate>2026-06-15</TaxPointDate>
          <LocalCurrencyCode>CZK</LocalCurrencyCode>
          <ForeignCurrencyCode>PLN</ForeignCurrencyCode>
          <CurrRate>5.80</CurrRate>
          <AccountingSupplierParty>
            <Party>
              <PartyIdentification><ID>12345678</ID></PartyIdentification>
            </Party>
          </AccountingSupplierParty>
          <AccountingCustomerParty>
            <Party>
              <PartyName><Name>Testowy Odbiorca sp. z o.o.</Name></PartyName>
              <PostalAddress>
                <StreetName>Testowa</StreetName>
                <BuildingNumber>1</BuildingNumber>
                <CityName>Warszawa</CityName>
                <PostalZone>00-001</PostalZone>
                <Country><IdentificationCode>pl</IdentificationCode><Name>Polska</Name></Country>
              </PostalAddress>
              $customerExtra
            </Party>
          </AccountingCustomerParty>
          <InvoiceLines>
            <InvoiceLine>
              <Item><Description>Licence software</Description></Item>
              <InvoicedQuantity unitCode="ks">1</InvoicedQuantity>
              <UnitPrice>5800</UnitPrice>
              <LineExtensionAmount>5800</LineExtensionAmount>
              <LineExtensionAmountCurr>1000</LineExtensionAmountCurr>
              $classifiedTaxCategory
            </InvoiceLine>
          </InvoiceLines>
          $taxTotal
        </Invoice>
        XML;
    }

    /**
     * @return array<string,mixed>
     */
    private function parseFirst(string $xml): array
    {
        $res = $this->parser->parse($xml);
        self::assertNotEmpty($res['invoices']);
        self::assertArrayNotHasKey('__error', $res['invoices'][0]);

        return $res['invoices'][0];
    }

    private function firstRate(string $classifiedTaxCategory): ?float
    {
        return $this->parseFirst($this->build($classifiedTaxCategory))['items'][0]['vat_rate'];
    }

    /**
     * @return array<string,mixed>
     */
    private function firstItem(string $classifiedTaxCategory, string $taxTotal = ''): array
    {
        return $this->parseFirst($this->build($classifiedTaxCategory, taxTotal: $taxTotal))['items'][0];
    }

    /** Regrese: zahraniční procento se čte doslova, nesrovnává se na českou škálu. */
    public function testForeignPercentIsReadVerbatim(): void
    {
        self::assertSame(23.0, $this->firstRate('<ClassifiedTaxCategory><Percent>23</Percent></ClassifiedTaxCategory>'));
        self::assertSame(27.0, $this->firstRate('<ClassifiedTaxCategory><Percent>27</Percent></ClassifiedTaxCategory>'));
    }

    /** Sazby s desetinnou částí (SI 9,5 %, IE 13,5 %, FI 25,5 %) se nezaokrouhlují. */
    public function testDecimalPercentIsPreserved(): void
    {
        self::assertSame(9.5, $this->firstRate('<ClassifiedTaxCategory><Percent>9.5</Percent></ClassifiedTaxCategory>'));
        self::assertSame(13.5, $this->firstRate('<ClassifiedTaxCategory><Percent>13.5</Percent></ClassifiedTaxCategory>'));
        self::assertSame(25.5, $this->firstRate('<ClassifiedTaxCategory><Percent>25.5</Percent></ClassifiedTaxCategory>'));
    }

    /**
     * Chybějící `Percent` je MLČENÍ, ne prohlášení o nule. Přetyp `(float) null` = 0.0
     * z něj dělal osvobozené plnění — a nulová sazba je z invariantu proti úniku cizí
     * daně ZÁMĚRNĚ vyňatá (bez daně není co unikat), takže zdaněný zahraniční řádek
     * neskončil ve špatné zemi, ale zmizel z evidence úplně. Tentýž únik jako u Pohody,
     * jen druhou větví.
     */
    public function testMissingPercentIsUnknownNotZero(): void
    {
        foreach (['', '<ClassifiedTaxCategory/>', '<ClassifiedTaxCategory><Percent/></ClassifiedTaxCategory>'] as $xml) {
            self::assertNull($this->firstRate($xml), $xml);
        }
        self::assertSame('unresolved', $this->firstItem('')['vat_rate_source']);
    }

    /** Explicitní nula je naopak prohlášení — osvobozené plnění se importovat musí. */
    public function testExplicitZeroPercentIsALegitimateRate(): void
    {
        $item = $this->firstItem('<ClassifiedTaxCategory><Percent>0</Percent></ClassifiedTaxCategory>');

        self::assertSame(0.0, $item['vat_rate']);
        self::assertSame('percent', $item['vat_rate_source']);
    }

    /**
     * Nedaňový ŘÁDEK má nulu i bez `Percent`: `VATApplicable=false` je dle ISDOC 4.1.5
     * prohlášení, že plnění dani nepodléhá. Bez tohohle rozlišení by se odmítl každý
     * doklad od neplátce DPH.
     */
    public function testNonTaxLineWithoutPercentIsZero(): void
    {
        $item = $this->firstItem('<ClassifiedTaxCategory><VATApplicable>false</VATApplicable></ClassifiedTaxCategory>');

        self::assertSame(0.0, $item['vat_rate']);
        self::assertSame('non_tax_line', $item['vat_rate_source']);
    }

    /**
     * Vstupy pro derivaci OSS: země klienta a prázdné DIČ. Země se normalizuje na
     * UPPERCASE — producent ji umí poslat malými písmeny a porovnání se zemí
     * dodavatele je citlivé na velikost písmen.
     */
    public function testClientCountryIsUppercasedAndVatIdEmptyForConsumer(): void
    {
        $client = $this->parseFirst($this->build())['client'];

        self::assertSame('PL', $client['country_iso2']);
        self::assertNull($client['dic'], 'bez DIČ = B2C indicie pro derivaci OSS');
        self::assertSame('Testowa 1', $client['street']);
    }

    /** S DIČ jde o B2B (reverse charge / dodání do JČS), ne o OSS — parser ho musí předat. */
    public function testClientVatIdIsExposedForB2b(): void
    {
        $client = $this->parseFirst($this->build(
            customerExtra: '<PartyTaxScheme><CompanyID>PL0000000000</CompanyID></PartyTaxScheme>',
        ))['client'];

        self::assertSame('PL0000000000', $client['dic']);
    }

    /**
     * Cizoměnová jednotková cena se bere z `LineExtensionAmountCurr` — `UnitPrice`
     * je dle ISDOC vždy v lokální měně a do OSS základu by propsal koruny.
     */
    public function testForeignCurrencyAmountsComeFromCurrElements(): void
    {
        $inv = $this->parseFirst($this->build());

        self::assertSame('PLN', $inv['currency']);
        self::assertEqualsWithDelta(5.80, $inv['exchange_rate'], 1e-6);
        self::assertSame(1000.0, $inv['items'][0]['unit_price_without_vat']);
    }

    /** Rekapitulace drží zahraniční sazbu ve vlastní přihrádce, nespojí ji s českou. */
    public function testTaxRecapKeepsForeignRate(): void
    {
        $inv = $this->parseFirst($this->build(taxTotal: <<<'XML'
          <TaxTotal>
            <TaxSubTotal>
              <TaxableAmountCurr>1000.00</TaxableAmountCurr>
              <TaxableAmount>5800.00</TaxableAmount>
              <TaxAmountCurr>230.00</TaxAmountCurr>
              <TaxAmount>1334.00</TaxAmount>
              <TaxCategory><Percent>23</Percent></TaxCategory>
            </TaxSubTotal>
            <TaxAmount>1334.00</TaxAmount>
          </TaxTotal>
        XML));

        self::assertSame(['23.00'], array_keys($inv['vat_recap']));
        self::assertSame(['base' => 1000.00, 'vat' => 230.00], $inv['vat_recap']['23.00']);
    }

    /**
     * Řádkové `VATApplicable=false` nuluje sazbu i u zahraničního procenta —
     * nedaňový řádek se do OSS nesmí dostat jen proto, že v něm zůstalo 23 %.
     */
    public function testNonTaxLineIsZeroEvenWithForeignPercent(): void
    {
        self::assertSame(0.0, $this->firstRate(
            '<ClassifiedTaxCategory><Percent>23</Percent><VATApplicable>false</VATApplicable></ClassifiedTaxCategory>'
        ));
    }

    // --- Křížová kontrola řádků proti rekapitulaci (§ G2) ------------------

    private function taxTotal(string $percent, string $baseCurr, string $vatCurr): string
    {
        return "<TaxTotal><TaxSubTotal>"
            . "<TaxableAmountCurr>$baseCurr</TaxableAmountCurr>"
            . "<TaxAmountCurr>$vatCurr</TaxAmountCurr>"
            . "<TaxCategory><Percent>$percent</Percent></TaxCategory>"
            . "</TaxSubTotal></TaxTotal>";
    }

    /** Řádek tvrdí 23 %, rekapitulace 21 % — obojí platit nemůže. */
    public function testContradictingRecapRateIsReported(): void
    {
        $invoice = $this->parseFirst($this->build(
            taxTotal: $this->taxTotal('21', '1000.00', '210.00'),
        ));

        self::assertCount(1, $invoice['file_issues']);
        self::assertStringContainsString('23 %', $invoice['file_issues'][0]);
        self::assertStringContainsString('21 %', $invoice['file_issues'][0]);
    }

    /** Rozdíl v základu při shodné sazbě je vada souboru stejně jako rozdíl v sazbě. */
    public function testMismatchedRecapBaseIsReported(): void
    {
        $invoice = $this->parseFirst($this->build(
            taxTotal: $this->taxTotal('23', '2500.00', '575.00'),
        ));

        self::assertCount(1, $invoice['file_issues']);
        self::assertStringContainsString('2 500,00', $invoice['file_issues'][0]);
    }

    /** Sedící doklad nesmí hlásit nic — jinak by se hláška stala šumem. */
    public function testConsistentDocumentHasNoFileIssues(): void
    {
        $invoice = $this->parseFirst($this->build(
            taxTotal: $this->taxTotal('23', '1000.00', '230.00'),
        ));

        self::assertSame([], $invoice['file_issues']);
    }

    /** Řádek s neurčenou sazbou kontrolu vypíná — doklad má konkrétnější hlášku. */
    public function testUnknownLineRateSuppressesTheCrossCheck(): void
    {
        $invoice = $this->parseFirst($this->build('', taxTotal: $this->taxTotal('21', '1000.00', '210.00')));

        self::assertNull($invoice['items'][0]['vat_rate']);
        self::assertSame([], $invoice['file_issues']);
    }
}
