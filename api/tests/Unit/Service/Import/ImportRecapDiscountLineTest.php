<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Křížová kontrola „řádky vs. rekapitulace" nad dokladem se ZÁPORNÝM řádkem.
 *
 * Slevový kupón, vratka jednoho kusu nebo stornovaná položka jsou v e-shopových
 * exportech běžné a přicházejí jako řádek se záporným součtem. Kontrola je dřív
 * sčítala v absolutní hodnotě po jednotlivých řádcích, takže sleva k základu
 * PŘIČETLA místo aby ho snížila: doklad 1 195 + 74 − 165 + 65 vyšel na 1 500 proti
 * rekapitulaci 1 169 a uživatel dostal hlášku o rozporu, který v souboru není.
 *
 * U migrace se stovkami dokladů to není kosmetika. Hláška „doklad si v souboru
 * odporuje" má člověka poslat zkontrolovat zdrojový soubor; když ji dostane u
 * každého dokladu se slevou, přestane ji číst — a přehlédne tu jednu, která platí.
 * Test proto hlídá obě strany: falešný poplach zmizel a skutečný rozpor zůstal.
 *
 * Data jsou syntetická.
 */
final class ImportRecapDiscountLineTest extends TestCase
{
    private const DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';
    private const INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    private const TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    /**
     * ISDOC: zboží 1 000, sleva −200, doprava 100 → základ 900. Rekapitulace uvádí
     * týž základ, takže hlásit není co.
     *
     * @return array<string,mixed>
     */
    private function isdoc(string $recapBase, string $recapVat): array
    {
        $xml = <<<XML
        <Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.2">
          <DocumentType>1</DocumentType>
          <ID>26SLEVA01</ID>
          <IssueDate>2026-04-10</IssueDate>
          <TaxPointDate>2026-04-10</TaxPointDate>
          <LocalCurrencyCode>CZK</LocalCurrencyCode>
          <AccountingSupplierParty>
            <Party><PartyIdentification><ID>12345678</ID></PartyIdentification></Party>
          </AccountingSupplierParty>
          <AccountingCustomerParty>
            <Party>
              <PartyName><Name>Vzorový odběratel s.r.o.</Name></PartyName>
              <PostalAddress><Country><IdentificationCode>CZ</IdentificationCode></Country></PostalAddress>
            </Party>
          </AccountingCustomerParty>
          <InvoiceLines>
            <InvoiceLine>
              <Item><Description>Vzorové zboží</Description></Item>
              <InvoicedQuantity unitCode="ks">1</InvoicedQuantity>
              <UnitPrice>1000</UnitPrice>
              <LineExtensionAmount>1000</LineExtensionAmount>
              <ClassifiedTaxCategory><Percent>21</Percent></ClassifiedTaxCategory>
            </InvoiceLine>
            <InvoiceLine>
              <Item><Description>Slevový kupón</Description></Item>
              <InvoicedQuantity unitCode="ks">1</InvoicedQuantity>
              <UnitPrice>-200</UnitPrice>
              <LineExtensionAmount>-200</LineExtensionAmount>
              <ClassifiedTaxCategory><Percent>21</Percent></ClassifiedTaxCategory>
            </InvoiceLine>
            <InvoiceLine>
              <Item><Description>Doprava</Description></Item>
              <InvoicedQuantity unitCode="ks">1</InvoicedQuantity>
              <UnitPrice>100</UnitPrice>
              <LineExtensionAmount>100</LineExtensionAmount>
              <ClassifiedTaxCategory><Percent>21</Percent></ClassifiedTaxCategory>
            </InvoiceLine>
          </InvoiceLines>
          <TaxTotal>
            <TaxSubTotal>
              <TaxableAmount>{$recapBase}</TaxableAmount>
              <TaxAmount>{$recapVat}</TaxAmount>
              <TaxCategory><Percent>21</Percent></TaxCategory>
            </TaxSubTotal>
            <TaxAmount>{$recapVat}</TaxAmount>
          </TaxTotal>
        </Invoice>
        XML;

        $res = (new IsdocParser())->parse($xml);
        self::assertNotEmpty($res['invoices']);
        self::assertArrayNotHasKey('__error', $res['invoices'][0]);

        return $res['invoices'][0];
    }

