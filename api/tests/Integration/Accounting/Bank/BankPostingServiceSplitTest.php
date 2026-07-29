<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use PHPUnit\Framework\Attributes\Group;

/**
 * Split platba N faktur = 1 zápis; dorovnání do 1,00 Kč (548/648); mismatch >1 Kč. §8.
 */
#[Group('integration')]
final class BankPostingServiceSplitTest extends BankPostingTestCase
{
    public function testSplitTwoPurchaseInvoicesOneEntry(): void
    {
        $vendor = $this->client('Dodavatel s.r.o.');
        $pf1 = $this->purchaseInvoice('PF-S1', $vendor, 3000.00);
        $pf2 = $this->purchaseInvoice('PF-S2', $vendor, 2000.00);
        $this->postPredpis('purchase_invoice', $pf1, '501', '321', 3000.00);
        $this->postPredpis('purchase_invoice', $pf2, '501', '321', 2000.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -5000.00, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $pf1, 3000.00);
        $this->paymentMatch($tx, $pf2, 2000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);
        self::assertSame(1, $this->entryCountForTx($tx), 'Jedna transakce = jeden zápis.');

        $entry = $this->journal->find((int) $res['entry_id'], $this->supplierId);
        self::assertCount(3, $entry['lines'], '2× 321 + 1× 221.');
        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(5000.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(5000.00, $byAcc['221']['credit'], 0.001);
    }

    public function testAllocationMismatchOverToleranceSkips(): void
    {
        $vendor = $this->client('Dodavatel s.r.o.');
        $pf = $this->purchaseInvoice('PF-M1', $vendor, 3000.00);
        $this->postPredpis('purchase_invoice', $pf, '501', '321', 3000.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -5000.00, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $pf, 3000.00); // rozdíl 2000 Kč > 1 Kč

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('allocation_mismatch', $res['reason']);
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    public function testOutgoingRoundingLine548(): void
    {
        $vendor = $this->client('Dodavatel s.r.o.');
        $pf = $this->purchaseInvoice('PF-R1', $vendor, 5000.00);
        $this->postPredpis('purchase_invoice', $pf, '501', '321', 5000.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -5000.50, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $pf, 5000.00); // rozdíl 0,50 Kč ≤ 1 Kč

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);
        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(0.50, $byAcc['548']['debit'], 0.001, 'Dorovnání 548 (náklad).');
        self::assertEqualsWithDelta(5000.50, $byAcc['221']['credit'], 0.001);
    }

    public function testIncomingRoundingLine648(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-R1', $client, 5000.00);
        $this->postPredpis('invoice', $inv, '311', '602', 5000.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 5000.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 4999.50); // rozdíl 0,50 Kč ≤ 1 Kč

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);
        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(0.50, $byAcc['648']['credit'], 0.001, 'Dorovnání 648 (výnos).');
        self::assertEqualsWithDelta(5000.00, $byAcc['221']['debit'], 0.001);
    }

    public function testRoundingBoundaryExactlyOneCrown(): void
    {
        $vendor = $this->client('Dodavatel s.r.o.');
        $pf = $this->purchaseInvoice('PF-B1', $vendor, 5000.00);
        $this->postPredpis('purchase_invoice', $pf, '501', '321', 5000.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -5001.00, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $pf, 5000.00); // přesně 1,00 Kč → ještě v toleranci

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);
        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(1.00, $byAcc['548']['debit'], 0.001);
    }

    public function testFeeGapSuggestionPostsNetBankFeeAndGrossAllocations(): void
    {
        $client = $this->client('Odběratel s poplatkem s.r.o.');
        $invoice = $this->saleInvoice('FV-FEE-1', $client, 5000.00);
        $proforma = $this->saleInvoice('ZF-FEE-2', $client, 5000.00, 'proforma');
        $this->postPredpis('invoice', $invoice, '311', '602', 5000.00);

        $statement = $this->statement();
        $tx = $this->transaction($statement, 9800.00, ['match_status' => 'manual', 'matched_invoice_id' => $invoice]);
        $this->invoicePayment($invoice, $tx, 5000.00);
        $this->invoicePayment($proforma, $tx, 5000.00);
        $suggestion = $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $this->supplierId,
            'bank_transaction_id' => $tx,
            'rule_id' => null,
            'source' => 'payment_match',
            'debit_account_code' => '568',
            'credit_account_code' => '311',
            'amount' => 200.00,
            'description' => 'Stržený poplatek z úhrady',
            'note' => 'fee_gap',
        ]);

        $entryId = $this->service->approveSuggestion($this->supplierId, (int) $suggestion['id'], $this->meta());
        $byAcc = $this->linesByAccountCode($entryId);

        self::assertEqualsWithDelta(9800.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(200.00, $byAcc['568']['debit'], 0.001);
        self::assertEqualsWithDelta(5000.00, $byAcc['311']['credit'], 0.001);
        self::assertEqualsWithDelta(5000.00, $byAcc['324']['credit'], 0.001);
    }
}
