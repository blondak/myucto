<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use PHPUnit\Framework\Attributes\Group;

/**
 * Doklady bez DPH (neplátce, sazba 0, chybějící sazba, prázdná rekapitulace) musí
 * z Fakturoidu vzniknout PŘESNĚ tak, jako vznikaly před opravou #128–#131. Očekávané
 * hodnoty jsou otisk chování verze 6.31.0 a test byl ověřen i proti ní.
 *
 * Jediná vědomá změna je převzetí `rounding_adjustment` u přijatého dokladu (#131),
 * ta má vlastní test a DPH ani sazbu doklad nedostane.
 */
#[Group('integration')]
final class FakturoidImportNonVatRegressionTest extends FakturoidImportTestCase
{
    public function testIssuedByNonPayerWithoutVatModeStaysUnchanged(): void
    {
        $this->useVatPayer(false);
        $this->api['subjects.json'] = [$this->subject(710001)];
        $this->api['invoices.json'] = [
            $this->document(720001, 710001, [
                ['name' => 'Konzultace', 'quantity' => 2, 'unit_name' => 'hod', 'unit_price' => 750.25, 'vat_rate' => 0],
                ['name' => 'Cestovné', 'quantity' => 1, 'unit_name' => 'ks', 'unit_price' => 120],
            ], ['vat_price_mode' => null, 'vat_rates_summary' => [], 'subtotal' => '1620.5', 'total' => '1620.5']),
            $this->document(720002, 710001, [
                ['name' => 'Školení', 'quantity' => 1, 'unit_name' => 'ks', 'unit_price' => 1500.5, 'vat_rate' => 0],
            ], [
                'vat_price_mode' => 'without_vat',
                'round_total' => true,
                'vat_rates_summary' => [['vat_rate' => 0, 'base' => '1500.5', 'vat' => '0.0']],
                'subtotal' => '1500.5',
                'total' => '1501.0',
            ]),
        ];

        $job = $this->runImport(['include_received' => false]);

        self::assertSame('completed', $job['status']);
        self::assertSame(0, $job['failed_count']);

        $a = $this->issued(720001);
        self::assertSame([
            'invoice_type' => 'invoice', 'prices_include_vat' => 0, 'rounding_mode' => 'auto',
            'total_without_vat' => '1620.50', 'total_vat' => '0.00', 'total_with_vat' => '1620.50', 'rounding' => '0.00',
        ], self::ints($a['header']));
        self::assertSame([
            ['quantity' => '2.000', 'unit_price_without_vat' => '750.250000', 'vat_rate_snapshot' => '0.00', 'total_without_vat' => '1500.50', 'total_vat' => '0.00', 'total_with_vat' => '1500.50'],
            ['quantity' => '1.000', 'unit_price_without_vat' => '120.000000', 'vat_rate_snapshot' => '0.00', 'total_without_vat' => '120.00', 'total_vat' => '0.00', 'total_with_vat' => '120.00'],
        ], $a['items']);

        $b = $this->issued(720002);
        self::assertSame([
            'invoice_type' => 'invoice', 'prices_include_vat' => 0, 'rounding_mode' => 'auto',
            'total_without_vat' => '1500.50', 'total_vat' => '0.00', 'total_with_vat' => '1500.50', 'rounding' => '0.00',
        ], self::ints($b['header']));
    }

