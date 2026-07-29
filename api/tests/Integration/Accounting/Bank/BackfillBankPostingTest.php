<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use PHPUnit\Framework\Attributes\Group;

/**
 * Backfill CLI runner (§7): dry-run nic nezapíše + agregace skipů; --apply účtuje;
 * bez --rules se pravidla nevyhodnocují; s --rules auto → jen suggest; zavřené období
 * skip bez suggestion; druhý běh po doúčtování předpisů doúčtuje (self-healing H1). §8.
 */
#[Group('integration')]
final class BackfillBankPostingTest extends BankPostingTestCase
{
    /** Izolace na testovací data (rok 2099) — demo tx z minulých let se nezahrnou. */
    private function from(): string
    {
        return self::YEAR . '-01-01';
    }

    /** Spárovaná FV platba; $withPredpis řídí, zda existuje zaúčtovaný předpis (guard H1). */
    private function matchedPayment(string $vs, float $amount, bool $withPredpis): int
    {
        $client = $this->client('Odběratel ' . $vs);
        $inv = $this->saleInvoice('FV-' . $vs, $client, $amount);
        if ($withPredpis) {
            $this->postPredpis('invoice', $inv, '311', '602', $amount);
        }
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, $amount, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, $amount);
        return $tx;
    }

    /** @return array{invoice_id:int,payment_id:int,tx_id:int} */
    private function legacyPaidPayment(string $vs, float $amount, string $matchStatus = 'auto_exact'): array
    {
        $client = $this->client('Legacy odběratel ' . $vs);
        $inv = $this->saleInvoice('FV-' . $vs, $client, $amount, 'invoice', 'paid');
        $this->db->pdo()->prepare(
            'UPDATE invoices SET paid_total = ?, paid_at = ? WHERE id = ?'
        )->execute([$amount, self::YEAR . '-06-15', $inv]);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, ?, ?, "CZK", "legacy")'
        )->execute([$this->supplierId, $inv, self::YEAR . '-06-15', $amount]);
        $paymentId = (int) $this->db->pdo()->lastInsertId();
        $this->postPredpis('invoice', $inv, '311', '602', $amount);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, $amount, [
            'match_status'      => $matchStatus,
            'matched_invoice_id' => $inv,
            'variable_symbol'  => null,
        ]);
        return ['invoice_id' => $inv, 'payment_id' => $paymentId, 'tx_id' => $tx];
    }

    public function testDryRunWritesNothingAndAggregatesSkips(): void
    {
        $postable = $this->matchedPayment('BF1', 1000.00, true);
        $this->matchedPayment('BF2', 2000.00, false); // document_not_posted

        $report = $this->backfill->run($this->supplierId, $this->from(), false, false);

        self::assertTrue($report['dry_run']);
        self::assertSame(1, $report['posted']);
        self::assertArrayHasKey('document_not_posted', $report['skip_reasons']);
        self::assertSame(1, $report['skip_reasons']['document_not_posted']);
        // Dry-run nic nezapsal (SAVEPOINT rollback).
        self::assertSame(0, $this->entryCountForTx($postable), 'Dry-run nezaložil žádný zápis.');
    }

    public function testApplyPostsMatchedPayments(): void
    {
        $postable = $this->matchedPayment('BF3', 1500.00, true);
        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);

        self::assertFalse($report['dry_run']);
        self::assertSame(1, $report['posted']);
        self::assertSame(1, $this->entryCountForTx($postable), 'Ostrý běh zaúčtoval platbu.');
    }

    public function testApplyReconcilesSingleLegacyPaymentWithoutVariableSymbol(): void
    {
        $data = $this->legacyPaidPayment('BF-LEGACY', 1750.00);

        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);

        self::assertSame(1, $report['posted']);
        self::assertSame(1, $report['reconciled_legacy']);
        self::assertSame(1, $this->entryCountForTx($data['tx_id']));
        $payment = $this->db->pdo()->query(
            'SELECT source, bank_transaction_id FROM invoice_payments WHERE id = ' . $data['payment_id']
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('legacy', $payment['source']);
        self::assertSame($data['tx_id'], (int) $payment['bank_transaction_id']);

        $second = $this->backfill->run($this->supplierId, $this->from(), true, false);
        self::assertSame(0, $second['posted']);
        self::assertSame(0, $second['reconciled_legacy']);
        self::assertSame(1, (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ' . $data['invoice_id']
        )->fetchColumn());
    }

    public function testActivationReconcilesLegacyPaymentDespiteSuggestPolicy(): void
    {
        $data = $this->legacyPaidPayment('BF-ACTIVATION', 1760.00);
        $this->db->pdo()->prepare(
            "UPDATE auto_posting_policy SET level = 'suggest'
              WHERE supplier_id = ? AND operation_type = 'bank.payment.matched'"
        )->execute([$this->supplierId]);

        $report = $this->backfill->run(
            $this->supplierId,
            $this->from(),
            true,
            false,
            $this->userId,
            true,
        );

        self::assertSame(1, $report['posted']);
        self::assertSame(1, $report['reconciled_legacy']);
        self::assertSame(0, $report['suggested']);
        self::assertSame(1, $this->entryCountForTx($data['tx_id']));
        self::assertSame($data['tx_id'], (int) $this->db->pdo()->query(
            'SELECT bank_transaction_id FROM invoice_payments WHERE id = ' . $data['payment_id']
        )->fetchColumn());
        self::assertNull($this->suggestionRepo->pendingForTx($this->supplierId, $data['tx_id']));
    }

    public function testAutoPartialExistingMatchIsReconciledAndPostedWithoutVariableSymbol(): void
    {
        $data = $this->legacyPaidPayment('BF-PARTIAL', 1800.00, 'auto_partial');

        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);

        self::assertSame(1, $report['posted']);
        self::assertSame(1, $report['reconciled_legacy']);
        self::assertSame(1, $this->entryCountForTx($data['tx_id']));
    }

    public function testLegacyReconciliationDryRunRollsBackLinkAndJournal(): void
    {
        $data = $this->legacyPaidPayment('BF-DRY-LEGACY', 1900.00);

        $report = $this->backfill->run($this->supplierId, $this->from(), false, false);

        self::assertSame(1, $report['posted']);
        self::assertSame(1, $report['reconciled_legacy']);
        self::assertSame(0, $this->entryCountForTx($data['tx_id']));
        $linked = $this->db->pdo()->query(
            'SELECT bank_transaction_id FROM invoice_payments WHERE id = ' . $data['payment_id']
        )->fetchColumn();
        self::assertNull($linked);
    }

    public function testApplyReconcilesPaidProformaWithoutInvoiceJournalEntry(): void
    {
        $client = $this->client('Odběratel zálohy');
        $proforma = $this->saleInvoice('900101', $client, 4800.00, 'proforma', 'paid');
        $this->db->pdo()->prepare(
            'UPDATE invoices SET paid_total = 4800.00, paid_at = ? WHERE id = ?'
        )->execute([self::YEAR . '-06-15', $proforma]);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, ?, 4800.00, "CZK", "legacy")'
        )->execute([$this->supplierId, $proforma, self::YEAR . '-06-15']);
        $paymentId = (int) $this->db->pdo()->lastInsertId();
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 4800.00, [
            'match_status'       => 'auto_exact',
            'matched_invoice_id' => $proforma,
            'variable_symbol'    => null,
        ]);

        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);

        self::assertSame(1, $report['posted']);
        self::assertSame(1, $report['reconciled_legacy']);
        self::assertSame($tx, (int) $this->db->pdo()->query(
            'SELECT bank_transaction_id FROM invoice_payments WHERE id = ' . $paymentId
        )->fetchColumn());
        $entryId = (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE source_type='bank' AND source_id={$tx}"
        )->fetchColumn();
        $lines = $this->linesByAccountCode($entryId);
        self::assertArrayHasKey('221', $lines);
        self::assertArrayHasKey('324', $lines);
        self::assertEqualsWithDelta(4800.00, $lines['221']['debit'], 0.001);
        self::assertEqualsWithDelta(4800.00, $lines['324']['credit'], 0.001);
    }

    public function testApplyRespectsExplicitMatchForMarkPaidPayment(): void
    {
        $client = $this->client('Odběratel mark paid');
        $invoice = $this->saleInvoice('900102', $client, 3200.00, 'invoice', 'paid');
        $this->db->pdo()->prepare(
            'UPDATE invoices SET paid_total = 3200.00, paid_at = ? WHERE id = ?'
        )->execute([self::YEAR . '-06-15', $invoice]);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, ?, 3200.00, "CZK", "mark_paid")'
        )->execute([$this->supplierId, $invoice, self::YEAR . '-06-15']);
        $this->postPredpis('invoice', $invoice, '311', '602', 3200.00);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 3200.00, [
            'match_status'       => 'manual',
            'matched_invoice_id' => $invoice,
        ]);

        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);

        self::assertSame(1, $report['posted']);
        self::assertSame(1, $report['reconciled_legacy']);
        self::assertSame(1, $this->entryCountForTx($tx));
    }

    public function testExplicitAllocationIsPostedEvenWhenTransactionStatusIsUnmatched(): void
    {
        $client = $this->client('Odběratel explicitní alokace');
        $invoice = $this->saleInvoice('900103', $client, 2100.00);
        $this->postPredpis('invoice', $invoice, '311', '602', 2100.00);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 2100.00, ['match_status' => 'unmatched']);
        $this->invoicePayment($invoice, $tx, 2100.00);

        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);

        self::assertSame(1, $report['posted']);
        self::assertSame(1, $this->entryCountForTx($tx));
        self::assertArrayNotHasKey('rules_disabled', $report['skip_reasons']);
    }

    public function testLegacyFxPaymentWithStaleCurrencyUsesExplicitInvoiceMatch(): void
    {
        $eurId = $this->currencyRow($this->supplierId, 'EUR');
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, "EUR", 25.200000)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([self::YEAR . '-06-15']);
        $client = $this->client('Odběratel EUR');
        $invoice = $this->saleInvoice('900104', $client, 100.00, 'invoice', 'paid');
        $this->db->pdo()->prepare(
            'UPDATE invoices SET currency_id = ?, exchange_rate = 25.000000,
                    paid_total = 100.00, paid_at = ? WHERE id = ?'
        )->execute([$eurId, self::YEAR . '-06-15', $invoice]);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, ?, 100.00, "CZK", "legacy")'
        )->execute([$this->supplierId, $invoice, self::YEAR . '-06-15']);
        $paymentId = (int) $this->db->pdo()->lastInsertId();
        $this->postPredpis('invoice', $invoice, '311', '602', 2500.00);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 100.00, [
            'currency'           => 'EUR',
            'match_status'       => 'manual',
            'matched_invoice_id' => $invoice,
        ]);

        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);

        self::assertSame(1, $report['posted']);
        self::assertSame(1, $report['reconciled_legacy']);
        $payment = $this->db->pdo()->query(
            'SELECT currency, bank_transaction_id FROM invoice_payments WHERE id = ' . $paymentId
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('EUR', $payment['currency']);
        self::assertSame($tx, (int) $payment['bank_transaction_id']);
    }

    public function testUniqueLegacyProformaIsDiscoveredByNormalizedVariableSymbol(): void
    {
        $client = $this->client('Odběratel nalezené zálohy');
        $proforma = $this->saleInvoice('000-900105', $client, 7600.00, 'proforma', 'paid');
        $this->db->pdo()->prepare(
            'UPDATE invoices SET paid_total = 7600.00, paid_at = ? WHERE id = ?'
        )->execute([self::YEAR . '-06-15', $proforma]);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, ?, 7600.00, "CZK", "legacy")'
        )->execute([$this->supplierId, $proforma, self::YEAR . '-06-15']);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 7600.00, [
            'match_status'    => 'unmatched',
            'variable_symbol' => '0900105',
        ]);

        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);

        self::assertSame(1, $report['posted']);
        self::assertSame(1, $report['reconciled_legacy']);
        $matched = $this->db->pdo()->query(
            'SELECT matched_invoice_id, match_status FROM bank_transactions WHERE id = ' . $tx
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($proforma, (int) $matched['matched_invoice_id']);
        self::assertSame('auto_exact', $matched['match_status']);
    }

    public function testLegacyDiscoveryRejectsAmbiguousBankTransactions(): void
    {
        $client = $this->client('Odběratel nejednoznačné zálohy');
        $proforma = $this->saleInvoice('900106', $client, 8100.00, 'proforma', 'paid');
        $this->db->pdo()->prepare(
            'UPDATE invoices SET paid_total = 8100.00, paid_at = ? WHERE id = ?'
        )->execute([self::YEAR . '-06-15', $proforma]);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, ?, 8100.00, "CZK", "legacy")'
        )->execute([$this->supplierId, $proforma, self::YEAR . '-06-15']);
        $stmt = $this->statement();
        $first = $this->transaction($stmt, 8100.00, ['variable_symbol' => '900106']);
        $second = $this->transaction($stmt, 8100.00, [
            'variable_symbol' => '900106',
            'posted_at'       => self::YEAR . '-06-16',
        ]);

        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);

        self::assertSame(0, $report['posted']);
        self::assertSame(0, $this->entryCountForTx($first));
        self::assertSame(0, $this->entryCountForTx($second));
        self::assertSame(2, $report['skip_reasons']['rules_disabled'] ?? 0);
    }

    public function testApplyNormalizesRoundingPartialPurchaseAndRewritesExistingEntry(): void
    {
        $vendor = $this->client('Dodavatel BF partial');
        $purchase = $this->purchaseInvoice('PF-BF-PARTIAL', $vendor, 1000.00);
        $this->postPredpis('purchase_invoice', $purchase, '518', '321', 1000.00);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -999.50, ['match_status' => 'auto_partial']);
        $this->db->pdo()->prepare(
            'INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
             VALUES (?, ?, ?, 999.50, "auto", 70)'
        )->execute([$this->supplierId, $tx, $purchase]);
        $existing = $this->postPredpis('bank', $tx, '321', '221', 999.50);

        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);

        self::assertSame(1, $report['posted']);
        self::assertSame(1, $report['normalized_full']);
        self::assertSame(1, $this->entryCountForTx($tx), 'Existující bankovní zápis se přepíše, neduplikuje.');
        self::assertSame('paid', $this->db->pdo()->query(
            'SELECT status FROM purchase_invoices WHERE id = ' . $purchase
        )->fetchColumn());
        self::assertSame('auto_exact', $this->db->pdo()->query(
            'SELECT match_status FROM bank_transactions WHERE id = ' . $tx
        )->fetchColumn());
        self::assertEqualsWithDelta(1000.00, (float) $this->db->pdo()->query(
            'SELECT amount FROM payment_matches WHERE bank_transaction_id = ' . $tx
        )->fetchColumn(), 0.001);
        $lines = $this->linesByAccountCode($existing);
        self::assertEqualsWithDelta(1000.00, $lines['321']['debit'], 0.001);
        self::assertEqualsWithDelta(999.50, $lines['221']['credit'], 0.001);
        self::assertEqualsWithDelta(0.50, $lines['648']['credit'], 0.001);
    }

    public function testApplyPostsCzkCardPaymentForEurPurchaseAsFullFxPayment(): void
    {
        $eurId = (int) ($this->db->pdo()->query(
            "SELECT id FROM currencies WHERE supplier_id={$this->supplierId} AND code='EUR' LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($eurId === 0) {
            $eurId = $this->currencyRow($this->supplierId, 'EUR');
        }
        $vendor = $this->client('Dodavatel EUR kartou');
        $purchase = $this->purchaseInvoice('PF-BF-EUR-CARD', $vendor, 180.00);
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET currency_id = ?, exchange_rate = 24.300000 WHERE id = ?'
        )->execute([$eurId, $purchase]);
        $this->postPredpis('purchase_invoice', $purchase, '518', '321', 4374.00);
        $stmt = $this->statement('999999999', '9999');
        $tx = $this->transaction($stmt, -4374.90, [
            'match_status' => 'auto_partial',
            'currency'     => 'CZK',
            'variable_symbol' => null,
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
             VALUES (?, ?, ?, 4374.90, "auto", 65)'
        )->execute([$this->supplierId, $tx, $purchase]);

        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);

        self::assertSame(1, $report['posted']);
        self::assertSame(1, $report['normalized_full']);
        self::assertSame('paid', $this->db->pdo()->query(
            'SELECT status FROM purchase_invoices WHERE id = ' . $purchase
        )->fetchColumn());
        self::assertSame('auto_exact', $this->db->pdo()->query(
            'SELECT match_status FROM bank_transactions WHERE id = ' . $tx
        )->fetchColumn());
        self::assertEqualsWithDelta(4374.90, (float) $this->db->pdo()->query(
            'SELECT amount FROM payment_matches WHERE bank_transaction_id = ' . $tx
        )->fetchColumn(), 0.001, 'FX alokace zůstává v měně transakce (CZK).');
        $entryId = (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id={$this->supplierId}
              AND source_type='bank' AND source_id={$tx}"
        )->fetchColumn();
        $lines = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(4374.00, $lines['321']['debit'], 0.001);
        self::assertEqualsWithDelta(4374.90, $lines['221']['credit'], 0.001);
        self::assertEqualsWithDelta(0.90, $lines['563']['debit'], 0.001);
    }

    public function testWithoutRulesUnmatchedNotEvaluated(): void
    {
        $this->rule([
            'name' => 'Odvod', 'direction' => 'outgoing', 'counterparty_account' => '778901',
            'amount_min' => 100.00, 'amount_max' => 90000.00,
            'debit_account_code' => '336', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000.00, ['counterparty_account' => '778901']);

        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);
        self::assertSame(0, $report['suggested']);
        self::assertSame(0, $this->suggestionCountForTx($tx), 'Bez --rules žádný návrh.');
        self::assertArrayHasKey('rules_disabled', $report['skip_reasons']);
    }

    public function testWithRulesAutoDegradesToSuggest(): void
    {
        $this->rule([
            'name' => 'Odvod', 'direction' => 'outgoing', 'counterparty_account' => '778902',
            'amount_min' => 100.00, 'amount_max' => 90000.00,
            'debit_account_code' => '336', 'credit_account_code' => '221', 'mode' => 'auto',
        ]);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000.00, ['counterparty_account' => '778902']);

        $report = $this->backfill->run($this->supplierId, $this->from(), true, true);
        self::assertSame(1, $report['suggested']);
        self::assertSame(0, $this->entryCountForTx($tx), 'Auto pravidlo se při backfillu neúčtuje.');
        $pending = $this->suggestionRepo->pendingForTx($this->supplierId, $tx);
        self::assertNotNull($pending, 'Vznikl jen návrh.');
    }

    public function testClosedPeriodSkippedWithoutSuggestion(): void
    {
        $this->rule([
            'name' => 'Odvod', 'direction' => 'outgoing', 'counterparty_account' => '778903',
            'amount_min' => 100.00, 'amount_max' => 90000.00,
            'debit_account_code' => '336', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000.00, ['counterparty_account' => '778903']);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $report = $this->backfill->run($this->supplierId, $this->from(), true, true);
        self::assertArrayHasKey('period_closed', $report['skip_reasons']);
        self::assertSame(0, $this->suggestionCountForTx($tx), 'Ze zavřeného období žádná suggestion.');
    }

    public function testSecondRunSelfHealsAfterPredpis(): void
    {
        // 1. běh: platba bez předpisu → skip document_not_posted.
        $client = $this->client('Odběratel BF9');
        $inv = $this->saleInvoice('FV-BF9', $client, 1200.00);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1200.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 1200.00);

        $r1 = $this->backfill->run($this->supplierId, $this->from(), true, false);
        self::assertSame(0, $r1['posted']);
        self::assertSame(0, $this->entryCountForTx($tx));

        // Doúčtování předpisu → 2. běh doúčtuje přeskočenou platbu (self-healing H1).
        $this->postPredpis('invoice', $inv, '311', '602', 1200.00);
        $r2 = $this->backfill->run($this->supplierId, $this->from(), true, false);
        self::assertSame(1, $r2['posted']);
        self::assertSame(1, $this->entryCountForTx($tx));

        // 3. běh je no-op (zápis už existuje → mimo kandidáty).
        $r3 = $this->backfill->run($this->supplierId, $this->from(), true, false);
        self::assertSame(0, $r3['posted']);
        self::assertSame(0, $r3['candidates']);
    }
}
