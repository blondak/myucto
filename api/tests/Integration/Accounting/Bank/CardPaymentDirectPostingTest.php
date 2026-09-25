<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use PHPUnit\Framework\Attributes\Group;

/**
 * Platba firemní kartou z výpisu se účtuje přímo jako každá jiná bankovní platba:
 * spárovaná s přijatou fakturou MD 321 / D 221 v jednom zápisu, kurzový rozdíl 563/663
 * ve stejném zápisu. Mezičlen 378.x ani samostatné vypořádání nevznikají, ani když
 * firmě zůstal řádek starého nastavení účtování karet.
 *
 * RED před odstraněním mezičlenu: se zapnutým nastavením karet vznikl bankovní zápis
 * MD 378.x / D 221 a vypořádání `card_settlement` MD 321 / D 378.x.
 *
 * Izolace: rok 2099, sdílená transakce BankPostingTestCase (rollback v tearDown).
 * Karta, dodavatel i částky jsou syntetické.
 */
#[Group('integration')]
final class CardPaymentDirectPostingTest extends BankPostingTestCase
{
    private const LAST4 = '4242';

    private int $eurId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->db->pdo();
        $this->eurId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0)
            ?: $this->currencyRow($this->supplierId, 'EUR');
        // Pozůstatek dřívějšího nastavení účtování karet (firma měla mezičlen zapnutý).
        if ((bool) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'card_clearing_settings'")->fetchColumn()) {
            $pdo->prepare(
                "INSERT INTO card_clearing_settings (supplier_id, enabled, effective_from, clearing_synthetic)
                 VALUES (?, 1, '" . self::YEAR . "-01-01', '378')
                 ON DUPLICATE KEY UPDATE enabled = 1, effective_from = VALUES(effective_from), clearing_synthetic = '378'"
            )->execute([$this->supplierId]);
        }
        $pdo->prepare("DELETE FROM payment_cards WHERE supplier_id = ? AND last4 = ?")->execute([$this->supplierId, self::LAST4]);
        $pdo->prepare(
            "INSERT INTO payment_cards (supplier_id, label, last4, card_type, currency_id, is_active)
             VALUES (?, 'Testovací karta', ?, 'debit', ?, 1)"
        )->execute([$this->supplierId, self::LAST4, $this->currencyId]);
    }

    public function testCardPaymentMatchedWithCzkInvoicePostsPayableDirectlyAgainstBank(): void
    {
        $pf = $this->purchaseInvoice('PF-KARTA-CZK-' . uniqid(), $this->client('Kartový dodavatel CZK'), 1000.00);
        $this->postPredpis('purchase_invoice', $pf, '518', '321', 1000.00);
        $tx = $this->cardTx(-1000.00);
        $this->paymentMatch($tx, $pf, 1000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(1000.00, $lines['321']['debit'] ?? 0.0, 0.001, json_encode($lines));
        self::assertEqualsWithDelta(1000.00, $lines['221']['credit'] ?? 0.0, 0.001, json_encode($lines));
        self::assertSame([], $this->clearingCodes($lines), 'Platba kartou nesmí jít přes mezičlen.');
        self::assertSame(0, $this->cardEntryCount($tx), 'Samostatné vypořádání platby kartou nevzniká.');
    }

    /**
     * Cizoměnová faktura zaplacená korunovou kartou: závazek se odúčtuje v kurzu předpisu,
     * banka ve skutečně stržených Kč, rozdíl je kurzový výsledek - vše v bankovním zápisu.
     */
    public function testCardPaymentOfForeignInvoiceBooksExchangeDifferenceInBankEntry(): void
    {
        $pf = $this->fxPurchaseInvoice('PF-KARTA-EUR-' . uniqid(), $this->client('Kartový dodavatel EUR'), 500.00, 25.00);
        $tx = $this->cardTx(-12000.00);
        $this->paymentMatch($tx, $pf, 12000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(12500.00, $lines['321']['debit'] ?? 0.0, 0.001, json_encode($lines));
        self::assertEqualsWithDelta(12000.00, $lines['221']['credit'] ?? 0.0, 0.001, json_encode($lines));
        self::assertEqualsWithDelta(500.00, $this->onPrefix($lines, '663', 'credit'), 0.001, 'Kurzový zisk ve stejném zápisu.');
        self::assertSame([], $this->clearingCodes($lines));
        self::assertSame(0, $this->cardEntryCount($tx));
    }

    public function testUnmatchedCardPaymentIsNotPostedToClearingAccount(): void
    {
        $tx = $this->cardTx(-350.00);

        $res = $this->service->handleTransaction($tx, $this->userId);

        if (($res['action'] ?? null) === 'posted') {
            self::assertSame([], $this->clearingCodes($this->linesByAccountCode((int) $res['entry_id'])), json_encode($res));
        } else {
            self::assertSame(0, $this->entryCountForTx($tx), json_encode($res));
        }
        self::assertSame(0, $this->cardEntryCount($tx));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    private function cardTx(float $amount): int
    {
        $tx = $this->transaction($this->statement(), $amount, [
            'match_status'      => 'manual',
            'counterparty_name' => 'TESTOVACI OBCHOD',
            'description'       => 'Platba kartou | PK: 000000******' . self::LAST4,
        ]);
        $this->db->pdo()->prepare('UPDATE bank_transactions SET card_last4 = ? WHERE id = ?')->execute([self::LAST4, $tx]);
        return $tx;
    }

    /** Přijatá faktura v EUR + zaúčtovaný předpis 518/321 s cizoměnovou stopou. */
    private function fxPurchaseInvoice(string $number, int $vendorId, float $foreignTotal, float $rate): int
    {
        $issue = self::YEAR . '-06-10';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, "invoice", "full", ?, ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, "booked", ?)'
        )->execute([
            $this->supplierId, $vendorId, $number, json_encode(['company_name' => 'EUR Dodavatel']), $issue, $issue, $issue, $issue,
            $this->eurId, $rate, $foreignTotal, $foreignTotal, $this->userId,
        ]);
        $pfId = (int) $this->db->pdo()->lastInsertId();
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $czk = round($foreignTotal * $rate, 2);
        $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => $issue,
            'document_no' => 'PREDPIS-' . $number,
            'description' => 'Předpis PF EUR',
            'source_type' => 'purchase_invoice',
            'source_id'   => $pfId,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map['518']['id'], 'side' => 'debit', 'amount' => $czk],
            ['account_id' => $map['321']['id'], 'side' => 'credit', 'amount' => $czk,
             'currency_code' => 'EUR', 'fx_rate' => $rate, 'amount_foreign' => $foreignTotal],
        ]);
        return $pfId;
    }

    /**
     * @param array<string,array{debit:float,credit:float}> $lines
     * @return list<string>
     */
    private function clearingCodes(array $lines): array
    {
        return array_values(array_filter(
            array_map('strval', array_keys($lines)),
            static fn (string $code): bool => preg_match('/^(378|261|395)([.]|$)/', $code) === 1,
        ));
    }

    /** @param array<string,array{debit:float,credit:float}> $lines */
    private function onPrefix(array $lines, string $prefix, string $side): float
    {
        $sum = 0.0;
        foreach ($lines as $code => $l) {
            if (str_starts_with((string) $code, $prefix)) {
                $sum += $l[$side];
            }
        }
        return $sum;
    }

    private function cardEntryCount(int $txId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}
              AND source_type IN ('card_settlement', 'card_writeoff') AND source_id = {$txId}"
        )->fetchColumn();
    }
}
