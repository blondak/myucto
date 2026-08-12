<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Repository\InTransitRepository;
use MyInvoice\Repository\PurchaseOrderRepository;
use MyInvoice\Service\Stock\InTransitService;
use MyInvoice\Service\Stock\PurchaseOrderReceiptService;
use MyInvoice\Service\Stock\PurchaseOrderService;
use MyInvoice\Service\Stock\StockException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Odvozená množství „na cestě" a „rezervováno" (Epic SKLAD, §3.4 + §11.2)
 * a automatické přechody objednávky, které nastavuje jen příjemka.
 *
 * ## Nejdůležitější test v souboru
 *
 * {@see testReverseOfReceiptReturnsGoodsToTransit()} a jeho zrcadlo
 * {@see testReverseOfIssueReturnsReservation()} hlídají riziko R-1: obojí
 * se počítá jako `SUM(receipt) − SUM(issue)` přes vazební sloupec na řádku
 * skladového dokladu, takže když `StockDocumentService::reverse()` ten sloupec
 * do protidokladu NEZKOPÍRUJE, protidoklad se nemá k čemu přiřadit a zboží se
 * TIŠE nevrátí — bez výjimky, bez varování, jen jiné číslo na kartě.
 * {@see testReversalActuallyCopiesTheLinkColumns()} to ověřuje ještě přímo
 * na datech protidokladu, aby bylo z chybové hlášky hned vidět proč.
 */
