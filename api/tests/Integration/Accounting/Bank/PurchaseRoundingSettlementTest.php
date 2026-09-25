<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\Bank\PurchaseRoundingSettlementBackfill;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PHPUnit\Framework\Attributes\Group;

/**
 * Přijatá faktura se zaokrouhlením (total_with_vat + rounding = částka k úhradě).
 *
 * Předpis jde na 321 v nominálu (základ + DPH), zaokrouhlení do DPH nevstupuje.
 * Úhrada se platí zaokrouhleně a rozdíl je náklad/výnos ze zaokrouhlení 548/648 —
 * stejně jako u vydaných faktur ({@see BankPostingService::normalizeRoundingFullInvoice()}).
 * Doklad musí po úhradě přesné částky k úhradě skončit s nulou na 321 a „Zbývá
 * uhradit" 0, i když párování mělo jen skóre 65 (shoda částky a data) a i u dobropisu.
 */
#[Group('integration')]
final class PurchaseRoundingSettlementTest extends BankPostingTestCase
{
    private function roundedPurchase(string $tag, float $total, float $rounding, string $kind = 'invoice'): int
    {
        $vendor = $this->client('Dodavatel ' . $tag);
        $id = $this->purchaseInvoice('PF-' . $tag, $vendor, $total, $kind);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET rounding = ? WHERE id = ?')->execute([$rounding, $id]);
        return $id;
    }

