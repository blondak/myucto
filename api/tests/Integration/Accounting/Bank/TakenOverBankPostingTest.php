<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pohyb se zápisem převzatým z jiného účetního programu zaúčtoval zdroj. Automatika
 * MyÚčta (import výpisu, dávka doúčtování, vypořádání karet) jeho párování, alokaci
 * ani zápis nesrovnává a nepřepisuje, jinak se převedený deník rozejde s deníkem zdroje.
 * Nativní pohyb v témž stavu se dorovná jako dřív (PurchaseRoundingSettlementTest,
 * IssuedRoundingNormalizationTest).
 */
#[Group('integration')]
final class TakenOverBankPostingTest extends BankPostingTestCase
{
    private function takeOver(int $entryId, string $key): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO premier_import_map (supplier_id, kind, premier_key, target_id) VALUES (?, 'journal_entry', ?, ?)"
        )->execute([$this->supplierId, self::YEAR . '|' . $key, $entryId]);
    }

    private function roundedPurchase(string $tag, float $total, float $rounding): int
    {
        $id = $this->purchaseInvoice('PF-' . $tag, $this->client('Dodavatel ' . $tag), $total);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET rounding = ? WHERE id = ?')->execute([$rounding, $id]);
        return $id;
    }

    private function autoMatch(int $tx, int $purchase, float $amount): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
             VALUES (?, ?, ?, ?, 'auto', 65)"
        )->execute([$this->supplierId, $tx, $purchase, $amount]);
    }

    private function scalar(string $sql): string
    {
        return (string) $this->db->pdo()->query($sql)->fetchColumn();
    }

    /** @return array{0:int,1:int,2:int} [doklad, pohyb, převzatý zápis pohybu] */
    private function takenOverRoundedPurchasePayment(string $tag): array
    {
        $pf = $this->roundedPurchase($tag, 72600.20, -0.20);
        $this->postPredpis('purchase_invoice', $pf, '518', '321', 72600.20);
        $tx = $this->transaction($this->statement(), -72600.00, ['match_status' => 'auto_partial']);
        $this->autoMatch($tx, $pf, 72600.00);
        $entry = $this->postPredpis('bank', $tx, '321', '221', 72600.00);
        $this->takeOver($entry, 'BV-' . $tag);
        return [$pf, $tx, $entry];
    }

    /**
     * N1: opakovaný import výpisu (bankovní API, cron) projde i existující pohyb a zavolá
     * na něj engine. Převzatá úhrada zůstává: párování 72 600,00, zápis bez 648.
     */
    public function testStatementReimportLeavesTakenOverPurchasePaymentAsTheSourceBookedIt(): void
    {
        [, $tx, $entry] = $this->takenOverRoundedPurchasePayment('REIMPORT');

        $result = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame(['action' => 'skipped', 'reason' => 'taken_over'], $result);
        self::assertEqualsWithDelta(72600.00, (float) $this->scalar("SELECT amount FROM payment_matches WHERE bank_transaction_id = {$tx}"), 0.001);
        self::assertSame('auto_partial', $this->scalar("SELECT match_status FROM bank_transactions WHERE id = {$tx}"));
        $lines = $this->linesByAccountCode($entry);
        self::assertEqualsWithDelta(72600.00, $lines['321']['debit'], 0.001);
        self::assertArrayNotHasKey('648', $lines);
        self::assertSame(1, $this->entryCountForTx($tx));
    }

    /** N1: přímé zaúčtování spárované platby (postMatched) převzatý zápis nepřepíše. */
    public function testPostMatchedDoesNotRewriteTakenOverEntry(): void
    {
        [$pf, $tx, $entry] = $this->takenOverRoundedPurchasePayment('POSTMATCHED');

        self::assertNull($this->service->postMatched($this->supplierId, $tx, $this->userId));

        self::assertArrayNotHasKey('648', $this->linesByAccountCode($entry));
        self::assertSame('received', $this->scalar("SELECT status FROM purchase_invoices WHERE id = {$pf}"));
    }

    /** N1: normalizace sama na párování převzatého pohybu nesáhne (volá ji i dávka a karty). */
    public function testPurchaseNormalizationSkipsTakenOverPayment(): void
    {
        [, $tx] = $this->takenOverRoundedPurchasePayment('NORMALIZE');

        self::assertFalse($this->service->normalizeRoundingFullPurchase($this->supplierId, $tx));
        self::assertEqualsWithDelta(72600.00, (float) $this->scalar("SELECT amount FROM payment_matches WHERE bank_transaction_id = {$tx}"), 0.001);
    }

    /** N1: vydaná větev — alokace, paid_total ani stav faktury převzaté úhrady se nemění. */
    public function testIssuedNormalizationSkipsTakenOverPayment(): void
    {
        $invoice = $this->saleInvoice('77001', $this->client('Odběratel převzatý'), 1000.40);
        $this->postPredpis('invoice', $invoice, '311', '602', 1000.40);
        $tx = $this->transaction($this->statement(), 1000.00, ['match_status' => 'auto_partial', 'matched_invoice_id' => $invoice]);
        $this->invoicePayment($invoice, $tx, 1000.00);
        $this->db->pdo()->prepare('UPDATE invoices SET paid_total = 1000.00 WHERE id = ?')->execute([$invoice]);
        $entry = $this->postPredpis('bank', $tx, '221', '311', 1000.00);
        $this->takeOver($entry, 'BV-VYDANA');

        self::assertFalse($this->service->normalizeRoundingFullInvoice($this->supplierId, $tx));
        self::assertSame(['action' => 'skipped', 'reason' => 'taken_over'], $this->service->handleTransaction($tx, $this->userId));

        self::assertEqualsWithDelta(1000.00, (float) $this->scalar("SELECT amount FROM invoice_payments WHERE bank_transaction_id = {$tx}"), 0.001);
        self::assertEqualsWithDelta(1000.00, (float) $this->scalar("SELECT paid_total FROM invoices WHERE id = {$invoice}"), 0.001);
        self::assertSame('issued', $this->scalar("SELECT status FROM invoices WHERE id = {$invoice}"));
        self::assertArrayNotHasKey('548', $this->linesByAccountCode($entry));
    }

    /**
     * N8: převzatý zápis, který řádky sedí na to, co by engine zaúčtoval, se dřív „jen"
     * přerazítkoval dimenzemi dokladu. U převzatého zápisu nic, ani dimenze.
     */
    public function testMatchingTakenOverEntryIsNotRestamped(): void
    {
        $pf = $this->purchaseInvoice('PF-DIMENZE', $this->client('Dodavatel dimenze'), 500.00);
        $this->postPredpis('purchase_invoice', $pf, '518', '321', 500.00);
        $tx = $this->transaction($this->statement(), -500.00, ['match_status' => 'manual']);
        $this->paymentMatch($tx, $pf, 500.00);
        $entry = $this->postPredpis('bank', $tx, '321', '221', 500.00);
        $this->takeOver($entry, 'BV-DIMENZE');

        self::assertSame(['action' => 'skipped', 'reason' => 'taken_over'], $this->service->handleTransaction($tx, $this->userId));
    }

    /** N1: přepis na místě (dávka haléřového dorovnání) převzatý zápis odmítne. */
    public function testRepostInPlaceRefusesTakenOverEntry(): void
    {
        [, $tx] = $this->takenOverRoundedPurchasePayment('INPLACE');

        $this->expectException(PostingException::class);
        $this->service->repostMatchedInPlace($this->supplierId, $tx, $this->userId);
    }

    /** N2: dávka doúčtování bankovních pohybů převzatý pohyb vůbec nenabídne. */
    public function testBankPostingBackfillSkipsTakenOverPayment(): void
    {
        [, $tx, $entry] = $this->takenOverRoundedPurchasePayment('BACKFILL');

        $report = $this->backfill->run($this->supplierId, self::YEAR . '-01-01', true, false, $this->userId);

        self::assertSame(0, $report['candidates'], json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $report['normalized_full']);
        self::assertEqualsWithDelta(72600.00, (float) $this->scalar("SELECT amount FROM payment_matches WHERE bank_transaction_id = {$tx}"), 0.001);
        self::assertArrayNotHasKey('648', $this->linesByAccountCode($entry));
    }

    /** Nativní pohyb v témž stavu se dál dorovná — guard se týká jen převzatých zápisů. */
    public function testNativePaymentIsStillNormalized(): void
    {
        $pf = $this->roundedPurchase('NATIVNI', 72600.20, -0.20);
        $this->postPredpis('purchase_invoice', $pf, '518', '321', 72600.20);
        $tx = $this->transaction($this->statement(), -72600.00, ['match_status' => 'auto_partial']);
        $this->autoMatch($tx, $pf, 72600.00);
        $this->postPredpis('bank', $tx, '321', '221', 72600.00);

        $result = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $result['action'], json_encode($result));
        self::assertEqualsWithDelta(72600.20, (float) $this->scalar("SELECT amount FROM payment_matches WHERE bank_transaction_id = {$tx}"), 0.001);
        self::assertEqualsWithDelta(0.20, $this->linesByAccountCode((int) $result['entry_id'])['648']['credit'], 0.001);
    }
}