#[Group('integration')]
final class InTransitTest extends StockTestCase
{
    private PurchaseOrderService $orders;
    private PurchaseOrderRepository $ordersRepo;
    private PurchaseOrderReceiptService $orderReceipts;
    private InTransitService $quantities;
    private InTransitRepository $inTransitRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders        = $this->container->get(PurchaseOrderService::class);
        $this->ordersRepo    = $this->container->get(PurchaseOrderRepository::class);
        $this->orderReceipts = $this->container->get(PurchaseOrderReceiptService::class);
        $this->quantities    = $this->container->get(InTransitService::class);
        $this->inTransitRepo = $this->container->get(InTransitRepository::class);
    }

    // ── „na cestě" podle stavu objednávky ────────────────────────────────────

    public function testDraftOrderIsNotInTransitButSentOneIs(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'IT-1');
        $id   = (int) $this->createOrder($sid, $whId, $item, '10')['id'];

        self::assertSame('0.000', $this->inTransitOf($sid, $item), 'Draft ještě není závazek.');

        $this->orders->send($sid, $id, $this->userId);
        self::assertSame('10.000', $this->inTransitOf($sid, $item));
    }

    public function testConfirmedQuantityReplacesOrderedInTransit(): void
    {
        $sid   = $this->createSupplier();
        $whId  = $this->warehouse($sid);
        $item  = $this->item($sid, 'IT-2');
        $order = $this->createOrder($sid, $whId, $item, '10');
        $id    = (int) $order['id'];

        $this->orders->send($sid, $id, $this->userId);
        $this->orders->confirm($sid, $id, [
            'lines' => [['id' => (int) $order['lines'][0]['id'], 'qty_confirmed' => '7']],
        ], $this->userId);

        self::assertSame('7.000', $this->inTransitOf($sid, $item));
    }

    public function testClosedCancelledAndReceivedOrdersLeaveTransit(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);

        $itemClosed = $this->item($sid, 'IT-3a');
        $closedId   = (int) $this->createOrder($sid, $whId, $itemClosed, '5')['id'];
        $this->orders->send($sid, $closedId, $this->userId);
        $this->orders->close($sid, $closedId, 'nedodá', $this->userId);
        self::assertSame('0.000', $this->inTransitOf($sid, $itemClosed));

        $itemCancelled = $this->item($sid, 'IT-3b');
        $cancelledId   = (int) $this->createOrder($sid, $whId, $itemCancelled, '5')['id'];
        $this->orders->send($sid, $cancelledId, $this->userId);
        $this->orders->cancel($sid, $cancelledId, 'zrušeno', $this->userId);
        self::assertSame('0.000', $this->inTransitOf($sid, $itemCancelled));
    }

    public function testSupplierSwitchConfirmedOnlyExcludesMerelySentOrders(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'IT-4');
        $id   = (int) $this->createOrder($sid, $whId, $item, '8')['id'];
        $this->orders->send($sid, $id, $this->userId);

        self::assertSame('8.000', $this->inTransitOf($sid, $item));

        // Firma, která věří až potvrzení dodavatelem (rozhodnutí #2 — přepínač).
        $this->db->pdo()->prepare("UPDATE supplier SET stock_in_transit_from = 'confirmed' WHERE id = ?")
            ->execute([$sid]);
        self::assertSame('0.000', $this->inTransitOf($sid, $item));

        $this->orders->confirm($sid, $id, [], $this->userId);
        self::assertSame('8.000', $this->inTransitOf($sid, $item));
    }

    public function testServiceLineWithoutStockItemNeverEntersTransit(): void
    {
        $sid  = $this->createSupplier();
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'IT-5');
        $id   = (int) $this->orders->create($sid, [
            'vendor_id'    => $this->vendorFor($sid),
            'order_date'   => '2099-08-01',
            'warehouse_id' => $whId,
            'currency_id'  => $this->currencyIdFor($sid),
            'lines'        => [
                ['stock_item_id' => $item, 'qty_ordered' => '3', 'unit_price' => '10', 'description' => 'Zboží'],
                ['qty_ordered' => '1', 'unit_price' => '500', 'description' => 'Doprava'],
            ],
        ], $this->userId)['id'];
        $this->orders->send($sid, $id, $this->userId);

        self::assertSame('3.000', $this->inTransitOf($sid, $item), 'Doprava se do „na cestě" nepočítá.');
    }

    // ── příjem a automatické přechody ────────────────────────────────────────

    public function testPartialReceiptMovesOrderToPartiallyReceivedAndShrinksTransit(): void
    {
        $sid   = $this->createSupplier();
        $whId  = $this->warehouse($sid);
        $item  = $this->item($sid, 'IT-6');
        $order = $this->createOrder($sid, $whId, $item, '10');
        $id    = (int) $order['id'];
        $polId = (int) $order['lines'][0]['id'];
        $this->orders->send($sid, $id, $this->userId);

        $this->receive($sid, $id, $polId, '4');

        self::assertSame('partially_received', (string) $this->ordersRepo->find($sid, $id)['state']);
        self::assertSame('6.000', $this->inTransitOf($sid, $item), 'Na cestě zbývá jen nedodaný zbytek.');
        self::assertSame(4000, $this->level($sid, $whId, $item)['qtyT']);
    }

    public function testFullReceiptMovesOrderToReceivedAndEmptiesTransit(): void
    {
        $sid   = $this->createSupplier();
        $whId  = $this->warehouse($sid);
        $item  = $this->item($sid, 'IT-7');
        $order = $this->createOrder($sid, $whId, $item, '10');
        $id    = (int) $order['id'];
        $polId = (int) $order['lines'][0]['id'];
        $this->orders->send($sid, $id, $this->userId);

        $this->receive($sid, $id, $polId, '10');

        self::assertSame('received', (string) $this->ordersRepo->find($sid, $id)['state']);
        self::assertSame('0.000', $this->inTransitOf($sid, $item));
    }

    public function testOverReceiptIsRejectedUnlessExplicitlyAllowed(): void
    {
        $sid   = $this->createSupplier();
        $whId  = $this->warehouse($sid);
        $item  = $this->item($sid, 'IT-8');
        $order = $this->createOrder($sid, $whId, $item, '5');
        $id    = (int) $order['id'];
        $polId = (int) $order['lines'][0]['id'];
        $this->orders->send($sid, $id, $this->userId);

        try {
            $this->orderReceipts->createReceipt($sid, $id, [
                'doc_date' => '2099-08-15',
                'lines'    => [['purchase_order_line_id' => $polId, 'qty' => '7']],
            ], $this->userId);
            self::fail('Nadměrná dodávka musí bez potvrzení skončit 409 over_receipt.');
        } catch (StockException $e) {
            self::assertSame('over_receipt', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }

        $draft = $this->orderReceipts->createReceipt($sid, $id, [
            'doc_date'            => '2099-08-15',
            'allow_over_delivery' => true,
            'lines'               => [['purchase_order_line_id' => $polId, 'qty' => '7']],
        ], $this->userId);
        $this->documents->post($sid, (int) $draft['id'], $this->userId);

        $lines = $this->ordersRepo->lines($sid, $id);
        self::assertTrue($lines[0]['has_over_delivery']);
        self::assertSame('5.000', $lines[0]['qty_ordered'], 'qty_ordered se nadměrnou dodávkou NIKDY nezvyšuje.');
        self::assertSame('0.000', $this->inTransitOf($sid, $item), 'Přijato víc než objednáno → na cestě nic (GREATEST 0).');
        self::assertSame(7000, $this->level($sid, $whId, $item)['qtyT']);
    }

    public function testReceiptBeforeInvoiceUsesOrderPriceAsEstimate(): void
    {
        $sid   = $this->createSupplier();
        $whId  = $this->warehouse($sid);
        $item  = $this->item($sid, 'IT-9');
        $order = $this->createOrder($sid, $whId, $item, '4', '123.50');
        $id    = (int) $order['id'];
        $this->orders->send($sid, $id, $this->userId);

        $proposal = $this->orderReceipts->propose($sid, $id);
        self::assertTrue($proposal['cost_is_estimate'], 'Bez faktury je cena odhad (rozhodnutí #3).');
        self::assertTrue($proposal['lines'][0]['cost_is_estimate']);
        self::assertSame('123.500000', $proposal['lines'][0]['unit_cost']);
        self::assertSame('4.000', $proposal['lines'][0]['remaining_qty']);
    }

    // ── R-1: storno musí vrátit zboží „na cestu" ─────────────────────────────

    /**
     * Storno příjemky vytvoří protidoklad `issue` se stejným
     * `purchase_order_line_id`; odvozovací dotaz ho odečte a zboží se vrátí
     * „na cestu". Bez kopírování toho sloupce v `reverse()` zůstane „na cestě"
     * na 6 místo 10 — tiše, protože nic nespadne.
     */
    public function testReverseOfReceiptReturnsGoodsToTransit(): void
    {
        $sid   = $this->createSupplier();
        $whId  = $this->warehouse($sid);
        $item  = $this->item($sid, 'IT-10');
        $order = $this->createOrder($sid, $whId, $item, '10');
        $id    = (int) $order['id'];
        $polId = (int) $order['lines'][0]['id'];
        $this->orders->send($sid, $id, $this->userId);

        self::assertSame('10.000', $this->inTransitOf($sid, $item));

        $receipt = $this->receive($sid, $id, $polId, '4');
        self::assertSame('6.000', $this->inTransitOf($sid, $item));
        self::assertSame('partially_received', (string) $this->ordersRepo->find($sid, $id)['state']);

        $this->documents->reverse($sid, (int) $receipt['id'], ['reason' => 'špatná dodávka'], $this->userId);

        self::assertSame(
            '10.000',
            $this->inTransitOf($sid, $item),
            'Storno příjemky MUSÍ vrátit množství „na cestu" — jinak reverse() nekopíruje purchase_order_line_id (R-1).',
        );
        self::assertSame(0, $this->level($sid, $whId, $item)['qtyT']);
        self::assertSame(
            'sent',
            (string) $this->ordersRepo->find($sid, $id)['state'],
            'Po stornu jediné příjemky se objednávka vrací mezi nesplněné.',
        );
    }

    /**
     * Zrcadlový případ pro rezervace (§11.2): výdejka k faktuře rezervaci
     * spotřebuje, její storno ji musí vrátit. Stejný vzorec, stejné riziko —
     * vazba je `invoice_item_id`.
     */
    public function testReverseOfIssueReturnsReservation(): void
    {
        // stock_auto_issue = 0 → mezi vystavením faktury a výdejem existuje okno,
        // ve kterém rezervace vůbec dává smysl.
        $sid  = $this->createSupplier(autoIssue: false);
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'IT-11');
        $this->receiveStock($sid, $whId, $item, '20.000', 100.0);

        $clientId  = $this->client($sid, 'Odběratel');
        $invoiceId = $this->invoiceDraft($sid, $clientId);
        $iiId      = $this->invoiceItem($invoiceId, $item, $whId, '6.000', 250.0);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invoiceId]);

        self::assertSame('6.000', $this->reservedOf($sid, $item), 'Vystavená faktura bez výdeje = rezervace.');

        $draft = $this->documents->create($sid, [
            'doc_type'     => 'issue',
            'origin'       => 'invoice',
            'warehouse_id' => $whId,
            'doc_date'     => '2099-06-11',
            'description'  => 'Výdej k FV',
            'invoice_id'   => $invoiceId,
            'lines'        => [['stock_item_id' => $item, 'qty' => '6.000', 'invoice_item_id' => $iiId]],
        ], $this->userId);
        $issue = $this->documents->post($sid, (int) $draft['id'], $this->userId);

        self::assertSame('0.000', $this->reservedOf($sid, $item), 'Zaúčtovaný výdej rezervaci spotřebuje.');

        $this->documents->reverse($sid, (int) $issue['id'], ['reason' => 'omyl'], $this->userId);

        self::assertSame(
            '6.000',
            $this->reservedOf($sid, $item),
            'Storno výdejky MUSÍ vrátit rezervaci — jinak reverse() nekopíruje invoice_item_id.',
        );
    }

    /**
     * Přímý důkaz mechanismu, aby při regresi bylo z hlášky vidět PROČ:
     * protidoklad musí nést tytéž vazební sloupce jako originál.
     */
    public function testReversalActuallyCopiesTheLinkColumns(): void
    {
        $sid   = $this->createSupplier();
        $whId  = $this->warehouse($sid);
        $item  = $this->item($sid, 'IT-12');
        $order = $this->createOrder($sid, $whId, $item, '3');
        $id    = (int) $order['id'];
        $polId = (int) $order['lines'][0]['id'];
        $this->orders->send($sid, $id, $this->userId);

        $receipt = $this->receive($sid, $id, $polId, '3');
        $result  = $this->documents->reverse($sid, (int) $receipt['id'], [], $this->userId);

        $reversal = $result['reversal'];
        self::assertSame('issue', (string) $reversal['doc_type']);
        self::assertSame(
            $id,
            (int) $reversal['purchase_order_id'],
            'Protidoklad musí nést purchase_order_id z hlavičky originálu.',
        );
        self::assertSame(
            $polId,
            (int) $reversal['lines'][0]['purchase_order_line_id'],
            'Protidoklad musí nést purchase_order_line_id — bez něj se odečet „na cestě" nemá k čemu přiřadit (R-1).',
        );
    }

    // ── /quantities: karta bez jediného pohybu (rozhodnutí #12) ──────────────

    public function testQuantitiesReturnZeroRowForBrandNewItemWithoutAnyMovement(): void
    {
        $sid  = $this->createSupplier();
        $this->warehouse($sid);
        $item = $this->item($sid, 'IT-13');

        $rows = $this->quantities->quantities($sid, [$item]);

        self::assertCount(1, $rows, 'Karta bez pohybu i bez objednávky MUSÍ vrátit řádek, ne prázdno.');
        self::assertSame($item, $rows[0]['stock_item_id']);
        self::assertSame('0.000', $rows[0]['on_hand']);
        self::assertSame('0.000', $rows[0]['reserved']);
        self::assertSame('0.000', $rows[0]['sellable']);
        self::assertSame('0.000', $rows[0]['in_transit']);
        self::assertSame('0.000', $rows[0]['at_vendor']);
        self::assertSame('0.000', $rows[0]['available_to_promise']);
        self::assertNull($rows[0]['earliest_expected_date']);
        self::assertSame([], $rows[0]['warehouses']);
        self::assertSame([], $rows[0]['in_transit_orders']);
        self::assertSame([], $rows[0]['vendor_offers']);
    }

    public function testQuantitiesCombineAllThreeDimensions(): void
    {
        $sid   = $this->createSupplier(autoIssue: false);
        $whId  = $this->warehouse($sid);
        $item  = $this->item($sid, 'IT-14');
        $this->receiveStock($sid, $whId, $item, '20.000', 50.0);

        $clientId  = $this->client($sid, 'Odběratel');
        $invoiceId = $this->invoiceDraft($sid, $clientId);
        $this->invoiceItem($invoiceId, $item, $whId, '5.000', 90.0);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invoiceId]);

        $orderId = (int) $this->createOrder($sid, $whId, $item, '12')['id'];
        $this->orders->send($sid, $orderId, $this->userId);

        $row = $this->quantities->quantities($sid, [$item])[0];

        self::assertSame('20.000', $row['on_hand']);
        self::assertSame('5.000', $row['reserved']);
        self::assertSame('15.000', $row['sellable'], 'sellable = on_hand − reserved (rozhodnutí #1).');
        self::assertSame('12.000', $row['in_transit']);
        self::assertSame('27.000', $row['available_to_promise'], 'ATP = on_hand − reserved + in_transit.');
        self::assertCount(1, $row['in_transit_orders']);
        self::assertSame($orderId, $row['in_transit_orders'][0]['order_id']);
    }

    public function testSellableGoesNegativeWhenOversoldAndIsNotFloored(): void
    {
        $sid  = $this->createSupplier(autoIssue: false);
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'IT-15');
        $this->receiveStock($sid, $whId, $item, '2.000', 10.0);

        $clientId  = $this->client($sid, 'Odběratel');
        $invoiceId = $this->invoiceDraft($sid, $clientId);
        $this->invoiceItem($invoiceId, $item, $whId, '5.000', 20.0);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invoiceId]);

        $row = $this->quantities->quantities($sid, [$item])[0];
        self::assertSame('-3.000', $row['sellable'], 'Záporné sellable je signál k urgentnímu doobjednání, nepodlahuje se.');
    }

    public function testProformaAndDraftInvoicesDoNotReserve(): void
    {
        $sid  = $this->createSupplier(autoIssue: false);
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'IT-16');
        $this->receiveStock($sid, $whId, $item, '10.000', 10.0);
        $clientId = $this->client($sid, 'Odběratel');

        $proforma = $this->invoiceDraft($sid, $clientId, 'proforma');
        $this->invoiceItem($proforma, $item, $whId, '4.000', 20.0);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$proforma]);

        $draft = $this->invoiceDraft($sid, $clientId);
        $this->invoiceItem($draft, $item, $whId, '3.000', 20.0);

        self::assertSame('0.000', $this->reservedOf($sid, $item), 'Proforma není závazek dodat, draft taky ne.');
    }

    public function testCancelledInvoiceReleasesReservation(): void
    {
        $sid  = $this->createSupplier(autoIssue: false);
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'IT-17');
        $this->receiveStock($sid, $whId, $item, '10.000', 10.0);

        $clientId  = $this->client($sid, 'Odběratel');
        $invoiceId = $this->invoiceDraft($sid, $clientId);
        $this->invoiceItem($invoiceId, $item, $whId, '4.000', 20.0);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invoiceId]);
        self::assertSame('4.000', $this->reservedOf($sid, $item));

        $this->db->pdo()->prepare("UPDATE invoices SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?")
            ->execute([$invoiceId]);
        self::assertSame('0.000', $this->reservedOf($sid, $item));
    }

    public function testReservationsBreakdownNamesTheInvoiceHoldingTheStock(): void
    {
        $sid  = $this->createSupplier(autoIssue: false);
        $whId = $this->warehouse($sid);
        $item = $this->item($sid, 'IT-18');
        $this->receiveStock($sid, $whId, $item, '10.000', 10.0);

        $clientId  = $this->client($sid, 'Firma Držící Zboží');
        $invoiceId = $this->invoiceDraft($sid, $clientId);
        $this->invoiceItem($invoiceId, $item, $whId, '2.000', 20.0);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invoiceId]);

        $rows = $this->quantities->reservations($sid, [$item]);
        self::assertCount(1, $rows);
        self::assertSame('2.000', $rows[0]['qty_reserved']);
        self::assertCount(1, $rows[0]['invoices']);
        self::assertSame($invoiceId, $rows[0]['invoices'][0]['invoice_id']);
        self::assertSame('Firma Držící Zboží', $rows[0]['invoices'][0]['client_name']);
    }

    public function testInTransitIsTenantScoped(): void
    {
        $sid   = $this->createSupplier();
        $other = $this->createSupplier();
        $whId  = $this->warehouse($sid);
        $item  = $this->item($sid, 'IT-19');
        $id    = (int) $this->createOrder($sid, $whId, $item, '9')['id'];
        $this->orders->send($sid, $id, $this->userId);

        self::assertSame('9.000', $this->inTransitOf($sid, $item));
        self::assertSame([], $this->inTransitRepo->forItems($other, [$item]), 'Cizí firma nesmí vidět nic.');
        self::assertSame([], $this->quantities->quantities($other, [$item]));
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function createOrder(int $sid, int $whId, int $itemId, string $qty, string $unitPrice = '100'): array
    {
        return $this->orders->create($sid, [
            'vendor_id'     => $this->vendorFor($sid),
            'order_date'    => '2099-08-01',
            'expected_date' => '2099-08-20',
            'warehouse_id'  => $whId,
            'currency_id'   => $this->currencyIdFor($sid),
            'lines'         => [[
                'stock_item_id' => $itemId,
                'qty_ordered'   => $qty,
                'unit_price'    => $unitPrice,
                'description'   => 'Zboží k objednávce',
            ]],
        ], $this->userId);
    }

    /** Založí a rovnou zaúčtuje příjemku z objednávky. @return array<string,mixed> */
    private function receive(int $sid, int $orderId, int $polId, string $qty): array
    {
        $draft = $this->orderReceipts->createReceipt($sid, $orderId, [
            'doc_date' => '2099-08-15',
            'lines'    => [['purchase_order_line_id' => $polId, 'qty' => $qty]],
        ], $this->userId);

        return $this->documents->post($sid, (int) $draft['id'], $this->userId);
    }

    private function inTransitOf(int $sid, int $itemId): string
    {
        $total = 0;
        foreach ($this->inTransitRepo->forItems($sid, [$itemId]) as $row) {
            $total += (int) round((float) $row['qty_in_transit'] * 1000);
        }

        return number_format($total / 1000, 3, '.', '');
    }

    private function reservedOf(int $sid, int $itemId): string
    {
        $total = 0;
        foreach ($this->inTransitRepo->reservedForItems($sid, [$itemId]) as $row) {
            $total += (int) round((float) $row['qty_reserved'] * 1000);
        }

        return number_format($total / 1000, 3, '.', '');
    }

    /** @var array<int,int> */
    private array $vendorCache = [];

    private function vendorFor(int $supplierId): int
    {
        return $this->vendorCache[$supplierId] ??= $this->client($supplierId, 'Dodavatel objednávek');
    }
}
