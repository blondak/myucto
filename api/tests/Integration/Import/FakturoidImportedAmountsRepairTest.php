<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Import\FakturoidImportedAmountsRepair;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use PHPUnit\Framework\Attributes\Group;

/**
 * Oprava dokladů převzatých z Fakturoidu před #128 a #131 (`fix-fakturoid-imported-amounts.php`).
 * Stav „před opravou" se vyrobí z dnešního importu vrácením toho, co dřív chybělo
 * (režim cen, rekapitulace, zaokrouhlení), a přepočtem.
 */
#[Group('integration')]
final class FakturoidImportedAmountsRepairTest extends FakturoidImportTestCase
{
    protected function tearDown(): void
    {
        if (isset($this->db) && $this->sid > 0) {
            $this->db->pdo()->prepare('DELETE FROM tax_submissions WHERE supplier_id = ?')->execute([$this->sid]);
            $this->db->pdo()->prepare('DELETE FROM invoice_payments WHERE supplier_id = ?')->execute([$this->sid]);
        }
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->useVatPayer(true);
        $this->api['subjects.json'] = [$this->subject(810001), $this->subject(810002, 'supplier')];
    }

    public function testRepairsGrossPricesAndReceivedRoundingAfterDryRun(): void
    {
        $gross = [
            'vat_price_mode' => 'from_total_with_vat',
            'vat_rates_summary' => [['vat_rate' => 21, 'base' => '1000.0', 'vat' => '210.0']],
        ];
        $line = [['name' => 'Licence', 'quantity' => 1, 'unit_name' => 'ks', 'unit_price' => '1210.0', 'vat_rate' => 21]];
        $this->api['invoices.json'] = [$this->document(820001, 810001, $line, $gross + ['status' => 'paid', 'paid_on' => '2094-03-15'])];
        $this->api['expenses.json'] = [
            $this->document(830001, 810002, $line, $gross),
            $this->document(830002, 810002, [
                ['name' => 'Položka A', 'quantity' => 1, 'unit_price' => '10.13', 'vat_rate' => 21],
                ['name' => 'Položka B', 'quantity' => 1, 'unit_price' => '10.13', 'vat_rate' => 21],
            ], [
                'vat_price_mode' => 'without_vat',
                'vat_rates_summary' => [['vat_rate' => 21, 'base' => '20.26', 'vat' => '4.25']],
                'rounding_adjustment' => '-0.01',
            ]),
        ];
        $this->runImport();
        $this->revertToPreFixState();

        self::assertSame(['1210.00', '254.10', '1464.10'], $this->totals('invoices', 820001));
        self::assertSame('1464.10', $this->paidAmount(820001));

        $repair = $this->container->get(FakturoidImportedAmountsRepair::class);
        $dry = $repair->run($this->sid, $this->api['invoices.json'], $this->api['expenses.json'], false);
        self::assertCount(3, $dry['changed']);
        self::assertSame([], $dry['review']);
        self::assertSame(['1210.00', '254.10', '1464.10'], $this->totals('invoices', 820001), 'Výpis nesmí nic zapsat.');

        $applied = $repair->run($this->sid, $this->api['invoices.json'], $this->api['expenses.json'], true);
        self::assertCount(3, $applied['changed']);
        self::assertSame(['1000.00', '210.00', '1210.00'], $this->totals('invoices', 820001));
        self::assertSame('1210.00', $this->paidAmount(820001), 'Úhrada převzatá importem se posune s dokladem.');
        self::assertSame(['1000.00', '210.00', '1210.00'], $this->totals('purchase_invoices', 830001));
        $h = $this->received(830002)['header'];
        self::assertSame(['20.26', '4.25', '24.51', '-0.01'], [$h['total_without_vat'], $h['total_vat'], $h['total_with_vat'], $h['rounding']]);

        $again = $repair->run($this->sid, $this->api['invoices.json'], $this->api['expenses.json'], true);
        self::assertSame([[], [], 3], [$again['changed'], $again['review'], $again['unchanged']], 'Opravený doklad se podruhé nemění.');
    }

