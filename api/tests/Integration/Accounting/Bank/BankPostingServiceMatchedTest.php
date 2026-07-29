<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use PHPUnit\Framework\Attributes\Group;

/**
 * Spárované platby FV/PF → přímý zápis (guard předpisu H1 + M1). §8.
 */
#[Group('integration')]
final class BankPostingServiceMatchedTest extends BankPostingTestCase
{
    public function testIncomingFvPosts221Over311(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-2099-1', $client, 5000.00);
        $this->postPredpis('invoice', $inv, '311', '602', 5000.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 5000.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 5000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);

        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(5000.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(5000.00, $byAcc['311']['credit'], 0.001);
    }

    public function testOutgoingPfPosts321Over221(): void
    {
        $vendor = $this->client('Dodavatel s.r.o.');
        $pf = $this->purchaseInvoice('PF-2099-1', $vendor, 3000.00);
        $this->postPredpis('purchase_invoice', $pf, '501', '321', 3000.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -3000.00, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $pf, 3000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);

        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(3000.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(3000.00, $byAcc['221']['credit'], 0.001);
    }

    public function testProformaPaymentPostsAdvanceCollection(): void
    {
        // B5: platba proformy = inkaso přijaté zálohy 221/324 (dřív blanket skip
        // 'document_not_posted' — proforma se neúčtovala vůbec).
        $client = $this->client('Odběratel s.r.o.');
        $pro = $this->saleInvoice('PRO-2099-1', $client, 4000.00, 'proforma');
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 4000.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $pro]);
        $this->invoicePayment($pro, $tx, 4000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);

        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(4000.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(4000.00, $byAcc['324']['credit'], 0.001);
        self::assertArrayNotHasKey('311', $byAcc, 'Proforma nesmí zakládat saldokonto 311.');
    }

    public function testSuggestedMatchedPaymentApprovalRebuildsExactAllocations(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE auto_posting_policy SET level = 'suggest'
              WHERE supplier_id = ? AND operation_type = 'bank.payment.matched'"
        )->execute([$this->supplierId]);

        $client = $this->client('Odběratel kombinované platby');
        $invoice = $this->saleInvoice('FV-2099-POLICY', $client, 3000.00);
        $proforma = $this->saleInvoice('PRO-2099-POLICY', $client, 2000.00, 'proforma');
        $this->postPredpis('invoice', $invoice, '311', '602', 3000.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 5000.00, ['match_status' => 'manual']);
        $this->invoicePayment($invoice, $tx, 3000.00);
        $this->invoicePayment($proforma, $tx, 2000.00);

        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $result['action']);
        $entryId = $this->service->approveSuggestion(
            $this->supplierId,
            (int) $result['suggestion_id'],
            ['user_id' => $this->userId],
        );

        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(5000.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(3000.00, $byAcc['311']['credit'], 0.001);
        self::assertEqualsWithDelta(2000.00, $byAcc['324']['credit'], 0.001);
    }

    public function testFvWithoutPostedPredpisIsSkipped(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-2099-2', $client, 2500.00); // žádný předpis v deníku
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 2500.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 2500.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('document_not_posted', $res['reason']);
        self::assertSame(0, $this->entryCountForTx($tx));
        self::assertSame(0, $this->suggestionCountForTx($tx));
    }