    public function testReceivedFromNonPayerWithoutRateStaysUnchanged(): void
    {
        $this->useVatPayer(true);
        $this->api['subjects.json'] = [$this->subject(710011, 'supplier')];
        $this->api['expenses.json'] = [
            $this->document(730001, 710011, [
                ['name' => 'Pronájem', 'quantity' => 1, 'unit_name' => 'měs', 'unit_price' => 8000, 'vat_rate' => 0],
                ['name' => 'Úklid', 'quantity' => 3, 'unit_name' => 'hod', 'unit_price' => 216.67],
            ], ['vat_price_mode' => null, 'subtotal' => '8650.01', 'total' => '8650.01']),
            // Neplátce s režimem „z celkové ceny": bez sazby nemá cena s DPH co znamenat.
            $this->document(730002, 710011, [
                ['name' => 'Materiál', 'quantity' => 2, 'unit_name' => 'ks', 'unit_price' => 99.9, 'vat_rate' => 0],
            ], [
                'vat_price_mode' => 'from_total_with_vat',
                'vat_rates_summary' => [['vat_rate' => 0, 'base' => '199.8', 'vat' => '0.0']],
                'subtotal' => '199.8',
                'total' => '199.8',
            ]),
        ];

        $job = $this->runImport(['include_issued' => false]);

        self::assertSame('completed', $job['status']);
        self::assertSame(0, $job['failed_count']);

        $a = $this->received(730001);
        self::assertSame([
            'document_kind' => 'invoice', 'prices_include_vat' => 0,
            'total_without_vat' => '8650.01', 'total_vat' => '0.00', 'total_with_vat' => '8650.01', 'rounding' => '0.00',
            'vat_overrides' => null, 'extraction_warning' => null,
        ], self::ints($a['header']));
        self::assertSame([
            ['quantity' => '1.000', 'unit_price_without_vat' => '8000.000000', 'vat_rate_snapshot' => '0.00', 'total_without_vat' => '8000.00', 'total_vat' => '0.00', 'total_with_vat' => '8000.00'],
            ['quantity' => '3.000', 'unit_price_without_vat' => '216.670000', 'vat_rate_snapshot' => '0.00', 'total_without_vat' => '650.01', 'total_vat' => '0.00', 'total_with_vat' => '650.01'],
        ], $a['items']);

        $b = $this->received(730002);
        self::assertSame([
            'document_kind' => 'invoice', 'prices_include_vat' => 0,
            'total_without_vat' => '199.80', 'total_vat' => '0.00', 'total_with_vat' => '199.80', 'rounding' => '0.00',
            'vat_overrides' => null, 'extraction_warning' => null,
        ], self::ints($b['header']));
    }

    public function testNonPayerCompanyReceivedDocumentStaysUnchanged(): void
    {
        $this->useVatPayer(false);
        $this->api['subjects.json'] = [$this->subject(710021, 'supplier')];
        $this->api['expenses.json'] = [
            $this->document(730021, 710021, [
                ['name' => 'Kancelářské potřeby', 'quantity' => 1, 'unit_name' => 'ks', 'unit_price' => 349, 'vat_rate' => 0],
            ], ['vat_price_mode' => 'without_vat', 'vat_rates_summary' => [], 'total' => '349.0']),
        ];

        $job = $this->runImport(['include_issued' => false]);

        self::assertSame('completed', $job['status']);
        self::assertSame([
            'document_kind' => 'invoice', 'prices_include_vat' => 0,
            'total_without_vat' => '349.00', 'total_vat' => '0.00', 'total_with_vat' => '349.00', 'rounding' => '0.00',
            'vat_overrides' => null, 'extraction_warning' => null,
        ], self::ints($this->received(730021)['header']));
    }

    /** Dry-run bez DPH nic nezaloží a úloha nesmí skončit s chybami. */
    public function testDryRunOfNonVatDocumentsReportsNoProblem(): void
    {
        $this->useVatPayer(false);
        $this->api['subjects.json'] = [$this->subject(710031), $this->subject(710032, 'supplier')];
        $this->api['invoices.json'] = [
            $this->document(720031, 710031, [['name' => 'Služba', 'quantity' => 1, 'unit_price' => 500]], ['vat_price_mode' => null]),
        ];
        $this->api['expenses.json'] = [
            $this->document(730031, 710032, [['name' => 'Nákup', 'quantity' => 1, 'unit_price' => 250, 'vat_rate' => 0]], ['vat_price_mode' => 'without_vat']),
        ];

        $job = $this->runImport(['dry_run' => true]);

        self::assertSame('completed', $job['status']);
        self::assertSame(0, $job['failed_count']);
        $count = $this->db->pdo()->prepare(
            'SELECT (SELECT COUNT(*) FROM invoices WHERE supplier_id = ?) + (SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ?)'
        );
        $count->execute([$this->sid, $this->sid]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    /** @param array<string,mixed> $row */
    private static function ints(array $row): array
    {
        foreach (['prices_include_vat'] as $k) {
            if (array_key_exists($k, $row)) $row[$k] = (int) $row[$k];
        }
        return $row;
    }
}
