<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Idempotence a stavová tabulka repostu (H3): rewrite in-place, supersede návrhů,
 * rule→match rewrite, unmatched s existujícím zápisem → pravidla se nevyhodnocují. §8.
 */
#[Group('integration')]
final class BankPostingServiceIdempotenceTest extends BankPostingTestCase
{
    public function testDoubleHandleKeepsOneEntry(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-ID1', $client, 5000.00);
        $this->postPredpis('invoice', $inv, '311', '602', 5000.00);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 5000.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 5000.00);

        $r1 = $this->service->handleTransaction($tx, $this->userId);
        $r2 = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame($r1['entry_id'], $r2['entry_id'], 'Rewrite in-place → stejné entry id.');
        self::assertSame(1, $this->entryCountForTx($tx));
    }

    /**
     * Regrese (ostrá data): opakovaný průchod nad UŽ zaúčtovanou spárovanou tx spadl do
     * policy větve a založil nový `pending` návrh. Takhle se nasbíralo 88 „duchů", které
     * nafukovaly kartu „Zaúčtuj doklady" proti reálné frontě nezaúčtovaných pohybů.
     * Rewrite je no-op, dokud se zápis nemá čím lišit.
     */
    public function testOpakovanyPruchodNadZauctovanouTxNezakladaNavrh(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-ID9', $client, 5000.00);
        $this->postPredpis('invoice', $inv, '311', '602', 5000.00);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 5000.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 5000.00);

        $r1 = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $r1['action']);

        // Několik dalších průchodů (rematch / reimport / accept match suggestion).
        $r2 = $this->service->handleTransaction($tx, $this->userId);
        $r3 = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $r2['action'], 'No-op rewrite hlásí posted, ne skipped.');
        self::assertSame('already_posted', $r2['reason'] ?? null);
        self::assertSame($r1['entry_id'], $r3['entry_id'], 'Pořád týž zápis.');

        $pending = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions
              WHERE supplier_id={$this->supplierId} AND bank_transaction_id={$tx} AND status='pending'"
        )->fetchColumn();
        self::assertSame(0, $pending, 'Nad zaúčtovanou tx nesmí viset pending návrh.');
    }

    public function testRematchSupersedesPendingSuggestion(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-ID2', $client, 5000.00);
        $this->postPredpis('invoice', $inv, '311', '602', 5000.00);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 5000.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 5000.00);

        // Předchozí (dříve nespárovaná) rule suggestion na téže tx.
        $sug = $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $this->supplierId, 'bank_transaction_id' => $tx, 'rule_id' => null,
            'source' => 'learned', 'debit_account_code' => '221', 'credit_account_code' => '648',
            'amount' => 5000.00, 'note' => 'looks_like:#0',
        ]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);
        $row = $this->suggestionRow($sug['id']);
        self::assertSame('superseded', $row['status']);
        self::assertSame('overwritten_by_match', $row['note']);
    }

    public function testRulePostedThenMatchRewritesToPaymentKontace(): void
    {
        // 1) auto pravidlo zaúčtuje nespárovanou tx 336/221.
        $rule = $this->rule([
            'name' => 'Odvod', 'direction' => 'outgoing', 'counterparty_account' => '55501',
            'amount_min' => 500.00, 'amount_max' => 1500.00,
            'debit_account_code' => '568', 'credit_account_code' => '221', 'mode' => 'auto',
        ]);
        $this->enableAutoRule($rule);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000.00, ['match_status' => 'unmatched', 'counterparty_account' => '55501']);
        $r1 = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $r1['action']);
        $entryId = (int) $r1['entry_id'];
        self::assertArrayHasKey('568', $this->linesByAccountCode($entryId));

        // 2) tx se dodatečně spáruje na PF → rewrite téhož zápisu na platební kontaci 321/221.
        $vendor = $this->client('Dodavatel s.r.o.');
        $pf = $this->purchaseInvoice('PF-ID3', $vendor, 1000.00);
        $this->postPredpis('purchase_invoice', $pf, '501', '321', 1000.00);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status='manual' WHERE id=?")->execute([$tx]);
        $this->paymentMatch($tx, $pf, 1000.00);

        $r2 = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $r2['action']);
        self::assertSame($entryId, (int) $r2['entry_id'], 'Rewrite téhož zápisu (unique slot).');
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertArrayHasKey('321', $byAcc, 'Nově platební kontace 321.');
        self::assertArrayNotHasKey('568', $byAcc, 'Původní 568 přepsáno.');

        // auto_posted protokolový řádek → superseded/overwritten_by_match.
        $auto = $this->db->pdo()->query(
            "SELECT status, note FROM bank_posting_suggestions WHERE bank_transaction_id={$tx} AND source='rule'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('superseded', $auto['status']);
        self::assertSame('overwritten_by_match', $auto['note']);
    }

    public function testUnmatchedWithExistingEntrySkipsRules(): void
    {
        $rule = $this->rule([
            'name' => 'Odvod', 'direction' => 'outgoing', 'counterparty_account' => '55502',
            'amount_min' => 500.00, 'amount_max' => 1500.00,
            'debit_account_code' => '568', 'credit_account_code' => '221', 'mode' => 'auto',
        ]);
        $this->enableAutoRule($rule);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000.00, ['match_status' => 'unmatched', 'counterparty_account' => '55502']);
        $r1 = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $r1['action']);

        // tx zůstává unmatched se zápisem → druhý běh pravidla nevyhodnotí.
        $r2 = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $r2['action']);
        self::assertSame('already_posted', $r2['reason']);
    }

    private function enableAutoRule(int $ruleId): void
    {
        $this->db->pdo()->prepare('UPDATE bank_posting_rules SET hit_count = 3 WHERE id = ?')->execute([$ruleId]);
        $this->db->pdo()->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'bank.rule.custom', 'auto', ?)
             ON DUPLICATE KEY UPDATE level = 'auto', updated_by = VALUES(updated_by)"
        )->execute([$this->supplierId, $this->userId]);
    }
}
