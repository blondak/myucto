<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Shoptet;

use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Service\Shoptet\ShoptetDocumentImportService;
use MyInvoice\Service\Shoptet\ShoptetImportException;
use MyInvoice\Service\Shoptet\ShoptetOrderImportService;
use MyInvoice\Service\Shoptet\ShoptetSettingsService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Import dokladů vystavených Shoptetem (ISDOC 6.0.2 jako hlavní cesta, XML Pohoda pro
 * zálohové faktury). Doklady musí skončit jako VYDANÉ faktury s číslem ze Shoptetu,
 * DPH po řádcích ve VAT ledgeru, nic jako přijatá faktura, a faktura se naváže na
 * importovanou objednávku. Syntetická data (api/tests/Fixtures/Shoptet/).
 */
#[Group('integration')]
final class ShoptetDocumentImportTest extends StockTestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/Shoptet/';
    private const SUPPLIER_IC = '12345679';

    private ShoptetDocumentImportService $shoptetDocuments;
    private ShoptetSettingsService $settings;
    private int $sid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shoptetDocuments = $this->container->get(ShoptetDocumentImportService::class);
        $this->settings = $this->container->get(ShoptetSettingsService::class);

        $this->sid = $this->createSupplier();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET ic = ?, dic = ? WHERE id = ?')->execute([self::SUPPLIER_IC, 'CZ' . self::SUPPLIER_IC, $this->sid]);
        $pdo->prepare(
            "INSERT IGNORE INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified)
             VALUES (?, '1900-01-01', 1, 0)"
        )->execute([$this->sid]);
        $clientId = $this->client($this->sid, 'Testovací odběratel s.r.o.');
        $pdo->prepare('UPDATE clients SET ic = ?, dic = ? WHERE id = ?')->execute(['25596641', 'CZ25596641', $clientId]);
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "EUR", "EUR", "€", "euro", "euro", 2, 1, 0)'
        )->execute([$this->sid]);
        $this->warehouse($this->sid);

        $orders = $this->container->get(ShoptetOrderImportService::class);
        $preview = $orders->previewUpload($this->sid, (string) file_get_contents(self::FIXTURES . 'orders.xml'), 'orders.xml', $this->userId);
        $orders->apply($this->sid, (int) $preview['id'], $this->userId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            foreach ($this->supplierIds as $supplierId) {
                $this->db->pdo()->prepare('DELETE FROM sales_orders WHERE supplier_id = ?')->execute([$supplierId]);
                $this->db->pdo()->prepare('DELETE FROM projects WHERE client_id IN (SELECT id FROM clients WHERE supplier_id = ?)')->execute([$supplierId]);
            }
        }
        parent::tearDown();
    }

    /** @return list<array{name:string,content:string}> */
    private function files(string ...$names): array
    {
        return array_map(
            static fn (string $n): array => ['name' => $n, 'content' => (string) file_get_contents(self::FIXTURES . $n)],
            $names,
        );
    }

    /** @return array<string,mixed> */
    private function invoiceByVs(string $vs): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->sid, $vs]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Doklad $vs nevznikl.");

        return $row;
    }

    private function scalar(string $sql, array $args): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($args);

        return $stmt->fetchColumn();
    }

    public function testImportIsRefusedWhileMyuctoIssuesDocuments(): void
    {
        try {
            $this->shoptetDocuments->import($this->sid, $this->files('isdoc-faktura.isdoc'), $this->userId);
            self::fail('V režimu „Doklady vystavuje MyÚčto" by import zaevidoval tržbu podruhé.');
        } catch (ShoptetImportException $e) {
            self::assertSame('shoptet_documents_mode_myucto', $e->errorCode);
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM invoices WHERE supplier_id = ?', [$this->sid]));
    }

    public function testShoptetDocumentsBecomeIssuedInvoicesWithVatAndOrderLink(): void
    {
        $this->settings->save($this->sid, ['documents_issuer' => 'shoptet'], $this->userId);

        $out = $this->shoptetDocuments->import(
            $this->sid,
            $this->files('isdoc-faktura.isdoc', 'isdoc-dobropis.isdoc', 'isdoc-ddpp.isdoc', 'pohoda-zalohova.xml'),
            $this->userId,
        );
        $reasons = implode(' | ', array_map(static fn (array $r): string => (string) ($r['reason'] ?? ''), $out['results']));
        self::assertSame(4, $out['summary']['created'], 'Všechny čtyři doklady musí vzniknout: ' . $reasons);
        self::assertSame(0, (int) $out['summary']['failed']);

        $invoice = $this->invoiceByVs('2026100001');
        self::assertSame('invoice', $invoice['invoice_type']);
        self::assertSame('1009.00', number_format((float) $invoice['total_with_vat'], 2, '.', ''), 'Součet řádků sedí na doklad Shoptetu.');
        self::assertSame('160.24', number_format((float) $invoice['total_vat'], 2, '.', ''));
        self::assertSame(5, (int) $this->scalar('SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ?', [(int) $invoice['id']]));
        self::assertSame('credit_note', $this->invoiceByVs('2026900001')['invoice_type']);
        self::assertSame('tax_document', $this->invoiceByVs('2026500001')['invoice_type'], 'DDPP je daňový doklad, ne záloha.');
        self::assertSame('proforma', $this->invoiceByVs('2026700001')['invoice_type']);

        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ?', [$this->sid]),
            'Vydané doklady Shoptetu se nesmí zaevidovat jako přijaté.');
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.supplier_id = ?', [$this->sid]
        ), 'Číslo objednávky nesmí založit zakázku.');

        $orderId = (int) $this->scalar("SELECT id FROM sales_orders WHERE supplier_id = ? AND external_id = '2026000101'", [$this->sid]);
        self::assertSame((int) $invoice['id'], (int) $this->scalar('SELECT invoice_id FROM sales_order_invoice_links WHERE order_id = ?', [$orderId]),
            'Faktura se naváže na importovanou objednávku podle čísla objednávky.');
        self::assertNull($invoice['project_id']);

        $ledger = $this->container->get(VatLedgerService::class)->rows($this->sid, '2026-09-01', '2026-09-30');
        $vatByInvoice = [];
        foreach ($ledger as $row) {
            if (($row['source'] ?? '') === 'sale') {
                $vatByInvoice[(int) $row['invoice_id']] = ($vatByInvoice[(int) $row['invoice_id']] ?? 0.0) + (float) ($row['vat'] ?? $row['vat_amount'] ?? 0);
            }
        }
        self::assertArrayHasKey((int) $invoice['id'], $vatByInvoice, 'Faktura ze Shoptetu musí být v evidenci DPH.');
        self::assertArrayHasKey((int) $this->invoiceByVs('2026500001')['id'], $vatByInvoice, 'DDPP musí být v evidenci DPH.');
        self::assertArrayNotHasKey((int) $this->invoiceByVs('2026700001')['id'], $vatByInvoice, 'Nedaňová záloha do evidence DPH nepatří.');
    }

    public function testReimportIsSkippedAndTotalMismatchIsReported(): void
    {
        $this->settings->save($this->sid, ['documents_issuer' => 'shoptet'], $this->userId);
        $this->shoptetDocuments->import($this->sid, $this->files('isdoc-faktura.isdoc'), $this->userId);

        $again = $this->shoptetDocuments->import($this->sid, $this->files('isdoc-faktura.isdoc'), $this->userId);
        self::assertSame(0, $again['summary']['created']);
        self::assertSame(1, (int) $again['summary']['skipped'] + (int) ($again['summary']['duplicates'] ?? 0));

        // Vlastní číslo objednávky: k objednávce 2026000101 už faktura je a druhou by
        // import odmítl dřív, než by došlo na kontrolu součtu.
        $mismatch = str_replace(
            ['<ID>2026100001</ID>', '<PayableAmount>1009.00</PayableAmount>', '<SalesOrderID>2026000101</SalesOrderID>'],
            ['<ID>2026100009</ID>', '<PayableAmount>1015.00</PayableAmount>', '<SalesOrderID>2026000199</SalesOrderID>'],
            (string) file_get_contents(self::FIXTURES . 'isdoc-faktura.isdoc'),
        );
        $out = $this->shoptetDocuments->import($this->sid, [['name' => 'rozdil.isdoc', 'content' => $mismatch]], $this->userId);
        self::assertSame('created', $out['results'][0]['status']);
        self::assertStringContainsString('liší', implode(' ', $out['results'][0]['warnings'] ?? []));
        self::assertSame('draft', $this->invoiceByVs('2026100009')['status'], 'Doklad s nesedícím součtem zůstane konceptem.');
    }

    /** @param array<string,mixed> $out @return array<string,mixed> */
    private static function resultFor(array $out, string $file): array
    {
        foreach ($out['results'] as $row) {
            if (str_starts_with((string) $row['file'], $file)) {
                return $row;
            }
        }
        self::fail("V reportu chybí řádek pro $file.");
    }

    /**
     * FAIL-BEFORE: H2 — konečná faktura, která odečítá zdaněnou zálohu, se převzala jako
     * vystavená s plnou tržbou i DPH. Spolu s importovaným DDPP se tak táž úplata zdanila
     * podruhé.
     */
    public function testFinalInvoiceDeductingTaxedDepositStaysDraftOutOfVat(): void
    {
        $this->settings->save($this->sid, ['documents_issuer' => 'shoptet'], $this->userId);

        $out = $this->shoptetDocuments->import($this->sid, $this->files('isdoc-ddpp.isdoc', 'isdoc-faktura-zalohy.isdoc'), $this->userId);

        $final = $this->invoiceByVs('2026100002');
        self::assertSame('invoice', $final['invoice_type']);
        self::assertSame('draft', $final['status'], 'Doklad s odpočtem záloh nesmí být vystavený.');
        self::assertNull($final['sent_at']);
        self::assertStringContainsString('odečítá zálohy', implode(' ', self::resultFor($out, 'isdoc-faktura-zalohy.isdoc')['warnings'] ?? []));

        $ledger = $this->container->get(VatLedgerService::class)->rows($this->sid, '2026-09-01', '2026-09-30');
        $ids = array_map(static fn (array $r): int => (int) ($r['invoice_id'] ?? 0), array_filter($ledger, static fn (array $r): bool => ($r['source'] ?? '') === 'sale'));
        self::assertNotContains((int) $final['id'], $ids, 'Koncept s odpočtem záloh nesmí do evidence DPH.');
        self::assertContains((int) $this->invoiceByVs('2026500001')['id'], $ids, 'DDPP v evidenci DPH zůstává.');
    }

    /**
     * FAIL-BEFORE: M1 — druhá faktura k objednávce, která už fakturu má, vznikla jako živý
     * doklad a import jen připsal poznámku (dvojí tržba).
     */
    public function testSecondInvoiceForInvoicedOrderIsRejectedButCreditNotePasses(): void
    {
        $this->settings->save($this->sid, ['documents_issuer' => 'shoptet'], $this->userId);
        $this->shoptetDocuments->import($this->sid, $this->files('isdoc-faktura.isdoc'), $this->userId);

        $second = str_replace('<ID>2026100001</ID>', '<ID>2026100003</ID>', (string) file_get_contents(self::FIXTURES . 'isdoc-faktura.isdoc'));
        $out = $this->shoptetDocuments->import(
            $this->sid,
            [['name' => 'druha-faktura.isdoc', 'content' => $second], ...$this->files('isdoc-dobropis.isdoc', 'isdoc-faktura.isdoc')],
            $this->userId,
        );

        $rejected = self::resultFor($out, 'druha-faktura.isdoc');
        self::assertSame('failed', $rejected['status']);
        self::assertStringContainsString('už má navázanou fakturu 2026100001', (string) $rejected['reason']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM invoices WHERE supplier_id = ? AND varsymbol = ?', [$this->sid, '2026100003']));
        self::assertSame('created', self::resultFor($out, 'isdoc-dobropis.isdoc')['status'], 'Dobropis k vyfakturované objednávce projde.');
        self::assertNotSame('failed', self::resultFor($out, 'isdoc-faktura.isdoc')['status'], 'Opakovaný import navázané faktury je duplicita, ne chyba.');
    }

    public function testTwoInvoicesForOneOrderInOneBatchKeepOnlyTheFirst(): void
    {
        $this->settings->save($this->sid, ['documents_issuer' => 'shoptet'], $this->userId);
        $second = str_replace('<ID>2026100001</ID>', '<ID>2026100004</ID>', (string) file_get_contents(self::FIXTURES . 'isdoc-faktura.isdoc'));

        $out = $this->shoptetDocuments->import(
            $this->sid,
            [...$this->files('isdoc-faktura.isdoc'), ['name' => 'druha-faktura.isdoc', 'content' => $second]],
            $this->userId,
        );

        self::assertSame('created', self::resultFor($out, 'isdoc-faktura.isdoc')['status']);
        $rejected = self::resultFor($out, 'druha-faktura.isdoc');
        self::assertSame('failed', $rejected['status']);
        self::assertStringContainsString('v téže dávce', (string) $rejected['reason']);
    }

    /**
     * FAIL-BEFORE: L6 — kódy objednávek se klíčovaly jen variabilním symbolem, takže
     * doklad se stejným symbolem přepsal DDPP jeho objednávku.
     */
    public function testDocumentsSharingVarsymbolKeepTheirOwnOrder(): void
    {
        $this->settings->save($this->sid, ['documents_issuer' => 'shoptet'], $this->userId);
        $sameVs = str_replace('<ID>2026100001</ID>', '<ID>2026500001</ID>', (string) file_get_contents(self::FIXTURES . 'isdoc-faktura.isdoc'));

        $out = $this->shoptetDocuments->import(
            $this->sid,
            [...$this->files('isdoc-ddpp.isdoc'), ['name' => 'faktura-stejny-vs.isdoc', 'content' => $sameVs]],
            $this->userId,
        );

        $ddpp = self::resultFor($out, 'isdoc-ddpp.isdoc');
        self::assertSame('created', $ddpp['status']);
        self::assertSame('2026000102', $ddpp['shoptet_order_code'] ?? null, 'DDPP patří k objednávce 2026000102, ne k objednávce faktury se stejným symbolem.');
    }
}
