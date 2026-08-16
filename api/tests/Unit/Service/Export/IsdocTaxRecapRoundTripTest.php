<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Import\IsdocParser;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip rekapitulace DPH: IsdocExporter zapíše <TaxTotal>/<TaxSubTotal>,
 * výsledek je validní proti isdoc-invoice-6.0.2.xsd a IsdocParser z něj přečte
 * `vat_recap` shodný s původním rozpadem po sazbách. Pojišťuje, že export i nové
 * čtení (PurchaseVatRecapSeeder zdroj) drží stejnou strukturu.
 */
final class IsdocTaxRecapRoundTripTest extends TestCase
{
    private const XSD = __DIR__ . '/../../../../xsd/isdoc-invoice-6.0.2.xsd';

    private IsdocExporter $exporter;

    protected function setUp(): void
    {
        if (!is_file(self::XSD)) {
            self::markTestSkipped('ISDOC XSD chybí — spusť cmd/download-xsd.sh isdoc.');
        }
        $this->exporter = new IsdocExporter(
            $this->createStub(InvoiceRepository::class),
            $this->createStub(Connection::class),
        );
    }

    public function testMultiRateTaxTotalIsXsdValidAndRoundTrips(): void
    {
        $xml = $this->exporter->buildXml($this->multiRateInvoice());

        // 1) XSD validita celého dokladu (vč. vícesazbového TaxTotal).
        $this->assertXsdValid($xml);

        // 2) Reimport — vat_recap musí sedět na původní rozpad po sazbách.
        $parsed = (new IsdocParser())->parse($xml);
        $recap = $parsed['invoices'][0]['vat_recap'];

        self::assertSame(['base' => 10000.00, 'vat' => 2100.00], $recap['21.00']);
        self::assertSame(['base' => 3000.00, 'vat' => 360.00], $recap['12.00']);
    }

    /** @return array<string,mixed> */
    private function multiRateInvoice(): array
    {
        return [
            'id'               => 1,
            'invoice_type'     => 'invoice',
            'varsymbol'        => '2026010',
            'issue_date'       => '2026-05-04',
            'tax_date'         => '2026-05-04',
            'due_date'         => '2026-05-18',
            'currency'         => 'CZK',
            'exchange_rate'    => null,
            'reverse_charge'   => false,
            'project_number'   => null,
            'contract_number'  => null,
            'advance_paid_amount' => 0.0,
            'amount_to_pay'    => 15460.0,
            'supplier_snapshot' => [
                'ic' => '01698401', 'dic' => 'CZ01698401', 'company_name' => 'Dodavatel s.r.o.',
                'street' => 'Zkušební 123/4', 'city' => 'Vzorov', 'zip' => '10000', 'country_iso2' => 'CZ',
            ],
            'client_snapshot' => [
                'ic' => '27140130', 'dic' => 'CZ27140130', 'company_name' => 'Odběratel a.s.',
                'street' => 'Václavské náměstí 1', 'city' => 'Praha 1', 'zip' => '11000', 'country_iso2' => 'CZ',
            ],
            'bank_snapshot' => ['account_number' => '1000000005', 'bank_code' => '0100', 'bank_name' => 'Komerční banka'],
            'items' => [
                [
                    'description' => 'Vývoj', 'quantity' => 10.0, 'unit' => 'h', 'unit_price_without_vat' => 1000.0,
                    'vat_rate_snapshot' => 21.0, 'total_without_vat' => 10000.0, 'total_vat' => 2100.0, 'total_with_vat' => 12100.0,
                ],
                [
                    'description' => 'Konzultace', 'quantity' => 2.0, 'unit' => 'h', 'unit_price_without_vat' => 1500.0,
                    'vat_rate_snapshot' => 12.0, 'total_without_vat' => 3000.0, 'total_vat' => 360.0, 'total_with_vat' => 3360.0,
                ],
            ],
            'vat_breakdown' => [
                ['rate' => 21.0, 'base' => 10000.0, 'vat' => 2100.0],
                ['rate' => 12.0, 'base' => 3000.0,  'vat' => 360.0],
            ],
            'totals' => ['without_vat' => 13000.0, 'with_vat' => 15460.0, 'rounding' => 0.0],
        ];
    }

    private function assertXsdValid(string $xml): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'Export není well-formed XML.');

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $dom->schemaValidate(self::XSD);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            $lines = array_map(
                static fn (\LibXMLError $e): string => sprintf('  [ř. %d] %s', $e->line, trim($e->message)),
                $errors,
            );
            self::fail("ISDOC XML není validní vůči isdoc-invoice-6.0.2.xsd:\n" . implode("\n", $lines));
        }
        self::assertTrue($ok);
    }
}
