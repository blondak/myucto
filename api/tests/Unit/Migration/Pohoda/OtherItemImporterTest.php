<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\OtherItemImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaVat;
use PHPUnit\Framework\TestCase;

final class OtherItemImporterTest extends TestCase
{
    public function testOnlyOpenNonVatItemWithoutLiquidationIsCandidate(): void
    {
        $vat = $this->vat();
        $record = $this->record();
        self::assertSame(1200.0, OtherItemImporter::candidate($record, 'receivable', $vat)['amount']);
        self::assertSame(1200.0, OtherItemImporter::candidate($record, 'commitment', $vat)['amount']);

        $record['invoiceHeader']['liquidation']['amountHome'] = '800.00';
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $vat));
        $record['invoiceHeader']['liquidation']['amountHome'] = '1200.00';
        $record['liquidations']['liquidation'] = ['id' => '1'];
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $vat));
    }

    public function testVatAndForeignCurrencyNeverEnterOtherItems(): void
    {
        $vat = $this->vat();
        $record = $this->record();
        $record['invoiceHeader']['classificationVAT']['ids'] = 'DOMESTIC';
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $vat));
        $record['invoiceHeader']['classificationVAT']['ids'] = 'OUTSIDE';
        $record['invoiceSummary']['homeCurrency']['priceHighVAT'] = '252';
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $vat));
        $record['invoiceSummary']['homeCurrency']['priceHighVAT'] = '0';
        $record['invoiceSummary']['homeCurrency']['priceSum'] = '1452';
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $vat));
        $record['invoiceSummary']['homeCurrency']['priceSum'] = '1200';
        $record['invoiceDetail']['invoiceItem'] = ['homeCurrency' => ['price' => '1000', 'priceVAT' => '200']];
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $vat));
        unset($record['invoiceDetail']);
        $record['invoiceSummary']['foreignCurrency']['currency']['ids'] = 'EUR';
        self::assertNull(OtherItemImporter::candidate($record, 'receivable', $vat));
    }

    public function testOnlyExactTwoLinePostingOutsidePayrollAndTaxAccountsIsAccepted(): void
    {
        $receivable = [
            ['side' => 'debit', 'amount' => '1200.00', 'account_code' => '315.000'],
            ['side' => 'credit', 'amount' => '1200.00', 'account_code' => '602.000'],
        ];
        self::assertSame(['account' => '315.000', 'counter' => '602.000'],
            OtherItemImporter::postingLines($receivable, 'receivable', 1200.0));
        $payable = [
            ['side' => 'debit', 'amount' => '1200.00', 'account_code' => '518.000'],
            ['side' => 'credit', 'amount' => '1200.00', 'account_code' => '325.000'],
        ];
        self::assertSame(['account' => '325.000', 'counter' => '518.000'],
            OtherItemImporter::postingLines($payable, 'commitment', 1200.0));
        $receivable[1]['account_code'] = '331.000';
        self::assertNull(OtherItemImporter::postingLines($receivable, 'receivable', 1200.0));
        $receivable[1]['account_code'] = '602.000';
        self::assertNull(OtherItemImporter::postingLines($receivable, 'receivable', 1199.0));
        $receivable[] = ['side' => 'credit', 'amount' => '252.00', 'account_code' => '343.000'];
        self::assertNull(OtherItemImporter::postingLines($receivable, 'receivable', 1200.0));
    }

    private function vat(): PohodaVat
    {
        $make = \Closure::bind(static fn (array $classes): PohodaVat => new PohodaVat($classes), null, PohodaVat::class);
        return $make([
            'OUTSIDE' => ['lines' => [], 'name' => 'Mimo přiznání', 'section' => ''],
            'DOMESTIC' => ['lines' => [1, 2], 'name' => 'Tuzemsko', 'section' => ''],
        ]);
    }

    private function record(): array
    {
        return [
            'invoiceHeader' => [
                'number' => ['numberRequested' => 'OP25001'], 'date' => '2025-06-01',
                'dateDue' => '2025-06-15', 'text' => 'Nájem', 'classificationVAT' => ['ids' => 'OUTSIDE'],
                'liquidation' => ['amountHome' => '1200.00'],
            ],
            'invoiceSummary' => ['homeCurrency' => [
                'priceNone' => '1200.00', 'priceLow' => '0', 'priceLowVAT' => '0',
                'priceHigh' => '0', 'priceHighVAT' => '0', 'price3' => '0', 'price3VAT' => '0',
            ]],
        ];
    }
}
