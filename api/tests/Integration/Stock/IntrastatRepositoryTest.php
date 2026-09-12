<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Repository\IntrastatRepository;
use MyInvoice\Service\Intrastat\IntrastatService;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class IntrastatRepositoryTest extends StockTestCase
{
    public function testReadsOnlyPostedMovementAndExcludesBothSidesOfReversal(): void
    {
        $supplierId = $this->createSupplier();
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET dic = 'CZ12345678' WHERE id = ?")->execute([$supplierId]);

        $skId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'SK' LIMIT 1")->fetchColumn() ?: 0);
        if ($skId === 0) {
            $this->markTestSkipped('Číselník zemí neobsahuje Slovensko.');
        }
        $clientId = $this->client($supplierId, 'Test EU partner');
        $pdo->prepare("UPDATE clients SET country_id = ?, dic = 'SK2020123456' WHERE id = ?")
            ->execute([$skId, $clientId]);

        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INTRASTAT-QUERY');
        $pdo->prepare(
            "UPDATE stock_items
                SET intrastat_cn8_code = '84137021', intrastat_country_of_origin = 'DE',
                    intrastat_net_mass_kg = 1.250
              WHERE id = ? AND supplier_id = ?"
        )->execute([$itemId, $supplierId]);
        $invoiceId = $this->invoiceDraft($supplierId, $clientId, over: ['issue_date' => '2026-09-10']);
        $invoiceItemId = $this->invoiceItem($invoiceId, $itemId, $warehouseId, '2.000', 100.0);
        $pdo->prepare(
            'UPDATE invoices SET client_snapshot = ?, status = "issued" WHERE id = ?'
        )->execute([json_encode(['company_name' => 'Test EU partner', 'country_iso2' => 'SK', 'dic' => 'SK2020123456']), $invoiceId]);

        $documentId = $this->insertIssue($supplierId, $warehouseId, $invoiceId, $itemId, $invoiceItemId, 'VYD-2026-0001');
        $repository = new IntrastatRepository($this->db);

        $rows = $repository->movementRows($supplierId, '2026-09-01', '2026-10-01', 'dispatch');
        self::assertCount(1, $rows);
        self::assertSame('84137021', $rows[0]['intrastat_cn8_code']);
        self::assertSame('200.00', $rows[0]['invoice_item_value']);

        $reversalId = $this->insertIssue($supplierId, $warehouseId, $invoiceId, $itemId, $invoiceItemId, 'VYD-2026-0002');
        $pdo->prepare("UPDATE stock_documents SET status = 'reversed', reversal_document_id = ? WHERE id = ?")
            ->execute([$reversalId, $documentId]);

        self::assertSame([], $repository->movementRows($supplierId, '2026-09-01', '2026-10-01', 'dispatch'));
    }

    public function testStandardDirectionsExcludeReturnsCreditNotesAndNegativeSourceLines(): void
    {
        $supplierId = $this->createSupplier();
        $pdo = $this->db->pdo();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INTRASTAT-STANDARD');
        $partnerId = $this->client($supplierId, 'Partner standardních pohybů');

        $saleId = $this->invoiceDraft($supplierId, $partnerId, over: ['issue_date' => '2026-09-11']);
        $saleLineId = $this->invoiceItem($saleId, $itemId, $warehouseId, '1.000', 100.0);
        $regularDispatchId = $this->insertMovement(
            $supplierId,
            $warehouseId,
            'issue',
            'manual',
            'VYD-2026-STANDARD',
            $itemId,
            $saleId,
            null,
            $saleLineId,
            null,
        );

        $negativeSaleId = $this->invoiceDraft($supplierId, $partnerId, over: ['issue_date' => '2026-09-11']);
        $negativeSaleLineId = $this->invoiceItem($negativeSaleId, $itemId, $warehouseId, '-1.000', 100.0);
        $this->insertMovement(
            $supplierId,
            $warehouseId,
            'receipt',
            'invoice',
            'PRI-2026-SALE-RETURN',
            $itemId,
            $negativeSaleId,
            null,
            $negativeSaleLineId,
            null,
        );

        $creditNoteId = $this->invoiceDraft($supplierId, $partnerId, 'credit_note', ['issue_date' => '2026-09-11']);
        $creditNoteLineId = $this->invoiceItem($creditNoteId, $itemId, $warehouseId, '1.000', 100.0);
        $this->insertMovement(
            $supplierId,
            $warehouseId,
            'receipt',
            'credit_note',
            'PRI-2026-CREDIT',
            $itemId,
            $creditNoteId,
            null,
            $creditNoteLineId,
            null,
        );

        $purchaseId = $this->purchaseInvoice($supplierId, $partnerId, ['issue_date' => '2026-09-11']);
        $purchaseLineId = $this->purchaseInvoiceItem($purchaseId, $itemId, '1.000', 80.0);
        $regularArrivalId = $this->insertMovement(
            $supplierId,
            $warehouseId,
            'receipt',
            'manual',
            'PRI-2026-STANDARD',
            $itemId,
            null,
            $purchaseId,
            null,
            $purchaseLineId,
        );

        $negativePurchaseId = $this->purchaseInvoice($supplierId, $partnerId, ['issue_date' => '2026-09-11']);
        $negativePurchaseLineId = $this->purchaseInvoiceItem($negativePurchaseId, $itemId, '-1.000', 80.0);
        $this->insertMovement(
            $supplierId,
            $warehouseId,
            'receipt',
            'purchase_invoice',
            'PRI-2026-NEGATIVE',
            $itemId,
            null,
            $negativePurchaseId,
            null,
            $negativePurchaseLineId,
        );

        $purchaseCreditId = $this->purchaseInvoice($supplierId, $partnerId, ['issue_date' => '2026-09-11']);
        $pdo->prepare("UPDATE purchase_invoices SET document_kind = 'credit_note' WHERE id = ?")
            ->execute([$purchaseCreditId]);
        $purchaseCreditLineId = $this->purchaseInvoiceItem($purchaseCreditId, $itemId, '1.000', 80.0);
        $this->insertMovement(
            $supplierId,
            $warehouseId,
            'issue',
            'purchase_invoice',
            'VYD-2026-PURCHASE-RETURN',
            $itemId,
            null,
            $purchaseCreditId,
            null,
            $purchaseCreditLineId,
        );

        $repository = new IntrastatRepository($this->db);
        $dispatch = $repository->movementRows($supplierId, '2026-09-01', '2026-10-01', 'dispatch');
        $arrival = $repository->movementRows($supplierId, '2026-09-01', '2026-10-01', 'arrival');

        self::assertSame([$regularDispatchId], array_map('intval', array_column($dispatch, 'document_id')));
        self::assertSame([$regularArrivalId], array_map('intval', array_column($arrival, 'document_id')));
    }

    public function testAllocationBaseExcludesStockReturnsAndKeepsSideExpenses(): void
    {
        $supplierId = $this->createSupplier();
        $pdo = $this->db->pdo();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INTRASTAT-ALLOCATION');
        $partnerId = $this->client($supplierId, 'Partner alokace');
        $invoiceId = $this->invoiceDraft($supplierId, $partnerId, over: ['issue_date' => '2026-09-12']);
        $positiveLineId = $this->invoiceItem($invoiceId, $itemId, $warehouseId, '1.000', 100.0, 0);
        $this->invoiceItem($invoiceId, $itemId, $warehouseId, '-1.000', 50.0, 1);
        $this->invoiceItem($invoiceId, null, null, '1.000', 20.0, 2);
        $pdo->prepare('UPDATE invoices SET total_without_vat = 70.00 WHERE id = ?')->execute([$invoiceId]);
        $this->insertMovement(
            $supplierId,
            $warehouseId,
            'issue',
            'manual',
            'VYD-2026-ALLOCATION',
            $itemId,
            $invoiceId,
            null,
            $positiveLineId,
            null,
        );

        $rows = (new IntrastatRepository($this->db))
            ->movementRows($supplierId, '2026-09-01', '2026-10-01', 'dispatch');

        self::assertCount(1, $rows);
        self::assertSame('100.00', $rows[0]['invoice_goods_value']);
        self::assertSame('20.00', $rows[0]['invoice_unmapped_value']);
    }

    public function testUnmappedConsultingBlocksExportAndIsNotAddedToGoodsValue(): void
    {
        [$supplierId, $partnerId, $warehouseId, $itemId] = $this->intrastatFixture('INTRASTAT-SERVICE');
        $pdo = $this->db->pdo();
        $invoiceId = $this->invoiceDraft($supplierId, $partnerId, over: ['issue_date' => '2026-09-12']);
        $goodsLineId = $this->invoiceItem($invoiceId, $itemId, $warehouseId, '1.000', 1000.0, 0);
        $this->invoiceItem($invoiceId, null, null, '1.000', 5000.0, 1);
        $pdo->prepare('UPDATE invoices SET total_without_vat = 6000.00 WHERE id = ?')->execute([$invoiceId]);
        $this->insertMovement(
            $supplierId,
            $warehouseId,
            'issue',
            'manual',
            'VYD-2026-SERVICE',
            $itemId,
            $invoiceId,
            null,
            $goodsLineId,
            null,
        );

        $repository = new IntrastatRepository($this->db);
        $rows = $repository->movementRows($supplierId, '2026-09-01', '2026-10-01', 'dispatch');
        self::assertCount(1, $rows);
        self::assertSame('1000.00', $rows[0]['invoice_goods_value']);
        self::assertSame('5000.00', $rows[0]['invoice_unmapped_value']);

        $preview = (new IntrastatService($repository))
            ->prepare($supplierId, ['period' => '2026-09', 'direction' => 'dispatch']);
        self::assertSame(1000, $preview['preview']['rows'][0]['invoiced_value']);
        self::assertContains(
            'invoice_unmapped_value_present',
            array_column($preview['preview']['rows'][0]['issues'], 'code'),
        );
        self::assertGreaterThan(0, $preview['preview']['summary']['error_count']);
    }

    public function testOppositeUnmappedLinesCannotCancelExportBlockInEitherDirection(): void
    {
        [$supplierId, $partnerId, $warehouseId, $itemId] = $this->intrastatFixture('INTRASTAT-UNMAPPED-OFFSET');
        $pdo = $this->db->pdo();

        $invoiceId = $this->invoiceDraft($supplierId, $partnerId, over: ['issue_date' => '2026-09-12']);
        $invoiceGoodsId = $this->invoiceItem($invoiceId, $itemId, $warehouseId, '1.000', 100.0, 0);
        $this->invoiceItem($invoiceId, null, null, '1.000', 500.0, 1);
        $this->invoiceItem($invoiceId, null, null, '-1.000', 500.0, 2);
        $pdo->prepare('UPDATE invoices SET total_without_vat = 100.00 WHERE id = ?')->execute([$invoiceId]);
        $this->insertMovement(
            $supplierId,
            $warehouseId,
            'issue',
            'manual',
            'VYD-2026-UNMAPPED-OFFSET',
            $itemId,
            $invoiceId,
            null,
            $invoiceGoodsId,
            null,
        );

        $purchaseId = $this->purchaseInvoice($supplierId, $partnerId, ['issue_date' => '2026-09-12']);
        $purchaseGoodsId = $this->purchaseInvoiceItem($purchaseId, $itemId, '1.000', 100.0, 0);
        $this->purchaseInvoiceItem($purchaseId, null, '1.000', 500.0, 1);
        $this->purchaseInvoiceItem($purchaseId, null, '-1.000', 500.0, 2);
        $pdo->prepare('UPDATE purchase_invoices SET total_without_vat = 100.00 WHERE id = ?')->execute([$purchaseId]);
        $this->insertMovement(
            $supplierId,
            $warehouseId,
            'receipt',
            'manual',
            'PRI-2026-UNMAPPED-OFFSET',
            $itemId,
            null,
            $purchaseId,
            null,
            $purchaseGoodsId,
        );

        $repository = new IntrastatRepository($this->db);
        $service = new IntrastatService($repository);
        foreach (['dispatch', 'arrival'] as $direction) {
            $rows = $repository->movementRows($supplierId, '2026-09-01', '2026-10-01', $direction);
            self::assertCount(1, $rows, $direction);
            self::assertSame('0.00', $rows[0]['invoice_unmapped_value'], $direction);
            self::assertSame(2, $rows[0]['invoice_unmapped_line_count'], $direction);

            $preview = $service->prepare($supplierId, ['period' => '2026-09', 'direction' => $direction]);
            self::assertContains(
                'invoice_unmapped_value_present',
                array_column($preview['preview']['rows'][0]['issues'], 'code'),
                $direction,
            );
            self::assertGreaterThan(0, $preview['preview']['summary']['error_count'], $direction);
        }
    }

    public function testMovementLineMappingClassifiesItemsWithoutCardAsGoodsInBothDirections(): void
    {
        [$supplierId, $partnerId, $warehouseId, $itemId] = $this->intrastatFixture('INTRASTAT-RECEIPT-MAP');
        $pdo = $this->db->pdo();
        $purchaseId = $this->purchaseInvoice($supplierId, $partnerId, ['issue_date' => '2026-09-13']);
        $purchaseLineId = $this->purchaseInvoiceItem($purchaseId, null, '1.000', 100.0);
        $pdo->prepare('UPDATE purchase_invoices SET total_without_vat = 100.00 WHERE id = ?')->execute([$purchaseId]);
        $this->insertMovement(
            $supplierId,
            $warehouseId,
            'receipt',
            'manual',
            'PRI-2026-RECEIPT-MAP',
            $itemId,
            null,
            $purchaseId,
            null,
            $purchaseLineId,
        );

        $repository = new IntrastatRepository($this->db);
        $rows = $repository->movementRows($supplierId, '2026-09-01', '2026-10-01', 'arrival');
        self::assertCount(1, $rows);
        self::assertSame('100.00', $rows[0]['invoice_goods_value']);
        self::assertSame('0.00', $rows[0]['invoice_unmapped_value']);

        $preview = (new IntrastatService($repository))
            ->prepare($supplierId, ['period' => '2026-09', 'direction' => 'arrival']);
        self::assertSame(100, $preview['preview']['rows'][0]['invoiced_value']);

        $invoiceId = $this->invoiceDraft($supplierId, $partnerId, over: ['issue_date' => '2026-09-13']);
        $invoiceLineId = $this->invoiceItem($invoiceId, null, null, '1.000', 100.0);
        $pdo->prepare('UPDATE invoices SET total_without_vat = 100.00 WHERE id = ?')->execute([$invoiceId]);
        $this->insertMovement(
            $supplierId,
            $warehouseId,
            'issue',
            'manual',
            'VYD-2026-ISSUE-MAP',
            $itemId,
            $invoiceId,
            null,
            $invoiceLineId,
            null,
        );

        $rows = $repository->movementRows($supplierId, '2026-09-01', '2026-10-01', 'dispatch');
        self::assertCount(1, $rows);
        self::assertSame('100.00', $rows[0]['invoice_goods_value']);
        self::assertSame('0.00', $rows[0]['invoice_unmapped_value']);
        $preview = (new IntrastatService($repository))
            ->prepare($supplierId, ['period' => '2026-09', 'direction' => 'dispatch']);
        self::assertSame(100, $preview['preview']['rows'][0]['invoiced_value']);
    }

    public function testOrphanedInvoiceItemLinksRemainBlockingCandidatesInBothDirections(): void
    {
        [$supplierId, $partnerId, $warehouseId, $itemId] = $this->intrastatFixture('INTRASTAT-ORPHAN');
        $pdo = $this->db->pdo();

        $invoiceId = $this->invoiceDraft($supplierId, $partnerId, over: ['issue_date' => '2026-09-14']);
        $invoiceLineId = $this->invoiceItem($invoiceId, $itemId, $warehouseId, '1.000', 100.0);
        $this->insertMovement(
            $supplierId,
            $warehouseId,
            'issue',
            'manual',
            'VYD-2026-ORPHAN',
            $itemId,
            $invoiceId,
            null,
            $invoiceLineId,
            null,
        );
        $pdo->prepare('DELETE FROM invoice_items WHERE id = ?')->execute([$invoiceLineId]);

        $purchaseId = $this->purchaseInvoice($supplierId, $partnerId, ['issue_date' => '2026-09-14']);
        $purchaseLineId = $this->purchaseInvoiceItem($purchaseId, $itemId, '1.000', 100.0);
        $this->insertMovement(
            $supplierId,
            $warehouseId,
            'receipt',
            'manual',
            'PRI-2026-ORPHAN',
            $itemId,
            null,
            $purchaseId,
            null,
            $purchaseLineId,
        );
        $pdo->prepare('DELETE FROM purchase_invoice_items WHERE id = ?')->execute([$purchaseLineId]);

        $repository = new IntrastatRepository($this->db);
        $service = new IntrastatService($repository);
        foreach (['dispatch', 'arrival'] as $direction) {
            $rows = $repository->movementRows($supplierId, '2026-09-01', '2026-10-01', $direction);
            self::assertCount(1, $rows, $direction);
            self::assertNull($rows[0][$direction === 'dispatch' ? 'invoice_item_id' : 'purchase_invoice_item_id']);

            $preview = $service->prepare($supplierId, ['period' => '2026-09', 'direction' => $direction]);
            $codes = array_column($preview['preview']['rows'][0]['issues'], 'code');
            self::assertContains('invoice_item_link_missing', $codes, $direction);
            self::assertGreaterThan(0, $preview['preview']['summary']['error_count'], $direction);
        }
    }

    public function testPurchaseExchangeRatePeriodUsesTaxDateBeforeIssueDate(): void
    {
        [$supplierId, $partnerId, $warehouseId, $itemId] = $this->intrastatFixture('INTRASTAT-RATE-DATE');
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "EUR", "Euro", "EUR", "Euro", "Euro", 2, 1, 0)'
        )->execute([$supplierId]);
        $eurId = (int) $pdo->lastInsertId();

        $purchaseId = $this->purchaseInvoice($supplierId, $partnerId, ['issue_date' => '2026-10-03']);
        $purchaseLineId = $this->purchaseInvoiceItem($purchaseId, $itemId, '1.000', 100.0);
        $pdo->prepare(
            'UPDATE purchase_invoices
                SET tax_date = "2026-09-25", currency_id = ?, exchange_rate = 25.000000,
                    exchange_rate_date = "2026-09-25", total_without_vat = 100.00
              WHERE id = ?'
        )->execute([$eurId, $purchaseId]);
        $this->insertMovement(
            $supplierId,
            $warehouseId,
            'receipt',
            'manual',
            'PRI-2026-RATE-DATE',
            $itemId,
            null,
            $purchaseId,
            null,
            $purchaseLineId,
        );

        $repository = new IntrastatRepository($this->db);
        $rows = $repository->movementRows($supplierId, '2026-09-01', '2026-10-01', 'arrival');
        self::assertCount(1, $rows);
        self::assertSame('2026-09-25', $rows[0]['invoice_rate_date']);

        $preview = (new IntrastatService($repository))
            ->prepare($supplierId, ['period' => '2026-09', 'direction' => 'arrival']);
        self::assertNotContains(
            'exchange_rate_period_mismatch',
            array_column($preview['preview']['rows'][0]['issues'], 'code'),
        );
    }

    private function insertIssue(
        int $supplierId,
        int $warehouseId,
        int $invoiceId,
        int $itemId,
        int $invoiceItemId,
        string $number,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO stock_documents
                (supplier_id, doc_type, origin, warehouse_id, doc_number, doc_date, description,
                 invoice_id, status, booked_at, booked_by, created_by)
             VALUES (?, 'issue', 'invoice', ?, ?, '2026-09-10', 'Test Intrastat', ?, 'posted', NOW(), ?, ?)"
        )->execute([$supplierId, $warehouseId, $number, $invoiceId, $this->userId, $this->userId]);
        $documentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO stock_document_lines
                (document_id, supplier_id, stock_item_id, doc_date, qty, unit_cost, value_total,
                 invoice_item_id, source_description, line_no)
             VALUES (?, ?, ?, "2026-09-10", 2.000, 10.000000, 20.00, ?, "Testovací položka", 0)'
        )->execute([$documentId, $supplierId, $itemId, $invoiceItemId]);
        return $documentId;
    }

    private function insertMovement(
        int $supplierId,
        int $warehouseId,
        string $docType,
        string $origin,
        string $number,
        int $itemId,
        ?int $invoiceId,
        ?int $purchaseInvoiceId,
        ?int $invoiceItemId,
        ?int $purchaseInvoiceItemId,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_documents
                (supplier_id, doc_type, origin, warehouse_id, doc_number, doc_date, description,
                 invoice_id, purchase_invoice_id, status, booked_at, booked_by, created_by)
             VALUES (?, ?, ?, ?, ?, "2026-09-11", "Test Intrastat", ?, ?, "posted", NOW(), ?, ?)'
        )->execute([
            $supplierId,
            $docType,
            $origin,
            $warehouseId,
            $number,
            $invoiceId,
            $purchaseInvoiceId,
            $this->userId,
            $this->userId,
        ]);
        $documentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO stock_document_lines
                (document_id, supplier_id, stock_item_id, doc_date, qty, unit_cost, value_total,
                 invoice_item_id, purchase_invoice_item_id, source_description, line_no)
             VALUES (?, ?, ?, "2026-09-11", 1.000, 10.000000, 10.00, ?, ?, "Testovací položka", 0)'
        )->execute([
            $documentId,
            $supplierId,
            $itemId,
            $invoiceItemId,
            $purchaseInvoiceItemId,
        ]);
        return $documentId;
    }

    /** @return array{int,int,int,int} */
    private function intrastatFixture(string $sku): array
    {
        $supplierId = $this->createSupplier();
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET dic = 'CZ12345678' WHERE id = ?")->execute([$supplierId]);
        $skId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'SK' LIMIT 1")->fetchColumn() ?: 0);
        if ($skId === 0) {
            $this->markTestSkipped('Číselník zemí neobsahuje Slovensko.');
        }
        $partnerId = $this->client($supplierId, 'Intrastat EU partner');
        $pdo->prepare("UPDATE clients SET country_id = ?, dic = 'SK2020123456' WHERE id = ?")
            ->execute([$skId, $partnerId]);
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, $sku);
        $pdo->prepare(
            "UPDATE stock_items
                SET intrastat_cn8_code = '84137021', intrastat_country_of_origin = 'DE',
                    intrastat_net_mass_kg = 1.000
              WHERE id = ? AND supplier_id = ?"
        )->execute([$itemId, $supplierId]);

        return [$supplierId, $partnerId, $warehouseId, $itemId];
    }
}
