<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Export\IsdocExporter;
use PHPUnit\Framework\TestCase;

/**
 * ISDOC konečné faktury uhrazené (zčásti i plně) NEDAŇOVOU zálohou (proformou).
 *
 * Daň se přiznává až na konečném dokladu → `AlreadyClaimed*` = 0, `Difference*` =
 * plná hodnota, odečet zálohy jen přes `PaidDepositsAmount`. Pojišťuje proti regresi,
 * kdy se záloha cpala do `AlreadyClaimedTaxInclusiveAmount` (vnitřně rozporné:
 * základ 0 vs částka s DPH = celá záloha).
 */
final class IsdocAdvanceDepositTest extends TestCase
{
    private const XSD = __DIR__ . '/../../../../xsd/isdoc-invoice-6.0.2.xsd';

    private IsdocExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new IsdocExporter(
            $this->createStub(InvoiceRepository::class),
            $this->createStub(Connection::class),
        );
    }

    public function testFullyPaidByDepositIsConsistent(): void
    {
        // 4,5 h × 336 = 1512 základ, DPH 21 % = 317,52, s DPH = 1829,52; záloha celá.
        $xml = $this->exporter->buildXml($this->invoice(
            advance: 1829.52,
            amountToPay: 0.0,
        ));

        if (is_file(self::XSD)) {
            $this->assertXsdValid($xml);
        }

        self::assertSame('0.00',    $this->one($xml, '//i:LegalMonetaryTotal/i:AlreadyClaimedTaxExclusiveAmount'));
        self::assertSame('0.00',    $this->one($xml, '//i:LegalMonetaryTotal/i:AlreadyClaimedTaxInclusiveAmount'));
        self::assertSame('1512.00', $this->one($xml, '//i:LegalMonetaryTotal/i:DifferenceTaxExclusiveAmount'));
        self::assertSame('1829.52', $this->one($xml, '//i:LegalMonetaryTotal/i:DifferenceTaxInclusiveAmount'));
        self::assertSame('1829.52', $this->one($xml, '//i:LegalMonetaryTotal/i:PaidDepositsAmount'));
        self::assertSame('0.00',    $this->one($xml, '//i:LegalMonetaryTotal/i:PayableAmount'));

        // TaxTotal deklaruje plnou DPH a per-sazba AlreadyClaimed = 0 (nedaňová záloha).
        self::assertSame('317.52',  $this->one($xml, '//i:TaxTotal/i:TaxAmount'));
        self::assertSame('0.00',    $this->one($xml, '//i:TaxTotal/i:TaxSubTotal/i:AlreadyClaimedTaxAmount'));
        self::assertSame('317.52',  $this->one($xml, '//i:TaxTotal/i:TaxSubTotal/i:DifferenceTaxAmount'));
    }

    public function testPartiallyPaidByDepositIsConsistent(): void
    {
        // Záloha 1000, zbývá doplatit 829,52.
        $xml = $this->exporter->buildXml($this->invoice(
            advance: 1000.00,
            amountToPay: 829.52,
        ));

        if (is_file(self::XSD)) {
            $this->assertXsdValid($xml);
        }

        self::assertSame('0.00',    $this->one($xml, '//i:LegalMonetaryTotal/i:AlreadyClaimedTaxInclusiveAmount'));
        self::assertSame('1829.52', $this->one($xml, '//i:LegalMonetaryTotal/i:DifferenceTaxInclusiveAmount'));
        self::assertSame('1000.00', $this->one($xml, '//i:LegalMonetaryTotal/i:PaidDepositsAmount'));
        self::assertSame('829.52',  $this->one($xml, '//i:LegalMonetaryTotal/i:PayableAmount'));

        // Konzistence: Payable = Difference(s DPH) − PaidDeposits = 1829,52 − 1000.
        self::assertSame(829.52, round(1829.52 - 1000.00, 2));
    }

    private function one(string $xml, string $expr): ?string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('i', 'http://isdoc.cz/namespace/2013');
        return $xp->query($expr)->item(0)?->textContent;
    }

    /** @return array<string,mixed> */
    private function invoice(float $advance, float $amountToPay): array
    {
        return [
            'id'               => 1,
            'invoice_type'     => 'invoice',
            'varsymbol'        => '2605025',
            'issue_date'       => '2026-05-31',
            'tax_date'         => '2026-05-31',
            'due_date'         => '2026-06-14',
            'currency'         => 'CZK',
            'exchange_rate'    => null,
            'reverse_charge'   => false,
            'project_number'   => null,
            'contract_number'  => null,
            'advance_paid_amount' => $advance,
            'amount_to_pay'    => $amountToPay,
            'supplier_snapshot' => [
                'ic' => '12345679', 'dic' => 'CZ12345679', 'company_name' => 'Vzorová firma s.r.o.',
                'street' => 'Kardinála Berana 1104/36', 'city' => 'Plzeň', 'zip' => '30100', 'country_iso2' => 'CZ',
            ],
            'client_snapshot' => [
                'ic' => '27140130', 'dic' => 'CZ27140130', 'company_name' => 'Studio Fialka',
                'street' => 'Nádražní 7', 'city' => 'Ostrava', 'zip' => '70030', 'country_iso2' => 'CZ',
            ],
            'bank_snapshot' => ['account_number' => '1000000005', 'bank_code' => '0100', 'bank_name' => 'Komerční banka'],
            'items' => [[
                'description' => 'Test', 'quantity' => 4.5, 'unit' => 'h', 'unit_price_without_vat' => 336.0,
                'vat_rate_snapshot' => 21.0, 'total_without_vat' => 1512.0, 'total_vat' => 317.52, 'total_with_vat' => 1829.52,
            ]],
            'vat_breakdown' => [['rate' => 21.0, 'base' => 1512.0, 'vat' => 317.52]],
            'totals' => ['without_vat' => 1512.0, 'with_vat' => 1829.52, 'rounding' => 0.0],
        ];
    }

    private function assertXsdValid(string $xml): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml));
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $dom->schemaValidate(self::XSD);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            $lines = array_map(static fn (\LibXMLError $e): string => sprintf('  [ř. %d] %s', $e->line, trim($e->message)), $errors);
            self::fail("ISDOC není validní vůči XSD:\n" . implode("\n", $lines));
        }
        self::assertTrue($ok);
    }
}
