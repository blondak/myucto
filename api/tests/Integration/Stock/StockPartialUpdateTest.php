<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Stock\CycleCountService;
use MyInvoice\Service\Stock\PurchaseOrderService;
use MyInvoice\Service\Stock\SalesOrderService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Úprava skladových dokladů s neúplným tělem (#113): vynechaný klíč ponechá
 * uloženou hodnotu, explicitní null ji smaže.
 */
#[Group('integration')]
final class StockPartialUpdateTest extends StockTestCase
{
    /** @var list<int> */
    private array $salesOrderSuppliers = [];

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            foreach ($this->salesOrderSuppliers as $supplierId) {
                $this->db->pdo()->prepare('DELETE FROM sales_orders WHERE supplier_id = ?')->execute([$supplierId]);
            }
        }
        parent::tearDown();
    }

    public function testSalesOrderPartialUpdateKeepsOmittedHeaderAndLines(): void
    {
        $orders = $this->container->get(SalesOrderService::class);
        $sid = $this->createSupplier();
        $this->salesOrderSuppliers[] = $sid;
        $clientId = $this->client($sid);
        $order = $orders->create($sid, [
            'client_id' => $clientId,
            'currency_id' => $this->currencyIdFor($sid),
            'order_number' => 'TEST-SO-PARTIAL',
            'allocation_policy' => 'partial',
            'prices_include_vat' => true,
            'exchange_rate' => '25.5',
            'reservation_expires_at' => '2099-01-01 10:00:00',
            'shipping_snapshot' => ['carrier' => 'Testovací dopravce'],
            'discount_snapshot' => ['code' => 'SLEVA'],
            'lines' => [[
                'description' => 'Služba', 'quantity' => '1.000', 'unit_price' => '121.000000',
                'vat_rate_id' => $this->vatRateId,
            ]],
        ], $this->userId);
        $id = (int) $order['id'];
        $lineUuid = $order['lines'][0]['line_uuid'];
        $totalWithVat = $order['total_with_vat'];

        $updated = $orders->update($sid, $id, 1, ['allocation_policy' => 'all_or_nothing']);

        self::assertSame('all_or_nothing', $updated['allocation_policy']);
        self::assertTrue($updated['prices_include_vat']);
        self::assertSame('25.50000000', $updated['exchange_rate']);
        self::assertSame('2099-01-01 10:00:00', $updated['reservation_expires_at']);
        self::assertSame(['carrier' => 'Testovací dopravce'], $updated['shipping_snapshot']);
        self::assertSame(['code' => 'SLEVA'], $updated['discount_snapshot']);
        self::assertSame($clientId, $updated['client_id']);
        self::assertCount(1, $updated['lines']);
        self::assertSame($lineUuid, $updated['lines'][0]['line_uuid']);
        self::assertSame($totalWithVat, $updated['total_with_vat']);

        $cleared = $orders->update($sid, $id, 2, ['exchange_rate' => null, 'reservation_expires_at' => null]);
        self::assertNull($cleared['exchange_rate']);
        self::assertNull($cleared['reservation_expires_at']);
        self::assertTrue($cleared['prices_include_vat']);
    }

    public function testSalesOrderHeaderOnlyUpdateLeavesStockLinesUntouched(): void
    {
        $orders = $this->container->get(SalesOrderService::class);
        $sid = $this->createSupplier();
        $this->salesOrderSuppliers[] = $sid;
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'SO-PARTIAL');
        $order = $orders->create($sid, [
            'client_id' => $this->client($sid),
            'currency_id' => $this->currencyIdFor($sid),
            'order_number' => 'TEST-SO-STOCK-PARTIAL',
            'prices_include_vat' => false,
            'lines' => [[
                'stock_item_id' => $item, 'warehouse_id' => $whId, 'quantity' => '2.000',
                'unit_price' => '100.000000', 'vat_rate_id' => $this->vatRateId,
            ]],
        ], $this->userId);
        $id = (int) $order['id'];
        $line = $order['lines'][0];

        // Mezitím se změní karta i sklad; úprava hlavičky se řádků nesmí dotknout.
        $this->db->pdo()->prepare('UPDATE stock_items SET sku = ?, name = ? WHERE id = ?')->execute(['SO-PREJMENOVANO', 'Přejmenovaná karta', $item]);
        $this->db->pdo()->prepare('UPDATE warehouses SET is_active = 0 WHERE id = ?')->execute([$whId]);

        $updated = $orders->update($sid, $id, 1, ['prices_include_vat' => true]);

        self::assertCount(1, $updated['lines']);
        $kept = $updated['lines'][0];
        self::assertSame($line['id'], $kept['id']);
        self::assertSame($line['sku_snapshot'], $kept['sku_snapshot']);
        self::assertSame($line['product_snapshot'], $kept['product_snapshot']);
        self::assertSame($line['component_snapshot'], $kept['component_snapshot']);
        self::assertSame($whId, $kept['warehouse_id']);
        self::assertTrue($updated['prices_include_vat']);
        self::assertSame('200.00', $kept['total_with_vat']);
        self::assertSame('200.00', $updated['total_with_vat']);
        self::assertSame($kept['total_without_vat'], $updated['total_without_vat']);
    }

    public function testSalesOrderCurrencyChangeWithoutRateDropsStoredRate(): void
    {
        $orders = $this->container->get(SalesOrderService::class);
        $sid = $this->createSupplier();
        $this->salesOrderSuppliers[] = $sid;
        $eur = $this->extraCurrency($sid, 'EUR');
        $order = $orders->create($sid, [
            'client_id' => $this->client($sid),
            'currency_id' => $eur,
            'order_number' => 'TEST-SO-CURRENCY',
            'exchange_rate' => '25.5',
            'lines' => [['description' => 'Služba', 'quantity' => '1', 'unit_price' => '10', 'vat_rate_id' => $this->vatRateId]],
        ], $this->userId);
        $id = (int) $order['id'];

        $same = $orders->update($sid, $id, 1, ['currency_id' => $eur]);
        self::assertSame('25.50000000', $same['exchange_rate']);

        $changed = $orders->update($sid, $id, 2, ['currency_id' => $this->currencyIdFor($sid)]);
        self::assertSame('CZK', $changed['currency_code']);
        self::assertNull($changed['exchange_rate']);
    }

    public function testPurchaseOrderCurrencyChangeWithoutRateDropsStoredRate(): void
    {
        $orders = $this->container->get(PurchaseOrderService::class);
        $sid = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-CURRENCY');
        $eur = $this->extraCurrency($sid, 'EUR');
        $order = $orders->create($sid, [
            'vendor_id' => $this->client($sid, 'Dodavatel'),
            'order_date' => '2099-08-01',
            'warehouse_id' => $whId,
            'currency_id' => $eur,
            'exchange_rate' => '25.5',
            'lines' => [['stock_item_id' => $item, 'description' => 'Položka', 'qty_ordered' => '1', 'unit_price' => '5']],
        ], $this->userId);
        $id = (int) $order['id'];

        $same = $orders->update($sid, $id, ['currency_id' => $eur], $this->userId);
        self::assertSame('25.500000', $same['exchange_rate']);

        $changed = $orders->update($sid, $id, ['currency_id' => $this->currencyIdFor($sid)], $this->userId);
        self::assertSame($this->currencyIdFor($sid), (int) $changed['currency_id']);
        self::assertNull($changed['exchange_rate']);
    }

    public function testPurchaseOrderPartialUpdateKeepsOmittedHeaderAndLines(): void
    {
        $orders = $this->container->get(PurchaseOrderService::class);
        $sid = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-PARTIAL');
        $vendorId = $this->client($sid, 'Dodavatel objednávek');
        $order = $orders->create($sid, [
            'vendor_id' => $vendorId,
            'order_date' => '2099-08-01',
            'expected_date' => '2099-08-20',
            'warehouse_id' => $whId,
            'currency_id' => $this->currencyIdFor($sid),
            'exchange_rate' => '1.5',
            'vendor_reference' => 'REF-1',
            'note' => 'Poznámka',
            'internal_note' => 'Interní',
            'lines' => [['stock_item_id' => $item, 'description' => 'Položka', 'qty_ordered' => '10', 'unit_price' => '25.50', 'note' => 'Řádek']],
        ], $this->userId);
        $id = (int) $order['id'];

        $updated = $orders->update($sid, $id, ['internal_note' => 'Nová interní'], $this->userId);

        self::assertSame('Nová interní', $updated['internal_note']);
        self::assertSame($vendorId, (int) $updated['vendor_id']);
        self::assertSame('2099-08-01', $updated['order_date']);
        self::assertSame('2099-08-20', $updated['expected_date']);
        self::assertSame('1.500000', $updated['exchange_rate']);
        self::assertSame('REF-1', $updated['vendor_reference']);
        self::assertSame('Poznámka', $updated['note']);
        self::assertCount(1, $updated['lines']);
        self::assertSame((int) $order['lines'][0]['id'], (int) $updated['lines'][0]['id']);
        self::assertSame('10.000', $updated['lines'][0]['qty_ordered']);
        self::assertSame('Řádek', $updated['lines'][0]['note']);
        self::assertSame('255.00', $updated['total_without_vat']);

        $cleared = $orders->update($sid, $id, ['expected_date' => null, 'vendor_reference' => '', 'exchange_rate' => null], $this->userId);
        self::assertNull($cleared['expected_date']);
        self::assertNull($cleared['vendor_reference']);
        self::assertNull($cleared['exchange_rate']);
        self::assertSame('Poznámka', $cleared['note']);
    }

    public function testPurchaseOrderReconfirmWithoutQtyKeepsConfirmedQuantity(): void
    {
        $orders = $this->container->get(PurchaseOrderService::class);
        $sid = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-CONFIRM');
        $order = $orders->create($sid, [
            'vendor_id' => $this->client($sid, 'Dodavatel'),
            'order_date' => '2099-08-01',
            'warehouse_id' => $whId,
            'currency_id' => $this->currencyIdFor($sid),
            'lines' => [['stock_item_id' => $item, 'description' => 'Položka', 'qty_ordered' => '10', 'unit_price' => '5']],
        ], $this->userId);
        $id = (int) $order['id'];
        $lineId = (int) $order['lines'][0]['id'];
        $orders->send($sid, $id, $this->userId);
        $orders->confirm($sid, $id, ['lines' => [['id' => $lineId, 'qty_confirmed' => '5']]], $this->userId);

        // Hlavičkový termín se mění, aby opakované potvrzení v téže sekundě změnilo řádek hlavičky.
        $again = $orders->confirm($sid, $id, ['expected_date' => '2099-10-01', 'lines' => [['id' => $lineId, 'expected_date' => '2099-10-10']]], $this->userId);
        self::assertSame('5.000', $again['lines'][0]['qty_confirmed']);
        self::assertSame('2099-10-10', $again['lines'][0]['expected_date']);

        $cleared = $orders->confirm($sid, $id, ['expected_date' => '2099-10-02', 'lines' => [['id' => $lineId, 'qty_confirmed' => null]]], $this->userId);
        self::assertNull($cleared['lines'][0]['qty_confirmed']);
    }

    public function testStockDocumentPartialUpdateKeepsOmittedHeaderAndLines(): void
    {
        $orders = $this->container->get(PurchaseOrderService::class);
        $sid = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'DOC-PARTIAL');
        $po = $orders->create($sid, [
            'vendor_id' => $this->client($sid, 'Dodavatel'),
            'order_date' => '2099-08-01',
            'warehouse_id' => $whId,
            'currency_id' => $this->currencyIdFor($sid),
            'lines' => [['stock_item_id' => $item, 'description' => 'Položka', 'qty_ordered' => '10', 'unit_price' => '5']],
        ], $this->userId);
        $draft = $this->documents->create($sid, [
            'doc_type' => 'receipt',
            'warehouse_id' => $whId,
            'doc_date' => '2099-08-10',
            'description' => 'Příjem z objednávky',
            'partner_name' => 'Dodavatel s.r.o.',
            'purchase_order_id' => (int) $po['id'],
            'lines' => [['stock_item_id' => $item, 'qty' => '3', 'unit_cost' => '5', 'note' => 'Řádek']],
        ], $this->userId);
        $id = (int) $draft['id'];

        $updated = $this->documents->updateDraft($sid, $id, ['description' => 'Nový popis'], $this->userId);

        self::assertSame('Nový popis', $updated['description']);
        self::assertSame('receipt', $updated['doc_type']);
        self::assertSame('2099-08-10', $updated['doc_date']);
        self::assertSame('Dodavatel s.r.o.', $updated['partner_name']);
        self::assertSame((int) $po['id'], $updated['purchase_order_id']);
        self::assertCount(1, $updated['lines']);
        self::assertSame('3.000', $updated['lines'][0]['qty']);
        self::assertSame('Řádek', $updated['lines'][0]['note']);

        // Tvar těla z editoru dokladu: purchase_order_id neposílá, partner_name maže nullem.
        $cleared = $this->documents->updateDraft($sid, $id, [
            'doc_type' => 'receipt', 'origin' => 'manual', 'doc_date' => '2099-08-10', 'description' => 'Nový popis',
            'warehouse_id' => $whId, 'partner_name' => null,
            'lines' => [['stock_item_id' => $item, 'qty' => '4', 'unit_cost' => '5']],
        ], $this->userId);
        self::assertNull($cleared['partner_name']);
        self::assertSame((int) $po['id'], $cleared['purchase_order_id']);
        self::assertSame('4.000', $cleared['lines'][0]['qty']);

        // Karta deaktivovaná po založení řádku neblokuje úpravu hlavičky bez řádků.
        $this->db->pdo()->prepare('UPDATE stock_items SET is_active = 0 WHERE id = ?')->execute([$item]);
        $headerOnly = $this->documents->updateDraft($sid, $id, ['purchase_order_id' => null], $this->userId);
        self::assertNull($headerOnly['purchase_order_id']);
        self::assertNull($headerOnly['partner_name']);
        self::assertSame('4.000', $headerOnly['lines'][0]['qty']);
    }

    public function testStockTransferPartialUpdateKeepsTargetWarehouse(): void
    {
        $sid = $this->createSupplier();
        $from = $this->warehouse($sid);
        $to = $this->warehouse($sid, 'DRUHY', false);
        $item = $this->item($sid, 'TRANSFER-PARTIAL');
        $draft = $this->documents->create($sid, [
            'doc_type' => 'transfer',
            'warehouse_id' => $from,
            'warehouse_to_id' => $to,
            'doc_date' => '2099-08-10',
            'description' => 'Převod',
            'lines' => [['stock_item_id' => $item, 'qty' => '2']],
        ], $this->userId);

        $updated = $this->documents->updateDraft($sid, (int) $draft['id'], ['doc_date' => '2099-08-11'], $this->userId);
        self::assertSame('transfer', $updated['doc_type']);
        self::assertSame($to, (int) $updated['warehouse_to_id']);
        self::assertSame('2099-08-11', $updated['doc_date']);

        try {
            $this->documents->updateDraft($sid, (int) $draft['id'], ['warehouse_to_id' => null], $this->userId);
            self::fail('Převodka bez cílového skladu musí být odmítnutá.');
        } catch (\MyInvoice\Service\Stock\StockException $e) {
            self::assertSame('invalid_document', $e->errorCode);
        }
    }

    private function extraCurrency(int $supplierId, string $code): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 0)'
        )->execute([$supplierId, $code, $code, $code, $code, $code]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function testStockTakeCountsKeepOmittedCountedQty(): void
    {
        $sid = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'TAKE-PARTIAL');
        $this->receiveStock($sid, $whId, $item, '5.000', 10.0, '2099-01-01');
        $take = $this->takes->create($sid, [
            'warehouse_id' => $whId,
            'take_date' => '2099-02-01',
            'counting_method' => 'physical_count',
            'responsible_count_name' => 'Testovací skladník',
            'responsible_inventory_name' => 'Testovací vedoucí',
        ], $this->userId);
        $started = $this->takes->start($sid, (int) $take['id'], $this->userId);
        $lineId = (int) $started['lines'][0]['id'];
        $this->takes->updateCounts($sid, (int) $take['id'], ['lines' => [['id' => $lineId, 'counted_qty' => '7.000']]], $this->userId);

        $kept = $this->takes->updateCounts($sid, (int) $take['id'], ['lines' => [['id' => $lineId, 'surplus_unit_cost' => '12']]], $this->userId);
        self::assertSame('7.000', $kept['lines'][0]['counted_qty']);
        self::assertSame('12.000000', $kept['lines'][0]['surplus_unit_cost']);

        $cleared = $this->takes->updateCounts($sid, (int) $take['id'], ['lines' => [['id' => $lineId, 'counted_qty' => null]]], $this->userId);
        self::assertNull($cleared['lines'][0]['counted_qty']);
        self::assertSame('12.000000', $cleared['lines'][0]['surplus_unit_cost']);
    }

    public function testCycleCountKeepsOmittedCountAndSurplusCost(): void
    {
        $cycles = $this->container->get(CycleCountService::class);
        $sid = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'CYCLE-PARTIAL');
        $this->receiveStock($sid, $whId, $item, '5.000', 10.0, '2099-09-01');
        $cycle = $cycles->create($sid, ['warehouse_id' => $whId, 'take_date' => '2099-09-02', 'item_ids' => [$item]], $this->userId);
        $cycles->tick($sid);
        $cycle = $cycles->get($sid, (int) $cycle['id']);
        $lineId = (int) $cycle['lines'][0]['id'];
        $cycles->updateCounts($sid, (int) $cycle['id'], [['id' => $lineId, 'counted_qty' => '6', 'surplus_unit_cost' => '10']]);

        $kept = $cycles->updateCounts($sid, (int) $cycle['id'], [['id' => $lineId, 'surplus_unit_cost' => '11']]);
        self::assertSame('6.000', $kept['lines'][0]['counted_qty']);
        self::assertSame(11.0, (float) $kept['lines'][0]['surplus_unit_cost']);

        $kept = $cycles->updateCounts($sid, (int) $cycle['id'], [['id' => $lineId, 'counted_qty' => '6']]);
        self::assertSame(11.0, (float) $kept['lines'][0]['surplus_unit_cost']);

        $cleared = $cycles->updateCounts($sid, (int) $cycle['id'], [['id' => $lineId, 'counted_qty' => null, 'surplus_unit_cost' => null]]);
        self::assertNull($cleared['lines'][0]['counted_qty']);
        self::assertNull($cleared['lines'][0]['surplus_unit_cost']);
    }
}
