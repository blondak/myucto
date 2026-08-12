<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Repository\PurchaseOrderRepository;
use MyInvoice\Service\Stock\PurchaseOrderService;
use MyInvoice\Service\Stock\StockException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Celý stavový automat objednávky (Epic SKLAD „na cestě", §4.1).
 *
 * Ruční přechody: send / confirm / cancel / close / reopen. Automatické
 * (partially_received, received) pokrývá {@see InTransitTest} — ty se totiž
 * nedají vyvolat jinak než skutečnou příjemkou.
 */
#[Group('integration')]
final class PurchaseOrderLifecycleTest extends StockTestCase
{
    private PurchaseOrderService $orders;
    private PurchaseOrderRepository $ordersRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders     = $this->container->get(PurchaseOrderService::class);
        $this->ordersRepo = $this->container->get(PurchaseOrderRepository::class);
    }

    public function testCreateDraftHasNoNumberAndComputesTotals(): void
    {
        $sid   = $this->createSupplier();
        $whId  = $this->warehouse($sid);
        $item  = $this->item($sid, 'PO-1');
        $order = $this->createOrder($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '10', 'unit_price' => '25.50'],
        ]);

        self::assertSame('draft', $order['state']);
        self::assertNull($order['order_number'], 'Draft nesmí mít přidělené číslo řady.');
        self::assertSame('255.00', $order['total_without_vat']);
        self::assertCount(1, $order['lines']);
        self::assertSame('10.000', $order['lines'][0]['qty_ordered']);
        self::assertSame('0.000', $order['lines'][0]['qty_received']);
        self::assertSame('10.000', $order['lines'][0]['qty_remaining']);
    }

    public function testSendAssignsNumberFromObjSeriesAndIsIdempotent(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-2');
        $id   = (int) $this->createOrder($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '5', 'unit_price' => '10'],
        ])['id'];

        $sent = $this->orders->send($sid, $id, $this->userId);
        self::assertSame('sent', $sent['state']);
        self::assertMatchesRegularExpression('/^OBJ-2099-\d{4}$/', (string) $sent['order_number']);
        self::assertNotNull($sent['sent_at']);

        // Dvojklik nesmí propálit další číslo ani spadnout.
        $again = $this->orders->send($sid, $id, $this->userId);
        self::assertSame($sent['order_number'], $again['order_number']);
        self::assertSame('sent', $again['state']);
    }

    public function testSendRejectsEmptyOrderAndNonDraft(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-3');
        $id   = (int) $this->createOrder($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '1', 'unit_price' => '1'],
        ])['id'];

        $this->orders->send($sid, $id, $this->userId);
        $this->orders->confirm($sid, $id, [], $this->userId);

        $this->expectStockError('order_state_conflict', fn () => $this->orders->send($sid, $id, $this->userId));
    }

    public function testConfirmOverridesQuantityAndExpectedDate(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-4');
        $order = $this->createOrder($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '10', 'unit_price' => '5'],
        ]);
        $id     = (int) $order['id'];
        $lineId = (int) $order['lines'][0]['id'];

        $this->orders->send($sid, $id, $this->userId);
        $confirmed = $this->orders->confirm($sid, $id, [
            'expected_date' => '2099-09-30',
            'lines'         => [['id' => $lineId, 'qty_confirmed' => '8', 'expected_date' => '2099-10-05']],
        ], $this->userId);

        self::assertSame('confirmed', $confirmed['state']);
        self::assertNotNull($confirmed['confirmed_at']);
        self::assertSame('2099-09-30', $confirmed['expected_date']);
        self::assertSame('8.000', $confirmed['lines'][0]['qty_confirmed']);
        self::assertSame('2099-10-05', $confirmed['lines'][0]['expected_date']);
        // Potvrzené množství nahrazuje objednané ve všech dalších výpočtech.
        self::assertSame('8.000', $confirmed['lines'][0]['qty_effective']);
        self::assertSame('8.000', $confirmed['qty_ordered_total']);
    }

    public function testConfirmRejectsForeignLine(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-5');
        $id   = (int) $this->createOrder($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '1', 'unit_price' => '1'],
        ])['id'];
        $this->orders->send($sid, $id, $this->userId);

        $this->expectStockError('invalid_order', fn () => $this->orders->confirm($sid, $id, [
            'lines' => [['id' => 999999999, 'qty_confirmed' => '1']],
        ], $this->userId));
    }

    public function testCancelIsAllowedBeforeAnyReceipt(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-6');
        $id   = (int) $this->createOrder($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '3', 'unit_price' => '7'],
        ])['id'];
        $this->orders->send($sid, $id, $this->userId);

        $cancelled = $this->orders->cancel($sid, $id, 'Dodavatel to nemá', $this->userId);
        self::assertSame('cancelled', $cancelled['state']);
        self::assertSame('Dodavatel to nemá', $cancelled['cancel_reason']);
        self::assertNotNull($cancelled['cancelled_at']);

        // Z terminálního stavu už nikam.
        $this->expectStockError('order_state_conflict', fn () => $this->orders->close($sid, $id, 'x', $this->userId));
    }

    public function testCloseSetsCancelledRemainderOnOpenLines(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-7');
        $id   = (int) $this->createOrder($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '12', 'unit_price' => '3'],
        ])['id'];
        $this->orders->send($sid, $id, $this->userId);

        $closed = $this->orders->close($sid, $id, 'Zbytek už nedodá', $this->userId);
        self::assertSame('closed', $closed['state']);
        self::assertSame('Zbytek už nedodá', $closed['close_reason']);
        // Celý zbytek je uzavřený → z „na cestě" musí zmizet.
        self::assertSame('12.000', $closed['lines'][0]['qty_cancelled']);
        self::assertSame('0.000', $closed['lines'][0]['qty_remaining']);
        self::assertSame('0.000', $closed['qty_remaining_total']);
    }

    public function testCloseRejectsDraft(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-8');
        $id   = (int) $this->createOrder($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '1', 'unit_price' => '1'],
        ])['id'];

        $this->expectStockError('order_state_conflict', fn () => $this->orders->close($sid, $id, 'x', $this->userId));
    }

    public function testReopenClearsCancelledRemainderAndRecomputes(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-9');
        $id   = (int) $this->createOrder($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '6', 'unit_price' => '2'],
        ])['id'];
        $this->orders->send($sid, $id, $this->userId);
        $this->orders->close($sid, $id, 'omyl', $this->userId);

        $reopened = $this->orders->reopen($sid, $id, $this->userId);
        self::assertSame('sent', $reopened['state'], 'Bez příjmu se objednávka vrací na sent.');
        self::assertNull($reopened['closed_at']);
        self::assertNull($reopened['close_reason']);
        self::assertSame('0.000', $reopened['lines'][0]['qty_cancelled']);
        self::assertSame('6.000', $reopened['qty_remaining_total']);
    }

    public function testReopenRejectsNonClosed(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-10');
        $id   = (int) $this->createOrder($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '1', 'unit_price' => '1'],
        ])['id'];

        $this->expectStockError('order_state_conflict', fn () => $this->orders->reopen($sid, $id, $this->userId));
    }

    public function testUpdateAndDeleteAreDraftOnly(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-11');
        $id   = (int) $this->createOrder($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '4', 'unit_price' => '11'],
        ])['id'];

        $updated = $this->orders->update($sid, $id, $this->body($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '9', 'unit_price' => '11'],
        ]), $this->userId);
        self::assertSame('9.000', $updated['lines'][0]['qty_ordered']);
        self::assertSame('99.00', $updated['total_without_vat']);

        $this->orders->send($sid, $id, $this->userId);
        $this->expectStockError('order_not_editable', fn () => $this->orders->update($sid, $id, $this->body($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '1', 'unit_price' => '1'],
        ]), $this->userId));
        $this->expectStockError('order_not_editable', fn () => $this->orders->delete($sid, $id));
    }

    public function testDeleteDraftRemovesLines(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-12');
        $id   = (int) $this->createOrder($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '2', 'unit_price' => '1'],
        ])['id'];

        self::assertTrue($this->orders->delete($sid, $id));
        self::assertNull($this->ordersRepo->find($sid, $id));
        self::assertSame([], $this->ordersRepo->lines($sid, $id));
    }

    public function testValidationRejectsForeignVendorWarehouseAndItem(): void
    {
        $sid   = $this->createSupplier();
        $other = $this->createSupplier();
        $whId  = $this->warehouse($sid);
        $item  = $this->item($sid, 'PO-13');

        $foreignVendor = $this->client($other, 'Cizí dodavatel');
        $foreignWh     = $this->warehouse($other, 'CIZI');
        $foreignItem   = $this->item($other, 'CIZI-1');

        $base = $this->body($sid, $whId, [['stock_item_id' => $item, 'qty_ordered' => '1', 'unit_price' => '1']]);

        $this->expectStockError('invalid_order', fn () => $this->orders->create(
            $sid,
            ['vendor_id' => $foreignVendor] + $base,
            $this->userId,
        ));
        $this->expectStockError('invalid_order', fn () => $this->orders->create(
            $sid,
            ['warehouse_id' => $foreignWh] + $base,
            $this->userId,
        ));
        $this->expectStockError('invalid_order', fn () => $this->orders->create(
            $sid,
            ['lines' => [['stock_item_id' => $foreignItem, 'qty_ordered' => '1', 'unit_price' => '1', 'description' => 'x']]] + $base,
            $this->userId,
        ));
    }

    public function testValidationRejectsNonPositiveQuantityAndEmptyLines(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-14');

        $this->expectStockError('invalid_order', fn () => $this->orders->create($sid, $this->body($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '0', 'unit_price' => '1'],
        ]), $this->userId));

        $this->expectStockError('invalid_order', fn () => $this->orders->create($sid, $this->body($sid, $whId, []), $this->userId));
    }

    public function testServiceOnlyLineDoesNotCountIntoFulfilment(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'PO-15');
        $order = $this->createOrder($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '2', 'unit_price' => '100'],
            ['qty_ordered' => '1', 'unit_price' => '250', 'description' => 'Doprava'],
        ]);

        self::assertCount(2, $order['lines']);
        self::assertNull($order['lines'][1]['stock_item_id']);
        // Doprava je v ceně, ale ne v plnění — do „na cestě" nevstupuje.
        self::assertSame('450.00', $order['total_without_vat']);
        self::assertSame('2.000', $order['qty_ordered_total']);
    }

    public function testTenantIsolationOnRead(): void
    {
        $sid   = $this->createSupplier();
        $other = $this->createSupplier();
        $whId  = $this->warehouse($sid);
        $item  = $this->item($sid, 'PO-16');
        $id    = (int) $this->createOrder($sid, $whId, [
            ['stock_item_id' => $item, 'qty_ordered' => '1', 'unit_price' => '1'],
        ])['id'];

        self::assertNotNull($this->orders->detail($sid, $id));
        self::assertNull($this->orders->detail($other, $id), 'Objednávka cizí firmy nesmí být čitelná.');
        self::assertSame([], $this->ordersRepo->lines($other, $id));
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,mixed>
     */
    private function createOrder(int $supplierId, int $warehouseId, array $lines): array
    {
        return $this->orders->create($supplierId, $this->body($supplierId, $warehouseId, $lines), $this->userId);
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,mixed>
     */
    private function body(int $supplierId, int $warehouseId, array $lines): array
    {
        return [
            'vendor_id'     => $this->vendorFor($supplierId),
            'order_date'    => '2099-08-01',
            'expected_date' => '2099-08-20',
            'warehouse_id'  => $warehouseId,
            'currency_id'   => $this->currencyIdFor($supplierId),
            'lines'         => array_map(static function (array $l): array {
                return $l + ['description' => 'Položka objednávky'];
            }, $lines),
        ];
    }

    /** @var array<int,int> */
    private array $vendorCache = [];

    private function vendorFor(int $supplierId): int
    {
        return $this->vendorCache[$supplierId] ??= $this->client($supplierId, 'Dodavatel objednávek');
    }

    private function expectStockError(string $errorCode, callable $fn): void
    {
        try {
            $fn();
            self::fail("Očekávaná StockException('$errorCode') nebyla vyhozena.");
        } catch (StockException $e) {
            self::assertSame($errorCode, $e->errorCode, 'Jiný chybový kód: ' . $e->getMessage());
        }
    }
}
