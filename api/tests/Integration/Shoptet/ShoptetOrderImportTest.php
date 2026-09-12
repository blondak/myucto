<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Shoptet;

use MyInvoice\Service\Shoptet\ShoptetImportException;
use MyInvoice\Service\Shoptet\ShoptetOrderImportService;
use MyInvoice\Service\Shoptet\ShoptetSettingsService;
use MyInvoice\Service\Stock\SalesOrderException;
use MyInvoice\Service\Stock\SalesOrderInvoiceService;
use MyInvoice\Service\Stock\SalesOrderService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Import objednávek ze Shoptetu: náhled bez zápisu, idempotence podle kódu,
 * aktualizace jen rozpracovaných objednávek, párování katalogu, ceny s DPH,
 * ruční kontrola DPH, pojistka proti dvojí fakturaci a izolace firem.
 * Syntetická data (api/tests/Fixtures/Shoptet/orders.xml).
 */
#[Group('integration')]
final class ShoptetOrderImportTest extends StockTestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/Shoptet/';

    private ShoptetOrderImportService $import;
    private ShoptetSettingsService $settings;
    private SalesOrderService $orders;
    private SalesOrderInvoiceService $orderInvoices;

    private int $sid = 0;
    private int $warehouseId = 0;
    private int $shirtId = 0;
    private int $mugId = 0;
    private int $b2bClientId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->import = $this->container->get(ShoptetOrderImportService::class);
        $this->settings = $this->container->get(ShoptetSettingsService::class);
        $this->orders = $this->container->get(SalesOrderService::class);
        $this->orderInvoices = $this->container->get(SalesOrderInvoiceService::class);

        $this->sid = $this->createSupplier();
        $this->warehouseId = $this->warehouse($this->sid);
        $this->shirtId = $this->item($this->sid, 'SHOP-TRIKO-M');
        $this->mugId = $this->item($this->sid, 'HRNEK-01');
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE stock_items SET ean = ?, vat_rate_id = ? WHERE id = ?')->execute(['8590000000017', $this->vatRateId, $this->mugId]);
        $pdo->prepare('UPDATE stock_items SET vat_rate_id = ? WHERE id = ?')->execute([$this->vatRateId, $this->shirtId]);
        $this->b2bClientId = $this->client($this->sid, 'Testovací odběratel s.r.o.');
        $pdo->prepare('UPDATE clients SET ic = ?, dic = ? WHERE id = ?')->execute(['25596641', 'CZ25596641', $this->b2bClientId]);
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "EUR", "EUR", "€", "euro", "euro", 2, 1, 0)'
        )->execute([$this->sid]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            foreach ($this->supplierIds as $supplierId) {
                $this->db->pdo()->prepare('DELETE FROM fulfillment_tasks WHERE supplier_id = ?')->execute([$supplierId]);
                $this->db->pdo()->prepare('DELETE FROM sales_orders WHERE supplier_id = ?')->execute([$supplierId]);
            }
        }
        parent::tearDown();
    }

    private function xml(): string
    {
        return (string) file_get_contents(self::FIXTURES . 'orders.xml');
    }

    /** @return array<string,mixed> */
    private function importXml(string $xml, ?int $sid = null): array
    {
        $sid ??= $this->sid;
        $preview = $this->import->previewUpload($sid, $xml, 'orders.xml', $this->userId);

        return $this->import->apply($sid, (int) $preview['id'], $this->userId);
    }

    /** @return array<string,mixed> */
    private function order(string $code): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM sales_orders WHERE supplier_id = ? AND external_source = 'shoptet' AND external_id = ?");
        $stmt->execute([$this->sid, $code]);
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id, "Objednávka $code nevznikla.");

        return $this->orders->detail($this->sid, (int) $id) ?? [];
    }

    /** @return array<string,mixed> */
    private function meta(int $orderId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM shoptet_orders WHERE order_id = ?');
        $stmt->execute([$orderId]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function countRows(string $sql, array $args): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($args);

        return (int) $stmt->fetchColumn();
    }

    public function testPreviewWritesNothingAndApplyCreatesOrders(): void
    {
        $preview = $this->import->previewUpload($this->sid, $this->xml(), 'orders.xml', $this->userId);

        self::assertSame('preview', $preview['status']);
        self::assertSame(3, $preview['summary']['create']);
        self::assertSame(1, $preview['summary']['failed']);
        self::assertSame(1, $preview['summary']['review'], 'Objednávka do SK s cizí sazbou musí jít ke kontrole.');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM sales_orders WHERE supplier_id = ?', [$this->sid]),
            'Náhled nesmí nic založit.');
        $clientsBefore = $this->countRows('SELECT COUNT(*) FROM clients WHERE supplier_id = ?', [$this->sid]);

        $applied = $this->import->apply($this->sid, (int) $preview['id'], $this->userId);
        self::assertSame('applied', $applied['status']);
        self::assertSame(3, $applied['summary']['created']);
        self::assertSame(1, $applied['summary']['failed']);
        self::assertNull($this->payload((int) $preview['id']), 'Po zápisu se obsah dávky (osobní údaje) maže.');

        $first = $this->order('2026000101');
        self::assertSame('shoptet', $first['external_source']);
        self::assertTrue((bool) $first['prices_include_vat'], 'Shoptet pracuje s cenami s DPH.');
        self::assertSame('1009.00', number_format((float) $first['total_with_vat'], 2, '.', ''));
        self::assertCount(5, $first['lines']);
        self::assertSame($this->shirtId, (int) $first['lines'][0]['stock_item_id'], 'Párování podle kódu.');
        self::assertSame($this->warehouseId, (int) $first['lines'][0]['warehouse_id']);
        self::assertSame($this->mugId, (int) $first['lines'][1]['stock_item_id'], 'Neznámý kód → párování podle EAN.');
        self::assertNull($first['lines'][2]['stock_item_id'], 'Doprava je neskladový řádek.');
        self::assertStringStartsWith('Doprava', (string) $first['lines'][2]['description']);
        self::assertSame('-50.000000', (string) $first['lines'][4]['unit_price'], 'Sleva zůstává záporným řádkem.');
        self::assertSame(0, (int) $this->meta((int) $first['id'])['review_required']);

        $b2b = $this->order('2026000102');
        self::assertSame($this->b2bClientId, (int) $b2b['client_id'], 'Existující odběratel se najde podle IČO.');
        self::assertNull($b2b['lines'][0]['stock_item_id'], 'Nespárovaná položka je textový řádek.');

        $foreign = $this->order('2026000103');
        self::assertSame('EUR', $foreign['currency_code']);
        self::assertEqualsWithDelta(25.10, (float) $foreign['exchange_rate'], 0.00001);
        $meta = $this->meta((int) $foreign['id']);
        self::assertSame(1, (int) $meta['review_required']);
        $snapshot = json_decode((string) $meta['vat_snapshot'], true);
        self::assertSame('SK', $snapshot['delivery_country']);
        self::assertSame([23.0], $snapshot['rates']);

        self::assertSame($clientsBefore + 2, $this->countRows('SELECT COUNT(*) FROM clients WHERE supplier_id = ?', [$this->sid]),
            'Vznikají jen dva noví zákazníci (spotřebitelé); B2B se znovu nezakládá.');
    }

    public function testReimportIsIdempotentAndLockedOrdersAreNotOverwritten(): void
    {
        $this->importXml($this->xml());
        $again = $this->importXml($this->xml());
        self::assertSame(3, $again['summary']['unchanged']);
        self::assertSame(3, $this->countRows("SELECT COUNT(*) FROM sales_orders WHERE supplier_id = ? AND external_source = 'shoptet'", [$this->sid]));

        $b2b = $this->order('2026000102');
        $this->db->pdo()->prepare("UPDATE sales_orders SET commercial_status = 'cancelled' WHERE id = ?")->execute([(int) $b2b['id']]);
        $first = $this->order('2026000101');
        $invoiceId = $this->invoiceDraft($this->sid, (int) $first['client_id']);
        $this->db->pdo()->prepare('INSERT INTO sales_order_invoice_links (supplier_id, order_id, invoice_id) VALUES (?, ?, ?)')
            ->execute([$this->sid, (int) $first['id'], $invoiceId]);

        $changed = str_replace(
            ['<AMOUNT>3</AMOUNT>', '<TOTAL_PRICE><WITH_VAT>363.00</WITH_VAT></TOTAL_PRICE>', '<STATUS>Vyřizuje se</STATUS>', '<TOTAL_PRICE><WITH_VAT>14.76</WITH_VAT></TOTAL_PRICE>'],
            ['<AMOUNT>4</AMOUNT>', '<TOTAL_PRICE><WITH_VAT>484.00</WITH_VAT></TOTAL_PRICE>', '<STATUS>Vyřízena</STATUS>', '<TOTAL_PRICE><WITH_VAT>29.52</WITH_VAT></TOTAL_PRICE>'],
            $this->xml(),
        );
        $changed = str_replace('<AMOUNT>1</AMOUNT>
        <CODE>SHOP-TRIKO-M</CODE>
        <UNIT_PRICE><WITH_VAT>14.76</WITH_VAT>', '<AMOUNT>2</AMOUNT>
        <CODE>SHOP-TRIKO-M</CODE>
        <UNIT_PRICE><WITH_VAT>14.76</WITH_VAT>', $changed);
        $report = $this->importXml($changed);
        $byCode = array_column($report['report'], null, 'code');

        self::assertSame('conflict', $byCode['2026000101']['status'], 'Vyfakturovaná objednávka se nepřepisuje.');
        self::assertSame('conflict', $byCode['2026000102']['status'], 'Stornovaná objednávka se nepřepisuje.');
        self::assertSame('updated', $byCode['2026000103']['status'], 'Rozpracovaná objednávka se aktualizuje.');
        self::assertSame(1, (int) $this->meta((int) $first['id'])['pending_change']);
        self::assertSame('3.000', (string) $this->order('2026000102')['lines'][0]['quantity'], 'Řádky zamčené objednávky zůstaly.');
        self::assertSame('2.000', (string) $this->order('2026000103')['lines'][0]['quantity']);
    }

    public function testShoptetModeBlocksInvoicingAndReviewFlagBlocksUntilConfirmed(): void
    {
        $this->receiveStock($this->sid, $this->warehouseId, $this->shirtId, '10', 50.0, date('Y-m-d', strtotime('-1 day')));
        $this->receiveStock($this->sid, $this->warehouseId, $this->mugId, '10', 50.0, date('Y-m-d', strtotime('-1 day')));
        $this->importXml($this->xml());
        $first = $this->order('2026000101');
        $foreign = $this->order('2026000103');
        $this->orders->confirm($this->sid, (int) $first['id'], 'test-confirm-1');
        $this->orders->confirm($this->sid, (int) $foreign['id'], 'test-confirm-3');

        $this->settings->save($this->sid, ['documents_issuer' => 'shoptet'], $this->userId);
        try {
            $this->orderInvoices->createDraft($this->sid, (int) $first['id'], $this->userId, 'test-invoice-1');
            self::fail('V režimu „Doklady vystavuje Shoptet" nesmí MyÚčto z objednávky vystavit fakturu.');
        } catch (SalesOrderException $e) {
            self::assertSame('shoptet_documents_issued_by_shoptet', $e->errorCode);
        }
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM invoices WHERE supplier_id = ?', [$this->sid]));

        $this->settings->save($this->sid, ['documents_issuer' => 'myucto'], $this->userId);
        try {
            $this->orderInvoices->createDraft($this->sid, (int) $foreign['id'], $this->userId, 'test-invoice-3');
            self::fail('Objednávka čekající na kontrolu DPH se nesmí vyfakturovat.');
        } catch (SalesOrderException $e) {
            self::assertSame('shoptet_order_review_required', $e->errorCode);
        }

        $invoice = $this->orderInvoices->createDraft($this->sid, (int) $first['id'], $this->userId, 'test-invoice-1b');
        self::assertTrue((bool) $invoice['prices_include_vat'], 'Faktura z objednávky přebírá ceny s DPH.');

        $this->import->markReviewed($this->sid, (int) $foreign['id'], $this->userId);
        try {
            $this->orderInvoices->createDraft($this->sid, (int) $foreign['id'], $this->userId, 'test-invoice-3b');
        } catch (SalesOrderException $e) {
            self::assertNotSame('shoptet_order_review_required', $e->errorCode, 'Po potvrzení kontroly už pojistka nebrání.');
        }
    }

    public function testBatchesAreIsolatedBetweenCompanies(): void
    {
        $preview = $this->import->previewUpload($this->sid, $this->xml(), 'orders.xml', $this->userId);
        $other = $this->createSupplier();

        try {
            $this->import->apply($other, (int) $preview['id'], $this->userId);
            self::fail('Cizí firma nesmí zapsat dávku jiné firmy.');
        } catch (ShoptetImportException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        self::assertNull($this->import->batch($other, (int) $preview['id']));
        self::assertSame([], $this->import->batches($other));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM sales_orders WHERE supplier_id = ?', [$other]));
    }

    public function testEraseDeletesOnlyOrdersWithoutFollowUp(): void
    {
        $batch = $this->importXml($this->xml());
        $first = $this->order('2026000101');
        $invoiceId = $this->invoiceDraft($this->sid, (int) $first['client_id']);
        $this->db->pdo()->prepare('INSERT INTO sales_order_invoice_links (supplier_id, order_id, invoice_id) VALUES (?, ?, ?)')
            ->execute([$this->sid, (int) $first['id'], $invoiceId]);

        $result = $this->import->erase($this->sid, (int) $batch['id']);

        self::assertSame(2, $result['deleted']);
        self::assertSame('2026000101', $result['skipped'][0]['code']);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM sales_orders WHERE supplier_id = ? AND external_source = 'shoptet'", [$this->sid]));
    }

    public function testNegativePriceIsRejectedOnStockLine(): void
    {
        $clientId = $this->client($this->sid);
        try {
            $this->orders->create($this->sid, [
                'client_id' => $clientId,
                'currency_id' => $this->currencyIdFor($this->sid),
                'lines' => [[
                    'stock_item_id' => $this->shirtId, 'warehouse_id' => $this->warehouseId,
                    'quantity' => '1', 'unit_price' => '-10', 'vat_rate_id' => $this->vatRateId,
                ]],
            ], $this->userId);
            self::fail('Skladový řádek se zápornou cenou je chyba.');
        } catch (SalesOrderException $e) {
            self::assertSame('money_invalid', $e->errorCode);
        }
    }

    /**
     * FAIL-BEFORE: L7 — záporná cena u neskladového řádku (sleva z e-shopu) pustila
     * v obecném API objednávek i objednávku srazenou slevami na nulu nebo pod ni.
     */
    public function testOrderDiscountedToZeroOrBelowIsRejected(): void
    {
        $clientId = $this->client($this->sid);
        foreach (['-100', '-150'] as $coupon) {
            try {
                $this->orders->create($this->sid, [
                    'client_id' => $clientId,
                    'currency_id' => $this->currencyIdFor($this->sid),
                    'lines' => [
                        ['description' => 'Služba', 'quantity' => '1', 'unit_price' => '100', 'vat_rate_id' => $this->vatRateId],
                        ['description' => 'Slevový kupón', 'quantity' => '1', 'unit_price' => $coupon, 'vat_rate_id' => $this->vatRateId],
                    ],
                ], $this->userId);
                self::fail('Objednávka srazená slevou na nulu nebo pod ni není prodej.');
            } catch (SalesOrderException $e) {
                self::assertSame('total_not_positive', $e->errorCode);
            }
        }

        $ok = $this->orders->create($this->sid, [
            'client_id' => $clientId,
            'currency_id' => $this->currencyIdFor($this->sid),
            'lines' => [
                ['description' => 'Služba', 'quantity' => '1', 'unit_price' => '100', 'vat_rate_id' => $this->vatRateId],
                ['description' => 'Slevový kupón', 'quantity' => '1', 'unit_price' => '-40', 'vat_rate_id' => $this->vatRateId],
            ],
        ], $this->userId);
        self::assertSame('60.00', number_format((float) $ok['total_without_vat'], 2, '.', ''), 'Sleva nižší než objednávka projde.');
    }

    /**
     * FAIL-BEFORE: M2 — kurzor automatického stahování se posunul i za dávku, ve které
     * objednávka selhala. Shoptet pak posílá jen změny od kurzoru, takže by se selhaná
     * objednávka už nikdy nenačetla.
     */
    public function testCursorIsHeldWhileAnyOrderFails(): void
    {
        $failing = $this->import->previewUpload($this->sid, $this->xml(), 'orders.xml', $this->userId);
        $this->markFetched((int) $failing['id'], '2026-09-01 12:00:00');
        $applied = $this->import->apply($this->sid, (int) $failing['id'], $this->userId);

        self::assertSame(1, (int) $applied['summary']['failed']);
        self::assertSame(1, (int) ($applied['summary']['cursor_held'] ?? 0), 'Dávka musí říct, že kurzor zůstal stát.');
        self::assertNull($this->settings->get($this->sid)['fetch_cursor'], 'Kurzor se za dávku s chybou nesmí posunout.');

        $clean = (string) preg_replace('~\s*<ORDER>\s*<DATE>2026-09-01 13:00:00</DATE>.*?</ORDER>~s', '', $this->xml());
        $ok = $this->import->previewUpload($this->sid, $clean, 'orders.xml', $this->userId);
        $this->markFetched((int) $ok['id'], '2026-09-01 12:30:00');
        $applied = $this->import->apply($this->sid, (int) $ok['id'], $this->userId);

        self::assertSame(0, (int) $applied['summary']['failed']);
        self::assertArrayNotHasKey('cursor_held', $applied['summary']);
        self::assertSame('2026-09-01 12:28:00', substr((string) $this->settings->get($this->sid)['fetch_cursor'], 0, 19));
    }

    /** Dávku z nahraného souboru převlékne za stažení z odkazu (to jediné posouvá kurzor). */
    private function markFetched(int $batchId, string $fetchedAt): void
    {
        $this->db->pdo()->prepare("UPDATE shoptet_import_batches SET source = 'url', fetched_at = ? WHERE id = ?")
            ->execute([$fetchedAt, $batchId]);
    }

    private function payload(int $batchId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT payload FROM shoptet_import_batches WHERE id = ?');
        $stmt->execute([$batchId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
