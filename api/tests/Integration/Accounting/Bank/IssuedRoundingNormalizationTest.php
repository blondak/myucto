<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use PHPUnit\Framework\Attributes\Group;

/**
 * §3.5 registru SSOT — haléřové dorovnání na VYDANÉ větvi.
 *
 * Přijatá větev dorovnání měla od začátku (`normalizeRoundingFullPurchase`), vydaná ne:
 * měla jen `InvoicePaymentService::TOLERANCE` = 0,05 Kč pro uzavření dokladu. Rozdíl
 * mezi 0,05 a 1,00 Kč tedy nechal fakturu viset otevřenou s haléřovým zbytkem na 311.
 *
 * Registr to vedl jako 🔴 jednostranný nález se značkou „agent". Ověřením se potvrdil
 * strukturálně, ale expozice na ostrých datech byla **nulová** (0 vydaných faktur
 * s nedoplatkem 0–1 Kč), takže to nebyla oprava čísel, ale doplnění chybějící cesty.
 *
 * ⚠ VĚDOMÝ DŮSLEDEK: nedoplatek zákazníka do 1 Kč se automaticky odpustí a z pohledávek
 * zmizí. Rozhodnuto 26. 7. 2026 ve prospěch symetrie s přijatou větví.
 */
#[Group('integration')]
final class IssuedRoundingNormalizationTest extends BankPostingTestCase
{
    /** Zákazník zaplatil o 0,50 Kč MÍŇ → doklad se uzavře, rozdíl je náklad na 548. */
    public function testUnderpaymentWithinToleranceClosesInvoiceAndBooksExpense(): void
    {
        $client = $this->client('Odběratel haléře');
        $invoice = $this->saleInvoice('FV-ROUND-1', $client, 1000.50);
        $this->postPredpis('invoice', $invoice, '311', '602', 1000.50);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.00, [
            'match_status' => 'auto_partial',
            'matched_invoice_id' => $invoice,
        ]);
        $this->invoicePayment($invoice, $tx, 1000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'Úhrada se má zaúčtovat: ' . ($res['reason'] ?? ''));

        // Alokace srovnaná na nominál předpisu.
        self::assertEqualsWithDelta(1000.50, (float) $this->db->pdo()->query(
            'SELECT amount FROM invoice_payments WHERE bank_transaction_id = ' . $tx
        )->fetchColumn(), 0.001, 'Alokace se srovná na nominál, jinak zůstane zbytek na 311.');

        // Doklad uzavřený.
        self::assertSame('paid', $this->db->pdo()->query(
            'SELECT status FROM invoices WHERE id = ' . $invoice
        )->fetchColumn(), 'Faktura krytá do 1 Kč se má uzavřít.');

        // Zápis: 221 MD 1000,00 + 548 MD 0,50 proti 311 D 1000,50.
        $lines = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(1000.00, $lines['221']['debit'], 0.001);
        self::assertEqualsWithDelta(1000.50, $lines['311']['credit'], 0.001);
        self::assertEqualsWithDelta(0.50, $lines['548']['debit'], 0.001, 'Nedoplatek je náklad ze zaokrouhlení.');
    }

    /** Zákazník zaplatil o 0,50 Kč VÍC → doklad se uzavře, rozdíl je výnos na 648. */
    public function testOverpaymentWithinToleranceBooksGain(): void
    {
        $client = $this->client('Odběratel přeplatek');
        $invoice = $this->saleInvoice('FV-ROUND-2', $client, 999.50);
        $this->postPredpis('invoice', $invoice, '311', '602', 999.50);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.00, [
            'match_status' => 'auto_partial',
            'matched_invoice_id' => $invoice,
        ]);
        $this->invoicePayment($invoice, $tx, 1000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], (string) ($res['reason'] ?? ''));

        $lines = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(1000.00, $lines['221']['debit'], 0.001);
        self::assertEqualsWithDelta(999.50, $lines['311']['credit'], 0.001);
        self::assertEqualsWithDelta(0.50, $lines['648']['credit'], 0.001, 'Přeplatek je výnos ze zaokrouhlení.');
    }

    /**
     * Rozdíl NAD tolerancí není zaokrouhlení, ale částečná úhrada — alokace se nesmí
     * hnout a doklad musí zůstat otevřený. Bez téhle hranice by se z dorovnání stalo
     * tiché odpouštění pohledávek.
     */
    public function testPartialPaymentAboveToleranceIsNotNormalized(): void
    {
        $client = $this->client('Odběratel splátka');
        $invoice = $this->saleInvoice('FV-ROUND-3', $client, 1500.00);
        $this->postPredpis('invoice', $invoice, '311', '602', 1500.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.00, [
            'match_status' => 'auto_partial',
            'matched_invoice_id' => $invoice,
        ]);
        $this->invoicePayment($invoice, $tx, 1000.00);

        $this->service->handleTransaction($tx, $this->userId);

        self::assertEqualsWithDelta(1000.00, (float) $this->db->pdo()->query(
            'SELECT amount FROM invoice_payments WHERE bank_transaction_id = ' . $tx
        )->fetchColumn(), 0.001, 'Splátka 500 Kč pod nominál se srovnávat NESMÍ.');

        self::assertNotSame('paid', $this->db->pdo()->query(
            'SELECT status FROM invoices WHERE id = ' . $invoice
        )->fetchColumn(), 'Částečně uhrazená faktura zůstane otevřená.');
    }

    /** Idempotence: druhý běh už alokaci nemění (guard `alokace ≠ částka tx`). */
    public function testNormalizationIsIdempotent(): void
    {
        $client = $this->client('Odběratel idempotence');
        $invoice = $this->saleInvoice('FV-ROUND-4', $client, 1000.50);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.00, [
            'match_status' => 'auto_partial',
            'matched_invoice_id' => $invoice,
        ]);
        $this->invoicePayment($invoice, $tx, 1000.00);

        self::assertTrue($this->service->normalizeRoundingFullInvoice($this->supplierId, $tx), 'První běh srovná.');
        self::assertFalse($this->service->normalizeRoundingFullInvoice($this->supplierId, $tx), 'Druhý běh je no-op.');

        self::assertEqualsWithDelta(1000.50, (float) $this->db->pdo()->query(
            'SELECT amount FROM invoice_payments WHERE bank_transaction_id = ' . $tx
        )->fetchColumn(), 0.001);
    }

    private function entryIdForBankTx(int $txId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM journal_entries
              WHERE supplier_id = ? AND source_type = 'bank' AND source_id = ? AND reversed_by IS NULL
           ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$this->supplierId, $txId]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $id, 'K transakci neexistuje živý účetní zápis.');

        return $id;
    }
}
