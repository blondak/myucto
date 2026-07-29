<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Stock\StockException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Epic SKLAD, plán §8.2 scénář 12 — inventura end-to-end (start → snapshot →
 * počty → close → rozdílové doklady origin='inventory', posted) a A13 (otevřená
 * inventura blokuje post pohybů skladu).
 */
#[Group('integration')]
final class StockTakeTest extends StockTestCase
{
    public function testInventoryLifecycleProducesPostedDifferenceDocuments(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $shortageItem = $this->item($supplierId, 'INV-A');
        $surplusItem  = $this->item($supplierId, 'INV-B');

        $this->receiveStock($supplierId, $whId, $shortageItem, '10.000', 10.0, '2099-01-01');
        $this->receiveStock($supplierId, $whId, $surplusItem, '5.000', 20.0, '2099-01-01');

        $take = $this->takes->create($supplierId, [
            'warehouse_id' => $whId,
            'take_date'    => '2099-02-01',
            'counting_method' => 'physical_count',
            'responsible_count_name' => 'Testovací skladník',
            'responsible_inventory_name' => 'Testovací vedoucí',
        ], $this->userId);
        self::assertSame('draft', $take['status']);

        $started = $this->takes->start($supplierId, (int) $take['id'], $this->userId);
        self::assertSame('counting', $started['status']);
        self::assertCount(2, $started['lines']);

        $lineIdByItem = [];
        foreach ($started['lines'] as $l) {
            $lineIdByItem[(int) $l['stock_item_id']] = (int) $l['id'];
        }
        self::assertSame('10.000', $this->findLine($started, $shortageItem)['expected_qty']);
        self::assertSame('5.000', $this->findLine($started, $surplusItem)['expected_qty']);

        // Otevřená inventura blokuje normální pohyb na tomtéž skladu (A13).
        $blockedDraft = $this->documents->create($supplierId, [
            'doc_type'     => 'receipt',
            'origin'       => 'manual',
            'warehouse_id' => $whId,
            'doc_date'     => '2099-02-02',
            'description'  => 'Pohyb během inventury',
            'lines'        => [['stock_item_id' => $shortageItem, 'qty' => '1.000', 'unit_cost' => '10.000000']],
        ], $this->userId);
        try {
            $this->documents->post($supplierId, (int) $blockedDraft['id'], $this->userId);
            self::fail('post() během otevřené inventury měl vyhodit stock_take_in_progress.');
        } catch (StockException $e) {
            self::assertSame('stock_take_in_progress', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }

        $this->takes->updateCounts($supplierId, (int) $take['id'], [
            'lines' => [
                ['id' => $lineIdByItem[$shortageItem], 'counted_qty' => '7.000'], // manko −3
                ['id' => $lineIdByItem[$surplusItem], 'counted_qty' => '8.000'],  // přebytek +3
            ],
        ], $this->userId);

        $closed = $this->takes->close($supplierId, (int) $take['id'], $this->userId);
        self::assertSame('closed', $closed['status']);

        self::assertNotNull($closed['issue_document'], 'manko musí vytvořit posted výdejku (origin=inventory).');
        self::assertSame('issue', $closed['issue_document']['doc_type']);
        self::assertSame('inventory', $closed['issue_document']['origin']);
        self::assertSame('posted', $closed['issue_document']['status']);
        self::assertNull($closed['issue_document']['journal_entry_id'], 'inventurní doklad NESMÍ mít deníkový zápis (v1 způsob B).');

        self::assertNotNull($closed['receipt_document'], 'přebytek musí vytvořit posted příjemku (origin=inventory).');
        self::assertSame('receipt', $closed['receipt_document']['doc_type']);
        self::assertSame('inventory', $closed['receipt_document']['origin']);
        self::assertSame('posted', $closed['receipt_document']['status']);
        self::assertNull($closed['receipt_document']['journal_entry_id']);

        $shortageLevel = $this->level($supplierId, $whId, $shortageItem);
        self::assertSame(7000, $shortageLevel['qtyT']);
        $surplusLevel = $this->level($supplierId, $whId, $surplusItem);
        self::assertSame(8000, $surplusLevel['qtyT']);

        // Zavřená inventura znovu neblokuje běžné pohyby.
        $freeDraft = $this->documents->create($supplierId, [
            'doc_type'     => 'receipt',
            'origin'       => 'manual',
            'warehouse_id' => $whId,
            'doc_date'     => '2099-02-05',
            'description'  => 'Pohyb po uzavření inventury',
            'lines'        => [['stock_item_id' => $shortageItem, 'qty' => '1.000', 'unit_cost' => '10.000000']],
        ], $this->userId);
        $posted = $this->documents->post($supplierId, (int) $freeDraft['id'], $this->userId);
        self::assertSame('posted', $posted['status']);
    }

    public function testHistoricalSnapshotIncludesInactiveItemAndUsesDecisionDate(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-HIST');
        $this->receiveStock($supplierId, $whId, $itemId, '5.000', 10.0, '2099-01-01');
        $this->receiveStock($supplierId, $whId, $itemId, '2.000', 20.0, '2099-01-20');
        $this->itemsRepo->deactivate($supplierId, $itemId);

        $take = $this->takes->create($supplierId, $this->takeBody($whId, '2099-01-10'), $this->userId);
        $started = $this->takes->start($supplierId, (int) $take['id'], $this->userId);

        self::assertSame('5.000', $this->findLine($started, $itemId)['expected_qty']);
        self::assertNotNull($started['started_at']);
    }

    public function testZeroBookSurplusRequiresReproductionCost(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-SURPLUS');
        $take = $this->takes->create($supplierId, $this->takeBody($whId, '2099-03-01'), $this->userId);
        $started = $this->takes->start($supplierId, (int) $take['id'], $this->userId);
        $line = $this->findLine($started, $itemId);

        $this->takes->updateCounts($supplierId, (int) $take['id'], [
            'lines' => [['id' => $line['id'], 'counted_qty' => '2.000']],
        ], $this->userId);
        try {
            $this->takes->close($supplierId, (int) $take['id'], $this->userId);
            self::fail('Přebytek bez reprodukční ceny musí být odmítnut.');
        } catch (StockException $e) {
            self::assertSame('missing_surplus_unit_cost', $e->errorCode);
        }

        $this->takes->updateCounts($supplierId, (int) $take['id'], [
            'lines' => [['id' => $line['id'], 'counted_qty' => '2.000', 'surplus_unit_cost' => '37.500000']],
        ], $this->userId);
        $closed = $this->takes->close($supplierId, (int) $take['id'], $this->userId);
        self::assertSame('37.500000', $closed['receipt_document']['lines'][0]['unit_cost']);
        self::assertSame('75.00', $closed['receipt_document']['lines'][0]['value_total']);
    }

    /** @return array<string,mixed> */
    private function takeBody(int $warehouseId, string $date): array
    {
        return [
            'warehouse_id' => $warehouseId,
            'take_date' => $date,
            'counting_method' => 'physical_count',
            'responsible_count_name' => 'Testovací skladník',
            'responsible_inventory_name' => 'Testovací vedoucí',
        ];
    }

    /** @param array<string,mixed> $take */
    private function findLine(array $take, int $stockItemId): array
    {
        foreach ($take['lines'] as $l) {
            if ((int) $l['stock_item_id'] === $stockItemId) {
                return $l;
            }
        }
        self::fail("řádek pro kartu #$stockItemId nenalezen.");
    }
}
