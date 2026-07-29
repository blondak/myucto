<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministické učení (§4.3): 2. podobná platba bez pravidla → learned návrh;
 * dvě různé historické kontace → nic; H2b: z platebních (matched) zápisů se neučí. §8.
 */
#[Group('integration')]
final class BankPostingLearnedTest extends BankPostingTestCase
{
    public function testSecondSimilarPaymentSuggestsLearned(): void
    {
        $stmt = $this->statement();
        // 1. výskyt — ručně zaúčtováno (učební předloha, ne-matched zdroj).
        $tx1 = $this->transaction($stmt, -5000.00, ['counterparty_account' => '77621']);
        $this->service->postManual($this->supplierId, $tx1, ['debit_account_code' => '336', 'credit_account_code' => '221'], $this->meta());

        // 2. výskyt — import bez pravidla → learned suggestion s okopírovanou kontací.
        $tx2 = $this->transaction($stmt, -5010.00, ['counterparty_account' => '77621']);
        $res = $this->service->handleTransaction($tx2, $this->userId);
        self::assertSame('suggested', $res['action']);
        self::assertSame('learned', $res['reason']);
        $sug = $this->suggestionRow((int) $res['suggestion_id']);
        self::assertSame('learned', $sug['source']);
        self::assertSame('336', $sug['debit_account_code']);
        self::assertSame('221', $sug['credit_account_code']);
        self::assertSame('corrected_from:#' . $tx1, $sug['note']);
    }

    public function testTwoDistinctKontaceSuggestNothing(): void
    {
        $this->enableBankAiFallback();
        $stmt = $this->statement();
        $a = $this->transaction($stmt, -5000.00, ['counterparty_account' => '77622']);
        $this->service->postManual($this->supplierId, $a, ['debit_account_code' => '336', 'credit_account_code' => '221'], $this->meta());
        $b = $this->transaction($stmt, -5000.00, ['counterparty_account' => '77622']);
        $this->service->postManual($this->supplierId, $b, ['debit_account_code' => '518', 'credit_account_code' => '221'], $this->meta());

        $tx = $this->transaction($stmt, -5000.00, ['counterparty_account' => '77622']);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('ai_queued', $res['reason']);
        self::assertSame(0, $this->suggestionCountForTx($tx));
    }

    public function testPaymentEntriesAreNotLearnedFrom(): void
    {
        $this->enableBankAiFallback();
        // Historie = jen platební zápis 221/311 ze spárované tx (H2b filtr).
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-L1', $client, 5000.00);
        $this->postPredpis('invoice', $inv, '311', '602', 5000.00);
        $stmt = $this->statement();
        $matched = $this->transaction($stmt, 5000.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv, 'counterparty_account' => '77623']);
        $this->invoicePayment($inv, $matched, 5000.00);
        self::assertSame('posted', $this->service->handleTransaction($matched, $this->userId)['action']);

        // Nová nespárovaná příchozí platba stejného protiúčtu → learned NIC nenavrhne.
        $tx = $this->transaction($stmt, 5000.00, ['counterparty_account' => '77623']);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame('ai_queued', $res['reason']);
        self::assertSame(0, $this->suggestionCountForTx($tx));
    }

    private function enableBankAiFallback(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET ai_assist_enabled=1,ai_assist_scope='bank_tx' WHERE id=?")
            ->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM ai_source_mutes WHERE supplier_id=?')->execute([$this->supplierId]);
    }
}