    /** Pohoda: tytéž tři položky v `<inv:invoiceDetail>` a rekapitulace v `homeCurrency`. */
    private function pohoda(string $recapBase, string $recapVat): array
    {
        $dat = self::DAT;
        $inv = self::INV;
        $typ = self::TYP;
        $xml = <<<XML
        <dat:dataPack xmlns:dat="$dat" xmlns:inv="$inv" xmlns:typ="$typ" ico="12345678">
          <dat:dataPackItem>
            <inv:invoice version="2.0">
              <inv:invoiceHeader>
                <inv:invoiceType>issuedInvoice</inv:invoiceType>
                <inv:symVar>26SLEVA02</inv:symVar>
                <inv:date>2026-04-10</inv:date>
                <inv:dateTax>2026-04-10</inv:dateTax>
              </inv:invoiceHeader>
              <inv:invoiceDetail>
                <inv:invoiceItem>
                  <inv:text>Vzorové zboží</inv:text>
                  <inv:quantity>1</inv:quantity>
                  <inv:unit>ks</inv:unit>
                  <inv:payVAT>false</inv:payVAT>
                  <inv:rateVAT>high</inv:rateVAT>
                  <inv:percentVAT>21</inv:percentVAT>
                  <inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>
                </inv:invoiceItem>
                <inv:invoiceItem>
                  <inv:text>Slevový kupón</inv:text>
                  <inv:quantity>1</inv:quantity>
                  <inv:unit>ks</inv:unit>
                  <inv:payVAT>false</inv:payVAT>
                  <inv:rateVAT>high</inv:rateVAT>
                  <inv:percentVAT>21</inv:percentVAT>
                  <inv:homeCurrency><typ:unitPrice>-200</typ:unitPrice></inv:homeCurrency>
                </inv:invoiceItem>
                <inv:invoiceItem>
                  <inv:text>Doprava</inv:text>
                  <inv:quantity>1</inv:quantity>
                  <inv:unit>ks</inv:unit>
                  <inv:payVAT>false</inv:payVAT>
                  <inv:rateVAT>high</inv:rateVAT>
                  <inv:percentVAT>21</inv:percentVAT>
                  <inv:homeCurrency><typ:unitPrice>100</typ:unitPrice></inv:homeCurrency>
                </inv:invoiceItem>
              </inv:invoiceDetail>
              <inv:invoiceSummary>
                <inv:homeCurrency>
                  <typ:priceHigh>{$recapBase}</typ:priceHigh>
                  <typ:priceHighVAT>{$recapVat}</typ:priceHighVAT>
                </inv:homeCurrency>
              </inv:invoiceSummary>
            </inv:invoice>
          </dat:dataPackItem>
        </dat:dataPack>
        XML;

        $res = (new PohodaXmlParser())->parse($xml);
        self::assertNotEmpty($res['invoices']);
        self::assertArrayNotHasKey('__error', $res['invoices'][0]);

        return $res['invoices'][0];
    }

    /** HLAVNÍ REGRESE — sedící doklad se slevovým řádkem nesmí hlásit rozpor. */
    public function testIsdocDiscountLineIsSubtractedNotAdded(): void
    {
        $invoice = $this->isdoc('900.00', '189.00');

        self::assertSame(
            [],
            $invoice['file_issues'],
            'Sleva −200 se sečetla v absolutní hodnotě: základ vyšel 1 300 místo 900 '
                . 'a doklad dostal hlášku o rozporu, který v souboru není.',
        );
    }

    /** Táž regrese na Pohoda cestě — pravidlo se nesmí rozejít mezi parsery. */
    public function testPohodaDiscountLineIsSubtractedNotAdded(): void
    {
        self::assertSame([], $this->pohoda('900.00', '189.00')['file_issues']);
    }

    /**
     * Druhá strana tvrzení: skutečný rozpor musí zůstat hlášený. Bez toho by
     * kontrolu šlo „opravit" tím, že přestane hlásit cokoli.
     */
    public function testRealMismatchIsStillReported(): void
    {
        foreach (['isdoc' => $this->isdoc('1300.00', '273.00'), 'pohoda' => $this->pohoda('1300.00', '273.00')] as $label => $invoice) {
            self::assertCount(1, $invoice['file_issues'], $label);
            self::assertStringContainsString('900,00', $invoice['file_issues'][0], $label);
            self::assertStringContainsString('1 300,00', $invoice['file_issues'][0], $label);
        }
    }
}
