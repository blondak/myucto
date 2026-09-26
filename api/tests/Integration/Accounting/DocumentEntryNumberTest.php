<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Service\Accounting\DocumentEntryNumberBackfill;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Číslo dokladu v hlavičce zápisu faktury (journal_entries.document_no).
 *
 * Automatické zaúčtování po vystavení i přeúčtování ho nedodávají — dřív ho doplnilo
 * až doúčtování, které přepisovalo i zaúčtované doklady. Od chvíle, kdy doúčtování
 * bere jen doklady bez zápisu, zůstávaly zápisy z automatiky bez čísla. Číslo proto
 * určuje PostingService sám (DocumentEntryNumber) a staré díry dorovná backfill.
 */
#[Group('integration')]
final class DocumentEntryNumberTest extends BankPostingTestCase
{
    public function testInvoiceAndCreditNoteEntriesCarryVarsymbol(): void
    {
        $client = $this->client('Odběratel čísla dokladu');
        $invoice = $this->saleInvoice('FV-DOCNO-1', $client, 1000.0);
        $credit = $this->saleInvoice('DB-DOCNO-1', $client, -200.0, 'credit_note');

        self::assertSame('FV-DOCNO-1', $this->documentNo($this->post('invoice', $invoice, '311', '602', 1000.0)));
        self::assertSame('DB-DOCNO-1', $this->documentNo($this->post('invoice', $credit, '602', '311', 200.0)));
    }

    public function testPurchaseEntryCarriesVendorNumberWithFallbackToInternalNumber(): void
    {
        $vendor = $this->client('Dodavatel čísla dokladu');
        $withVendorNo = $this->purchaseInvoice('DOD-2099-17', $vendor, 500.0);
        $withoutVendorNo = $this->purchaseInvoice('', $vendor, 300.0);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET varsymbol = 'PF-DOCNO-2' WHERE id = ?")
            ->execute([$withoutVendorNo]);

        self::assertSame('DOD-2099-17', $this->documentNo($this->post('purchase_invoice', $withVendorNo, '518', '321', 500.0)));
        self::assertSame('PF-DOCNO-2', $this->documentNo($this->post('purchase_invoice', $withoutVendorNo, '518', '321', 300.0)));
    }

    public function testRepostOfEntryWithoutNumberFillsIt(): void
    {
        $client = $this->client('Odběratel přeúčtování');
        $invoice = $this->saleInvoice('FV-DOCNO-3', $client, 800.0);
        $entryId = $this->post('invoice', $invoice, '311', '602', 800.0);
        $this->db->pdo()->prepare('UPDATE journal_entries SET document_no = NULL WHERE id = ?')->execute([$entryId]);

        // Přeúčtování předává číslo z existujícího zápisu — tady prázdné.
        $again = $this->post('invoice', $invoice, '311', '604', 800.0, ['document_no' => null]);
        self::assertSame($entryId, $again, 'Přeúčtování přepisuje týž zápis.');
        self::assertSame('FV-DOCNO-3', $this->documentNo($entryId));
    }

    public function testBackfillFillsOnlyOpenPeriods(): void
    {
        $client = $this->client('Odběratel backfill');
        $open = $this->post('invoice', $this->saleInvoice('FV-DOCNO-4', $client, 100.0), '311', '602', 100.0);
        $closedPeriod = $this->periods->create($this->supplierId, self::YEAR - 1, (self::YEAR - 1) . '-01-01', (self::YEAR - 1) . '-12-31');
        $closed = $this->post('invoice', $this->saleInvoice('FV-DOCNO-5', $client, 100.0), '311', '602', 100.0, ['entry_date' => (self::YEAR - 1) . '-06-10']);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE journal_entries SET document_no = NULL WHERE id IN (?, ?)')->execute([$open, $closed]);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$closedPeriod]);

        $backfill = new DocumentEntryNumberBackfill($this->db);
        $dry = $backfill->run($this->supplierId, false);
        $ids = array_column($dry['changes'], 'entry_id');
        self::assertContains($open, $ids);
        self::assertNotContains($closed, $ids, 'Uzavřené období zůstává beze změny.');
        self::assertNull($this->documentNo($open), 'Dry-run nic nezapisuje.');

        $backfill->run($this->supplierId, true);
        self::assertSame('FV-DOCNO-4', $this->documentNo($open));
        self::assertNull($this->documentNo($closed));
        self::assertSame(0, $backfill->run($this->supplierId, false)['changed'], 'Druhý běh nemá co měnit.');
    }

    /** @param array<string,mixed> $meta */
    private function post(string $type, int $id, string $debit, string $credit, float $amount, array $meta = []): int
    {
        return $this->posting->postDocument($this->supplierId, $type, $id, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], $meta + ['entry_date' => self::YEAR . '-06-10', 'posted_by' => $this->userId, 'user_id' => $this->userId]);
    }

    private function documentNo(int $entryId): ?string
    {
        $no = $this->db->pdo()->query("SELECT document_no FROM journal_entries WHERE id = {$entryId}")->fetchColumn();
        return $no === false || $no === null ? null : (string) $no;
    }
}
