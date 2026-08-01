<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Epic VH-05 (Plátcovství DPH v čase) — ocenění příjemky z PF podle plátcovství
 * k ROZHODNÉMU DATU zdrojového dokladu (tax_date ?? issue_date), ne podle živé
 * cache supplier.is_vat_payer.
 *
 * Scénář: firma má v historii (supplier_vat_status_history) jiný stav k datu PF,
 * než jaký ukazuje živý flag "dnes". Návrh i založení příjemky musí ocenit řádky
 * podle stavu k datu faktury: plátce → total_without_vat (DPH je odpočet, do
 * pořizovací ceny nepatří), neplátce → total_with_vat (DPH je součást PC).
 */
#[Group('integration')]
final class StockReceiptVatStatusTest extends StockTestCase
{
    use IsolatedSupplierTrait;

    /**
     * Živě plátce (dnes), ale k datu PF (2099-06-01) už NEplátce → pořizovací
     * cena s DPH, přestože živý flag říká "bez DPH".
     */
    public function testValuationUsesVatStatusAtDocumentDateNotLiveFlag(): void
    {
        $supplierId = $this->createSupplier();
        $whId       = $this->warehouse($supplierId);
        $itemId     = $this->item($supplierId, 'VAT-HIST-1');
        $vendorId   = $this->client($supplierId, 'Dodavatel historie');

        $pdo = $this->db->pdo();
        $this->setVatPayerAt($pdo, $supplierId, '1900-01-01', true);
        $this->setVatPayerAt($pdo, $supplierId, '2099-01-01', false);
        self::assertSame(
            1,
            (int) $pdo->query("SELECT is_vat_payer FROM supplier WHERE id = {$supplierId}")->fetchColumn(),
            'Živá cache musí zůstat "plátce" (budoucí řádek historie ji nemění).',
        );

        $piId     = $this->purchaseInvoice($supplierId, $vendorId, ['issue_date' => '2099-06-01']);
        $piItemId = $this->purchaseInvoiceItem($piId, $itemId, '1.000', 1000.0);

        $withVat = round(1000.0 + 1000.0 * ((float) $this->vatRatePercent) / 100, 2);

        $proposal = $this->receipts->proposeForPurchaseInvoice($supplierId, $piId);
        self::assertEqualsWithDelta(
            $withVat,
            (float) $proposal['lines'][0]['unit_cost'],
            0.0001,
            'Neplátce k datu PF → návrh PC s DPH, i když živý flag říká plátce.',
        );

        $receipt = $this->receipts->createReceipt($supplierId, $piId, [
            'warehouse_id' => $whId,
            'doc_date'     => '2099-06-05',
            'lines'        => [['purchase_invoice_item_id' => $piItemId, 'quantity' => '1.000']],
        ], $this->userId);
        self::assertEqualsWithDelta(
            $withVat,
            (float) $receipt['lines'][0]['unit_cost'],
            0.0001,
            'Neplátce k datu PF → řádek příjemky oceněn s DPH.',
        );
    }

    /**
     * Zrcadlově: živě NEplátce (dnes), ale k datu PF plátce → pořizovací cena
     * bez DPH (nárok na odpočet v okamžiku plnění).
     */
    public function testHistoricPayerGetsCostWithoutVatDespiteLiveNonPayer(): void
    {
        $supplierId = $this->createSupplier();
        $whId       = $this->warehouse($supplierId);
        $itemId     = $this->item($supplierId, 'VAT-HIST-2');
        $vendorId   = $this->client($supplierId, 'Dodavatel historie 2');

        $pdo = $this->db->pdo();
        $this->setVatPayerAt($pdo, $supplierId, '1900-01-01', false);
        $this->setVatPayerAt($pdo, $supplierId, '2099-01-01', true);
        self::assertSame(
            0,
            (int) $pdo->query("SELECT is_vat_payer FROM supplier WHERE id = {$supplierId}")->fetchColumn(),
            'Živá cache musí být "neplátce" (řádek s budoucí účinností se neaplikuje).',
        );

        $piId     = $this->purchaseInvoice($supplierId, $vendorId, ['issue_date' => '2099-06-01']);
        $piItemId = $this->purchaseInvoiceItem($piId, $itemId, '2.000', 500.0);

        $proposal = $this->receipts->proposeForPurchaseInvoice($supplierId, $piId);
        self::assertEqualsWithDelta(
            500.0,
            (float) $proposal['lines'][0]['unit_cost'],
            0.0001,
            'Plátce k datu PF → návrh PC bez DPH, i když živý flag říká neplátce.',
        );

        $receipt = $this->receipts->createReceipt($supplierId, $piId, [
            'warehouse_id' => $whId,
            'doc_date'     => '2099-06-05',
            'lines'        => [['purchase_invoice_item_id' => $piItemId, 'quantity' => '2.000']],
        ], $this->userId);
        self::assertEqualsWithDelta(500.0, (float) $receipt['lines'][0]['unit_cost'], 0.0001);
    }

    /** Rozhodné datum je tax_date (DUZP), issue_date je jen fallback. */
    public function testTaxDateTakesPrecedenceOverIssueDate(): void
    {
        $supplierId = $this->createSupplier();
        $itemId     = $this->item($supplierId, 'VAT-HIST-3');
        $vendorId   = $this->client($supplierId, 'Dodavatel DUZP');

        $pdo = $this->db->pdo();
        $this->setVatPayerAt($pdo, $supplierId, '1900-01-01', true);
        $this->setVatPayerAt($pdo, $supplierId, '2099-01-01', false);

        // issue_date po změně (neplátce), ale DUZP ještě v období plátcovství.
        $piId = $this->purchaseInvoice($supplierId, $vendorId, ['issue_date' => '2099-06-01']);
        $pdo->prepare('UPDATE purchase_invoices SET tax_date = ? WHERE id = ?')
            ->execute(['2098-12-15', $piId]);
        $this->purchaseInvoiceItem($piId, $itemId, '1.000', 1000.0);

        $proposal = $this->receipts->proposeForPurchaseInvoice($supplierId, $piId);
        self::assertEqualsWithDelta(
            1000.0,
            (float) $proposal['lines'][0]['unit_cost'],
            0.0001,
            'DUZP (tax_date) v období plátcovství → PC bez DPH bez ohledu na issue_date.',
        );
    }
}