    public function testLockedOrFiledDocumentIsOnlyReported(): void
    {
        $gross = [
            'vat_price_mode' => 'from_total_with_vat',
            'vat_rates_summary' => [['vat_rate' => 21, 'base' => '1000.0', 'vat' => '210.0']],
        ];
        $line = [['name' => 'Licence', 'quantity' => 1, 'unit_price' => '1210.0', 'vat_rate' => 21]];
        $this->api['invoices.json'] = [$this->document(820011, 810001, $line, $gross)];
        $this->api['expenses.json'] = [$this->document(830011, 810002, $line, $gross)];
        $this->runImport();
        $this->revertToPreFixState();

        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE invoices SET booked_at = NOW() WHERE supplier_id = ?')->execute([$this->sid]);
        $pdo->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, status, xml_content, xml_sha256, xml_size_bytes)
             VALUES (?, 'DPHDP3', 2094, 3, 'submitted', '<x/>', ?, 4)"
        )->execute([$this->sid, str_repeat('0', 64)]);

        $report = $this->container->get(FakturoidImportedAmountsRepair::class)
            ->run($this->sid, $this->api['invoices.json'], $this->api['expenses.json'], true);

        self::assertSame([], $report['changed']);
        self::assertCount(2, $report['review']);
        self::assertContains('booked', $report['review'][0]['blocked']);
        self::assertContains('vat_filed', $report['review'][1]['blocked']);
        self::assertSame(['1210.00', '254.10', '1464.10'], $this->totals('invoices', 820011));
        self::assertSame(['1210.00', '254.10', '1464.10'], $this->totals('purchase_invoices', 830011));
    }

    /** Doklady bez DPH tool nehlásí ani nepřepočítává. */
    public function testNonVatDocumentsAreLeftAlone(): void
    {
        $this->useVatPayer(false);
        $this->api['invoices.json'] = [
            $this->document(820021, 810001, [['name' => 'Služba', 'quantity' => 2, 'unit_price' => 750.25, 'vat_rate' => 0], ['name' => 'Bez sazby', 'quantity' => 1, 'unit_price' => 120]], ['vat_price_mode' => null]),
            $this->document(820022, 810001, [['name' => 'Školení', 'quantity' => 1, 'unit_price' => 1500.5, 'vat_rate' => 0]], [
                'vat_price_mode' => 'without_vat', 'round_total' => true,
                'vat_rates_summary' => [['vat_rate' => 0, 'base' => '1500.5', 'vat' => '0.0']], 'total' => '1501.0',
            ]),
        ];
        $this->api['expenses.json'] = [
            $this->document(830021, 810002, [['name' => 'Materiál', 'quantity' => 2, 'unit_price' => 99.9, 'vat_rate' => 0]], [
                'vat_price_mode' => 'from_total_with_vat',
                'vat_rates_summary' => [['vat_rate' => 0, 'base' => '199.8', 'vat' => '0.0']],
            ]),
            $this->document(830022, 810002, [['name' => 'Úklid', 'quantity' => 3, 'unit_price' => 216.67]], ['vat_price_mode' => 'without_vat', 'vat_rates_summary' => []]),
        ];
        $this->runImport();
        $before = [$this->issued(820021), $this->issued(820022), $this->received(830021), $this->received(830022)];

        $report = $this->container->get(FakturoidImportedAmountsRepair::class)
            ->run($this->sid, $this->api['invoices.json'], $this->api['expenses.json'], true);

        self::assertSame([[], [], 4], [$report['changed'], $report['review'], $report['unchanged']]);
        self::assertSame($before, [$this->issued(820021), $this->issued(820022), $this->received(830021), $this->received(830022)]);
    }

    /** Stav, který vytvářel import před #128 a #131. */
    private function revertToPreFixState(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE invoices SET prices_include_vat = 0 WHERE supplier_id = ?')->execute([$this->sid]);
        $pdo->prepare('UPDATE purchase_invoices SET prices_include_vat = 0, vat_overrides = NULL, rounding = 0 WHERE supplier_id = ?')->execute([$this->sid]);
        foreach ($pdo->query('SELECT id FROM invoices WHERE supplier_id = ' . $this->sid)->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->container->get(InvoiceCalculator::class)->recompute((int) $id);
        }
        $pdo->prepare(
            "UPDATE invoice_payments p JOIN invoices i ON i.id = p.invoice_id SET p.amount = i.amount_to_pay WHERE i.supplier_id = ?"
        )->execute([$this->sid]);
        foreach ($pdo->query('SELECT id FROM purchase_invoices WHERE supplier_id = ' . $this->sid)->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->container->get(PurchaseInvoiceCalculator::class)->recompute((int) $id);
        }
    }

    /** @return list<string> */
    private function totals(string $table, int $fakturoidId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT total_without_vat, total_vat, total_with_vat FROM {$table} WHERE supplier_id = ? AND fakturoid_id = ?");
        $stmt->execute([$this->sid, $fakturoidId]);
        return array_values($stmt->fetch(\PDO::FETCH_ASSOC) ?: []);
    }

    private function paidAmount(int $fakturoidId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.amount FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id WHERE i.supplier_id = ? AND i.fakturoid_id = ?'
        );
        $stmt->execute([$this->sid, $fakturoidId]);
        return (string) $stmt->fetchColumn();
    }
}