    private function match(int $tx, int $purchase, float $amount, string $type, ?int $confidence): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $tx, $purchase, $amount, $type, $confidence]);
    }

    /** Saldo 321 dokladu z deníku: předpis + úhrada (MD − D), jen živé zápisy. */
    private function payableBalance(int $purchase, int $tx): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entries je
               JOIN journal_entry_lines l ON l.entry_id = je.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE je.supplier_id = ? AND je.reversed_by IS NULL AND a.account_code LIKE '321%'
                AND ((je.source_type = 'purchase_invoice' AND je.source_id = ?)
                  OR (je.source_type = 'bank' AND je.source_id = ?))"
        );
        $stmt->execute([$this->supplierId, $purchase, $tx]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function remaining(int $purchase): float
    {
        return round((float) $this->db->pdo()->query(
            'SELECT ' . PurchaseSettledExpr::remainingAmount('pi') . ' FROM purchase_invoices pi WHERE pi.id = ' . $purchase
        )->fetchColumn(), 2);
    }

    private function matchAmount(int $tx): float
    {
        return (float) $this->db->pdo()->query('SELECT amount FROM payment_matches WHERE bank_transaction_id = ' . $tx)->fetchColumn();
    }

    private function bankEntryId(int $tx): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id = {$this->supplierId} AND source_type = 'bank'
                AND source_id = {$tx} AND reversed_by IS NULL"
        )->fetchColumn();
    }

    /** Zaokrouhlení nahoru (+0,91): zaplaceno 16 371,00, na 321 nula, 0,91 je náklad 548. */
    public function testRoundedUpPaymentClosesPayableAndPostsExpense(): void
    {
        $pf = $this->roundedPurchase('ZAOKR-PLUS', 16370.09, 0.91);
        $predpis = $this->postPredpis('purchase_invoice', $pf, '518', '321', 16370.09);
        $tx = $this->transaction($this->statement(), -16371.00, ['match_status' => 'auto_partial']);
        $this->match($tx, $pf, 16371.00, 'auto', 65);

        self::assertNotNull($this->service->postMatched($this->supplierId, $tx, $this->userId));

        self::assertEqualsWithDelta(16370.09, $this->matchAmount($tx), 0.001, 'úhrada se srovná na nominál předpisu');
        $lines = $this->linesByAccountCode($this->bankEntryId($tx));
        self::assertEqualsWithDelta(16370.09, $lines['321']['debit'], 0.001);
        self::assertEqualsWithDelta(16371.00, $lines['221']['credit'], 0.001);
        self::assertEqualsWithDelta(0.91, $lines['548']['debit'], 0.001, 'zaokrouhlení nahoru je náklad');
        self::assertSame(0.0, $this->payableBalance($pf, $tx));
        self::assertSame(0.0, $this->remaining($pf), 'Zbývá uhradit 0');

        // Předpis (a s ním DPH) zůstává v nominálu, zaokrouhlení do něj nevstupuje.
        $predpisLines = $this->linesByAccountCode($predpis);
        self::assertEqualsWithDelta(16370.09, $predpisLines['321']['credit'], 0.001);
        self::assertArrayNotHasKey('548', $predpisLines);
    }

    /** Zaokrouhlení dolů (−0,35): zaplaceno 859,00, 0,35 je výnos 648. */
    public function testRoundedDownPaymentPostsIncome(): void
    {
        $pf = $this->roundedPurchase('ZAOKR-MINUS', 859.35, -0.35);
        $this->postPredpis('purchase_invoice', $pf, '518', '321', 859.35);
        $tx = $this->transaction($this->statement(), -859.00, ['match_status' => 'auto_partial']);
        $this->match($tx, $pf, 859.00, 'auto', 65);

        $this->service->postMatched($this->supplierId, $tx, $this->userId);

        $lines = $this->linesByAccountCode($this->bankEntryId($tx));
        self::assertEqualsWithDelta(859.35, $lines['321']['debit'], 0.001);
        self::assertEqualsWithDelta(0.35, $lines['648']['credit'], 0.001, 'zaokrouhlení dolů je výnos');
        self::assertSame(0.0, $this->payableBalance($pf, $tx));
        self::assertSame(0.0, $this->remaining($pf));
    }

    /** Dobropis −4 453,19 se zaokrouhlením +0,19: vratka 4 453,00, 0,19 je náklad 548. */
    public function testCreditNoteRefundClosesPayable(): void
    {
        $cn = $this->roundedPurchase('ZAOKR-DOBROPIS', -4453.19, 0.19, 'credit_note');
        $this->postPredpis('purchase_invoice', $cn, '321', '518', 4453.19);
        $tx = $this->transaction($this->statement(), 4453.00, ['match_status' => 'manual']);
        $this->match($tx, $cn, 4453.00, 'manual', null);

        $this->service->postMatched($this->supplierId, $tx, $this->userId);

        self::assertEqualsWithDelta(4453.19, $this->matchAmount($tx), 0.001);
        $lines = $this->linesByAccountCode($this->bankEntryId($tx));
        self::assertEqualsWithDelta(4453.00, $lines['221']['debit'], 0.001);
        self::assertEqualsWithDelta(4453.19, $lines['321']['credit'], 0.001);
        self::assertEqualsWithDelta(0.19, $lines['548']['debit'], 0.001, 'vrátili méně → náklad');
        self::assertSame(0.0, $this->payableBalance($cn, $tx));
        self::assertSame(0.0, $this->remaining($cn));
    }

    /** Slabé párování BEZ zaokrouhlení na dokladu dál čeká na člověka (práh 70 platí). */
    public function testWeakMatchWithoutDeclaredRoundingStaysUntouched(): void
    {
        $pf = $this->roundedPurchase('BEZ-ZAOKR', 1000.00, 0.0);
        $this->postPredpis('purchase_invoice', $pf, '518', '321', 1000.00);
        $tx = $this->transaction($this->statement(), -999.50, ['match_status' => 'auto_partial']);
        $this->match($tx, $pf, 999.50, 'auto', 65);

        self::assertFalse($this->service->normalizeRoundingFullPurchase($this->supplierId, $tx));
        self::assertEqualsWithDelta(999.50, $this->matchAmount($tx), 0.001);
    }

    /**
     * Už zaúčtovaná úhrada bez dorovnání v datu zamčeném podaným DPH: dávka ji přepíše
     * NA MÍSTĚ (týž zápis, datum), dry-run nic nezapíše, druhý běh nemá co dělat.
     */
    public function testBackfillRewritesLockedEntryInPlaceAndIsIdempotent(): void
    {
        $pf = $this->roundedPurchase('BACKFILL', 16370.09, 0.91);
        $predpis = $this->postPredpis('purchase_invoice', $pf, '518', '321', 16370.09);
        $tx = $this->transaction($this->statement(), -16371.00, ['match_status' => 'auto_partial']);
        $this->match($tx, $pf, 16371.00, 'auto', 65);
        $entry = $this->postPredpis('bank', $tx, '321', '221', 16371.00);
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, self::YEAR . '-12-31']);
        self::assertSame(0.91, $this->payableBalance($pf, $tx), 'výchozí stav: přeplatek 0,91 na straně MD 321');

        /** @var PurchaseRoundingSettlementBackfill $backfill */
        $backfill = $this->container->get(PurchaseRoundingSettlementBackfill::class);
        $dry = $backfill->run($this->supplierId, false);
        self::assertSame(1, $dry['fixed']);
        self::assertEqualsWithDelta(16371.00, $this->matchAmount($tx), 0.001, 'dry-run nic nezapíše');

        $applied = $backfill->run($this->supplierId, true, $this->userId);
        self::assertSame(1, $applied['fixed'], json_encode($applied['rows'], JSON_UNESCAPED_UNICODE));
        self::assertSame($entry, $this->bankEntryId($tx), 'přepis na místě, žádné storno');
        self::assertSame(1, $this->entryCountForTx($tx));
        $lines = $this->linesByAccountCode($entry);
        self::assertEqualsWithDelta(16370.09, $lines['321']['debit'], 0.001);
        self::assertEqualsWithDelta(0.91, $lines['548']['debit'], 0.001);
        self::assertSame(self::YEAR . '-06-10', (string) $this->db->pdo()->query("SELECT entry_date FROM journal_entries WHERE id = {$entry}")->fetchColumn());
        self::assertSame(0.0, $this->payableBalance($pf, $tx));
        self::assertSame(0.0, $this->remaining($pf));
        self::assertEqualsWithDelta(16370.09, $this->linesByAccountCode($predpis)['321']['credit'], 0.001, 'předpis beze změny');

        self::assertSame(0, $backfill->run($this->supplierId, true)['candidates'], 'druhý běh nemá co dělat');
    }

    /** Období, které není otevřené, dávka nepřepisuje — jen ho vypíše. */
    public function testBackfillSkipsPeriodThatIsNotOpen(): void
    {
        $pf = $this->roundedPurchase('SCHVALENO', 859.35, -0.35);
        $this->postPredpis('purchase_invoice', $pf, '518', '321', 859.35);
        $tx = $this->transaction($this->statement(), -859.00, ['match_status' => 'auto_partial']);
        $this->match($tx, $pf, 859.00, 'auto', 65);
        $entry = $this->postPredpis('bank', $tx, '321', '221', 859.00);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'approved' WHERE id = ?")->execute([$this->periodId]);

        $report = $this->container->get(PurchaseRoundingSettlementBackfill::class)->run($this->supplierId, true);

        self::assertSame(0, $report['fixed']);
        $row = array_values(array_filter($report['rows'], static fn (array $r): bool => $r['tx_id'] === $tx));
        self::assertSame('period_not_open', $row[0]['status'] ?? null);
        self::assertEqualsWithDelta(859.00, $this->matchAmount($tx), 0.001);
        self::assertEqualsWithDelta(859.00, $this->linesByAccountCode($entry)['321']['debit'], 0.001);
    }
}