    public function testFvWithReversedPredpisIsSkipped(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-2099-3', $client, 2500.00);
        $predpis = $this->postPredpis('invoice', $inv, '311', '602', 2500.00);
        $this->posting->reverse($this->supplierId, $predpis, ['entry_date' => self::YEAR . '-06-11', 'user_id' => $this->userId]);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 2500.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 2500.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('document_not_posted', $res['reason']);
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    public function testPaidInvoiceWithoutPaymentRowSuggestsVerify(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-2099-4', $client, 1800.00, 'invoice', 'paid');
        $this->postPredpis('invoice', $inv, '311', '602', 1800.00);

        $stmt = $this->statement();
        // paid ∧ žádný invoice_payments řádek → člověk ověří (M1).
        $tx = $this->transaction($stmt, 1800.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $res['action']);
        self::assertSame('already_paid_verify', $res['reason']);
        $sug = $this->suggestionRow((int) $res['suggestion_id']);
        self::assertSame('payment_match', $sug['source']);
        self::assertSame('already_paid_verify', $sug['note']);
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    public function testPaidInvoiceWithUniqueLegacyPaymentIsReconciledAndPosted(): void
    {
        $client = $this->client('Odběratel s historickou úhradou');
        $inv = $this->saleInvoice('FV-2099-LEGACY', $client, 12100.00, 'invoice', 'paid');
        $this->db->pdo()->prepare('UPDATE invoices SET paid_total = 12100, paid_at = ? WHERE id = ?')
            ->execute([self::YEAR . '-06-12', $inv]);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, 12100, "CZK", "legacy", NULL)'
        )->execute([$this->supplierId, $inv, self::YEAR . '-06-12']);
        $paymentId = (int) $this->db->pdo()->lastInsertId();
        $this->postPredpis('invoice', $inv, '311', '602', 12100.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 12100.00, [
            'match_status' => 'auto_exact',
            'matched_invoice_id' => $inv,
            'variable_symbol' => 'FV2099LEGACY',
        ]);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action']);
        self::assertSame($tx, (int) $this->db->pdo()->query(
            "SELECT bank_transaction_id FROM invoice_payments WHERE id={$paymentId}"
        )->fetchColumn());
        self::assertSame(1, $this->entryCountForTx($tx));
        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(12100.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(12100.00, $byAcc['311']['credit'], 0.001);
    }

    public function testOutgoingIssuedCreditNoteRefundPosts311Over221(): void
    {
        $client = $this->client('Odběratel dobropisu');
        $credit = $this->saleInvoice('ODD-2099-EP8', $client, -1000.00, 'credit_note');
        $this->postPredpis('invoice', $credit, '602', '311', 1000.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000.00, ['match_status' => 'manual', 'matched_invoice_id' => $credit]);
        $this->db->pdo()->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, invoice_id, amount, match_type)
             VALUES (?, ?, ?, 1000, "manual")'
        )->execute([$this->supplierId, $tx, $credit]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);
        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(1000.00, $byAcc['311']['debit'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byAcc['221']['credit'], 0.001);
    }

    public function testIncomingPurchaseCreditNoteRefundPosts221Over321(): void
    {
        $vendor = $this->client('Dodavatel dobropisu');
        $credit = $this->purchaseInvoice('ODD-PF-2099-EP8', $vendor, -800.00, 'credit_note');
        $this->postPredpis('purchase_invoice', $credit, '321', '501', 800.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 800.00, ['match_status' => 'manual']);
        $this->db->pdo()->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, 800, "manual")'
        )->execute([$this->supplierId, $tx, $credit]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);
        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(800.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(800.00, $byAcc['321']['credit'], 0.001);
    }

    public function testForeignRuleSuggestionApprovalUsesCzkRateAndFxTrace(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, "EUR", 25.00)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([self::YEAR . '-06-15']);
        $this->rule([
            'name' => 'Úrok EUR',
            'direction' => 'incoming',
            'counterparty_account' => '55501',
            'amount_min' => 1,
            'amount_max' => 200,
            'debit_account_code' => '221',
            'credit_account_code' => '662',
            'mode' => 'suggest',
            'applies_currency' => 'EUR',
        ]);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 100.00, [
            'currency' => 'EUR',
            'counterparty_account' => '55501',
        ]);

        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $result['action']);
        $suggestion = $this->suggestionRow((int) $result['suggestion_id']);
        self::assertEqualsWithDelta(2500.00, (float) $suggestion['amount'], 0.001);
        self::assertEqualsWithDelta(0.70, (float) $suggestion['confidence'], 0.001);

        $entryId = $this->service->approveSuggestion($this->supplierId, (int) $result['suggestion_id'], $this->meta());
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(2500.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(2500.00, $byAcc['662']['credit'], 0.001);
        $trace = $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entry_lines WHERE entry_id={$entryId}
              AND currency_code='EUR' AND fx_rate=25 AND amount_foreign=100"
        )->fetchColumn();
        self::assertSame(2, (int) $trace);
    }
}
