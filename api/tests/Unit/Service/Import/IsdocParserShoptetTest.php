<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Doklady z exportu Shoptetu (ISDOC 6.0.2 a XML Pohoda) projdou existujícími parsery
 * vydaných faktur. Syntetické fixtures v api/tests/Fixtures/Shoptet/.
 */
final class IsdocParserShoptetTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../Fixtures/Shoptet/';

    /** @return array<string,mixed> */
    private function isdoc(string $file): array
    {
        $out = (new IsdocParser())->parse((string) file_get_contents(self::FIXTURES . $file));
        self::assertArrayNotHasKey('__error', $out['invoices'][0], (string) ($out['invoices'][0]['__error'] ?? ''));

        return $out['invoices'][0];
    }

    /**
     * FAIL-BEFORE: DocumentType 5 (daňový zálohový list = doklad k přijaté platbě) se
     * četl jako `proforma`, tedy NEDAŇOVÁ záloha, a jeho DPH z evidence zmizela.
     * Týž kód zapisuje pro `tax_document` náš vlastní IsdocExporter.
     */
    public function testTaxAdvanceDocumentIsATaxDocumentNotAProforma(): void
    {
        $inv = $this->isdoc('isdoc-ddpp.isdoc');

        self::assertSame('tax_document', $inv['invoice_type']);
        self::assertSame(5, $inv['document_type_code']);
        self::assertSame(21.0, $inv['items'][0]['vat_rate']);
    }

    public function testNonTaxAdvanceStaysProforma(): void
    {
        $xml = str_replace('<DocumentType>5</DocumentType>', '<DocumentType>4</DocumentType>',
            (string) file_get_contents(self::FIXTURES . 'isdoc-ddpp.isdoc'));
        $out = (new IsdocParser())->parse($xml);

        self::assertSame('proforma', $out['invoices'][0]['invoice_type']);
    }

    public function testShoptetInvoiceCarriesOrderReferenceNumberAndPayableAmount(): void
    {
        $inv = $this->isdoc('isdoc-faktura.isdoc');

        self::assertSame('invoice', $inv['invoice_type']);
        self::assertSame('2026100001', $inv['varsymbol']);
        self::assertSame('2026000101', $inv['project_number'], 'Číslo objednávky Shoptetu je v OrderReference.');
        self::assertSame(1009.0, $inv['payable_amount']);
        self::assertSame('12345679', $inv['supplier']['ic']);
        self::assertSame([], $inv['file_issues'], 'Řádky a rekapitulace fixture musí souhlasit.');
        self::assertCount(5, $inv['items']);
        self::assertSame(-41.32, $inv['items'][4]['unit_price_without_vat'], 'Sleva zůstává záporným řádkem.');
    }

    public function testCreditNote(): void
    {
        $inv = $this->isdoc('isdoc-dobropis.isdoc');

        self::assertSame('credit_note', $inv['invoice_type']);
        self::assertSame('2026900001', $inv['varsymbol']);
    }

    public function testForeignCurrencyOssDocument(): void
    {
        $inv = $this->isdoc('isdoc-eur-oss.isdoc');

        self::assertSame('EUR', $inv['currency']);
        self::assertEqualsWithDelta(25.10, (float) $inv['exchange_rate'], 0.0001);
        self::assertSame('SK', $inv['client']['country_iso2']);
        self::assertSame(23.0, $inv['items'][0]['vat_rate']);
        self::assertEqualsWithDelta(12.0, (float) $inv['items'][0]['unit_price_without_vat'], 0.0001,
            'Jednotková cena v měně dokladu z LineExtensionAmountCurr, ne v Kč.');
        self::assertSame(14.76, $inv['payable_amount']);
    }

    public function testFinalInvoiceDeductingDepositsExposesTheDeduction(): void
    {
        $inv = $this->isdoc('isdoc-faktura-zalohy.isdoc');

        self::assertSame('invoice', $inv['invoice_type']);
        self::assertSame(484.0, $inv['monetary']['paid_deposits']);
        self::assertSame(484.0, $inv['monetary']['already_claimed']);
        self::assertSame(484.0, $inv['monetary']['total']);
        self::assertSame(0.0, $inv['monetary']['payable']);
        self::assertSame([['id' => '2026500001', 'varsymbol' => '2026500001']], $inv['taxed_deposits']);
    }

    public function testForeignCurrencyTotalsAreReadOnlyInDocumentCurrency(): void
    {
        $inv = $this->isdoc('isdoc-eur-oss.isdoc');

        self::assertSame(14.76, $inv['monetary']['payable']);
        self::assertNull($inv['monetary']['total'], 'Celková částka v Kč se s řádky v eurech nesmí porovnávat.');
    }

    /**
     * FAIL-BEFORE: kontrola DOCTYPE nad surovými bajty neviděla deklaraci v UTF-16
     * (nulové bajty mezi znaky), libxml ji přesto načetl.
     */
    public function testUtf16DoctypeIsRejectedInIsdoc(): void
    {
        $xml = self::utf16WithDoctype((string) file_get_contents(self::FIXTURES . 'isdoc-ddpp.isdoc'), 'Invoice');
        self::assertDoesNotMatchRegularExpression('/<!DOCTYPE/i', $xml, 'Předpoklad testu: surové bajty DOCTYPE neprozradí.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DOCTYPE/');
        (new IsdocParser())->parse($xml);
    }

    public function testUtf16DoctypeIsRejectedInPohoda(): void
    {
        $xml = self::utf16WithDoctype((string) file_get_contents(self::FIXTURES . 'pohoda-zalohova.xml'), 'dat:dataPack');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DOCTYPE/');
        (new PohodaXmlParser())->parse($xml);
    }

    private static function utf16WithDoctype(string $xml, string $root): string
    {
        $encoding = preg_match('/^\s*<\?xml[^>]*encoding="([^"]+)"/i', $xml, $m) === 1 ? strtoupper($m[1]) : 'UTF-8';
        $body = (string) preg_replace('/^\s*<\?xml[^>]*\?>/', '', $xml);
        if ($encoding !== 'UTF-8') {
            $body = (string) iconv($encoding, 'UTF-8', $body);
        }
        $doc = '<?xml version="1.0" encoding="UTF-16"?>' . "\n" . '<!DOCTYPE ' . $root . ' [<!ENTITY x "y">]>' . "\n" . $body;

        return "\xFF\xFE" . mb_convert_encoding($doc, 'UTF-16LE', 'UTF-8');
    }

    public function testShoptetPohodaAdvanceInvoice(): void
    {
        $out = (new PohodaXmlParser())->parse((string) file_get_contents(self::FIXTURES . 'pohoda-zalohova.xml'));
        $inv = $out['invoices'][0];

        self::assertSame('12345679', $out['supplier_ic']);
        self::assertSame('proforma', $inv['invoice_type']);
        self::assertSame('issued', $inv['direction']);
        self::assertSame('2026700001', $inv['varsymbol']);
        self::assertSame('2026000102', $inv['project_number']);
        self::assertSame('25596641', $inv['client']['ic']);
        self::assertCount(2, $inv['items']);
        self::assertSame(21.0, $inv['items'][0]['vat_rate']);
        self::assertSame([], $inv['file_issues']);
    }
}
