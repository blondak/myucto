<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Shoptet;

use MyInvoice\Service\Shoptet\ShoptetImportException;
use MyInvoice\Service\Shoptet\ShoptetOrderCsvParser;
use MyInvoice\Service\Shoptet\ShoptetOrderFileParser;
use MyInvoice\Service\Shoptet\ShoptetOrderXmlParser;
use MyInvoice\Service\Shoptet\ShoptetValues;
use PHPUnit\Framework\TestCase;

/**
 * Parsery exportu objednávek Shoptetu nad syntetickými fixtures
 * (api/tests/Fixtures/Shoptet/orders.xml, orders.csv).
 */
final class ShoptetOrderParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../Fixtures/Shoptet/';

    private function parser(): ShoptetOrderFileParser
    {
        return new ShoptetOrderFileParser(new ShoptetOrderXmlParser(), new ShoptetOrderCsvParser());
    }

    public function testXmlExportIsNormalizedAndBrokenOrderIsReportedAlone(): void
    {
        $out = $this->parser()->parse((string) file_get_contents(self::FIXTURES . 'orders.xml'), 'orders.xml');

        self::assertSame('xml', $out['format']);
        self::assertSame(['2026000101', '2026000102', '2026000103'], array_column($out['orders'], 'code'));
        self::assertCount(1, $out['errors'], 'Objednávka bez kódu je chyba jednoho řádku, ne celého souboru.');
        self::assertStringContainsString('nemá kód', $out['errors'][0]['message']);

        $first = $out['orders'][0];
        self::assertSame('2026-09-01 10:15:00', $first['date']);
        self::assertSame('CZK', $first['currency']);
        self::assertSame('jana.testovaci@example.test', $first['email']);
        self::assertSame('Testovací 1', $first['billing']['street']);
        self::assertSame('CZ', $first['billing']['country'], 'Země se bere z COUNTRY_CODE, ne z lokalizovaného názvu.');
        self::assertNull($first['billing']['company_id']);
        self::assertSame(1009.0, $first['totals']['with_vat']);
        self::assertFalse($first['paid']);

        self::assertSame(['product', 'product', 'shipping', 'billing', 'discount'], array_column($first['items'], 'kind'));
        self::assertSame('SHOP-TRIKO-M', $first['items'][0]['code']);
        self::assertSame('M', $first['items'][0]['variant']);
        self::assertSame(2.0, $first['items'][0]['quantity']);
        self::assertSame(363.0, $first['items'][0]['unit_price_with_vat']);
        self::assertSame(21.0, $first['items'][0]['vat_rate']);
        self::assertSame('8590000000017', $first['items'][1]['ean']);
        self::assertSame(12.0, $first['items'][1]['vat_rate']);
        self::assertSame(-50.0, $first['items'][4]['total_with_vat'], 'Sleva zůstává záporným řádkem se svou sazbou.');

        $foreign = $out['orders'][2];
        self::assertSame('EUR', $foreign['currency']);
        self::assertSame(25.10, $foreign['exchange_rate'], 'Kurz s desetinnou čárkou se musí přečíst.');
        self::assertSame('SK', $foreign['delivery']['country']);
    }

    public function testXmlWithDoctypeIsRejected(): void
    {
        $this->expectException(ShoptetImportException::class);
        (new ShoptetOrderXmlParser())->parse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><ORDERS/>');
    }

    /**
     * FAIL-BEFORE: DOCTYPE v UTF-16 kontrola nad surovými bajty neviděla a export se
     * načetl i s deklarací entit.
     */
    public function testUtf16XmlWithDoctypeIsRejected(): void
    {
        $doc = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE ORDERS [<!ENTITY a "b">]>'
            . '<ORDERS><ORDER><CODE>X1</CODE><DATE>2026-09-01</DATE><CURRENCY><CODE>CZK</CODE></CURRENCY>'
            . '<ORDER_ITEMS><ITEM><TYPE>product</TYPE><NAME>A</NAME><AMOUNT>1</AMOUNT>'
            . '<UNIT_PRICE><WITH_VAT>10</WITH_VAT><VAT_RATE>21</VAT_RATE></UNIT_PRICE></ITEM></ORDER_ITEMS></ORDER></ORDERS>';
        $xml = "\xFF\xFE" . mb_convert_encoding($doc, 'UTF-16LE', 'UTF-8');
        self::assertDoesNotMatchRegularExpression('/<!DOCTYPE/i', $xml);

        try {
            (new ShoptetOrderXmlParser())->parse($xml);
            self::fail('XML s DOCTYPE v UTF-16 musí být odmítnuto.');
        } catch (ShoptetImportException $e) {
            self::assertSame('shoptet_xml_doctype', $e->errorCode);
        }
    }

    public function testItemWithoutVatRateIsAnOrderErrorNotAGuessedRate(): void
    {
        $xml = '<ORDERS><ORDER><CODE>X1</CODE><ORDER_ITEMS><ITEM><TYPE>product</TYPE><NAME>A</NAME>'
            . '<AMOUNT>1</AMOUNT><UNIT_PRICE><WITH_VAT>100</WITH_VAT></UNIT_PRICE></ITEM></ORDER_ITEMS></ORDER></ORDERS>';
        $out = (new ShoptetOrderXmlParser())->parse($xml);

        self::assertSame([], $out['orders']);
        self::assertStringContainsString('sazbu DPH', $out['errors'][0]['message']);
    }

    public function testCsvRowsAreGroupedByOrderCodeWithCzechNumbers(): void
    {
        $out = $this->parser()->parse((string) file_get_contents(self::FIXTURES . 'orders.csv'), 'orders.csv');

        self::assertSame('csv', $out['format']);
        self::assertSame(['2026000201', '2026000202'], array_column($out['orders'], 'code'));
        self::assertCount(1, $out['errors']);

        $first = $out['orders'][0];
        self::assertCount(2, $first['items'], 'Řádky se stejným kódem tvoří jednu objednávku.');
        self::assertSame(1331.0, $first['totals']['with_vat'], '„1 331,00" se čte jako 1331.');
        self::assertSame(1089.0, $first['items'][0]['total_with_vat']);
        self::assertSame('shipping', $first['items'][1]['kind']);
        self::assertSame('Zkušební 7', $first['billing']['street']);

        $second = $out['orders'][1];
        self::assertSame('25596641', $second['billing']['company_id']);
        self::assertSame('Testovací; odběratel s.r.o.', $second['billing']['company'], 'Uvozovky chrání oddělovač.');
        self::assertSame(12.0, $second['items'][0]['vat_rate'], 'Sazba „12 %" se musí přečíst.');
    }

    public function testCsvInWindows1250IsConverted(): void
    {
        $utf8 = (string) file_get_contents(self::FIXTURES . 'orders.csv');
        $cp1250 = (string) iconv('UTF-8', 'Windows-1250', $utf8);
        self::assertFalse(mb_check_encoding($cp1250, 'UTF-8'), 'Fixture musí být opravdu v cp1250.');

        $out = $this->parser()->parse($cp1250, 'orders.csv');

        self::assertSame('Tričko', $out['orders'][0]['items'][0]['name']);
        self::assertSame('Třeboň', $out['orders'][0]['billing']['city']);
    }

    public function testCsvWithoutCodeColumnIsRejectedWithClearMessage(): void
    {
        $this->expectException(ShoptetImportException::class);
        $this->expectExceptionMessage('code');
        (new ShoptetOrderCsvParser())->parse("datum;jmeno\n2026-01-01;A\n");
    }

    public function testValueHelpers(): void
    {
        self::assertSame(1234.5, ShoptetValues::decimal('1.234,50'));
        self::assertSame(1234.5, ShoptetValues::decimal('1,234.50'));
        self::assertNull(ShoptetValues::decimal('abc'));
        self::assertNull(ShoptetValues::decimal(''));
        self::assertSame('2026-09-01 00:00:00', ShoptetValues::dateTime('1. 9. 2026'));
        self::assertNull(ShoptetValues::dateTime('2026-02-31'));
        self::assertSame('discount', ShoptetValues::itemKind('volume-discount'));
        self::assertSame('product', ShoptetValues::itemKind('bazar'));
    }
}
