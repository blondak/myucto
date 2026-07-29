<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Uzavřené období: auto pravidlo i matched platba → pending suggestion period_closed
 * (žádný zápis); approve v zavřeném období selže; period_closed počítán živě. §8.
 */
#[Group('integration')]
final class BankPostingPeriodTest extends BankPostingTestCase
{
    private function closePeriod(): void
    {
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');
    }

    public function testAutoRuleIntoClosedPeriodSuggestsPeriodClosed(): void
    {
        $this->rule([
            'name' => 'Odvod', 'direction' => 'outgoing', 'counterparty_account' => '90001',
            'amount_min' => 100.00, 'amount_max' => 90000.00,
            'debit_account_code' => '336', 'credit_account_code' => '221', 'mode' => 'auto',
        ]);
        $this->closePeriod();
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000.00, ['counterparty_account' => '90001']);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $res['action']);
        self::assertSame('period_closed', $res['reason']);
        self::assertSame(0, $this->entryCountForTx($tx));
        self::assertSame('period_closed', $this->suggestionRow((int) $res['suggestion_id'])['note']);
    }

    public function testMatchedPaymentIntoClosedPeriodSuggests(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-P1', $client, 1000.00);
        $this->postPredpis('invoice', $inv, '311', '602', 1000.00);
        $this->closePeriod();

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 1000.00);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $res['action']);
        self::assertSame('period_closed', $res['reason']);
        self::assertSame('payment_match', $this->suggestionRow((int) $res['suggestion_id'])['source']);
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    public function testApproveInClosedPeriodFails(): void
    {
        $this->rule([
            'name' => 'Odvod', 'direction' => 'outgoing', 'counterparty_account' => '90002',
            'amount_min' => 100.00, 'amount_max' => 90000.00,
            'debit_account_code' => '336', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000.00, ['counterparty_account' => '90002']);
        $res = $this->service->handleTransaction($tx, $this->userId);
        $sid = (int) $res['suggestion_id'];

        $this->closePeriod();
        try {
            $this->service->approveSuggestion($this->supplierId, $sid, $this->meta());
            self::fail('Approve do zavřeného období musí selhat.');
        } catch (PostingException $e) {
            self::assertContains($e->errorCode, ['period_not_open', 'period_closed', 'no_accounting_period']);
        }
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    public function testPeriodClosedComputedLive(): void
    {
        $this->rule([
            'name' => 'Odvod', 'direction' => 'outgoing', 'counterparty_account' => '90003',
            'amount_min' => 100.00, 'amount_max' => 90000.00,
            'debit_account_code' => '336', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000.00, ['counterparty_account' => '90003']);
        $this->service->handleTransaction($tx, $this->userId);

        $this->closePeriod();
        $closed = $this->suggestionRepo->paginate($this->supplierId, 'pending', null, 50, 0);
        self::assertTrue($this->flagForTx($closed['items'], $tx), 'Zavřené období → period_closed true.');

        $this->periods->setStatus($this->periodId, $this->supplierId, 'open');
        $open = $this->suggestionRepo->paginate($this->supplierId, 'pending', null, 50, 0);
        self::assertFalse($this->flagForTx($open['items'], $tx), 'Otevřené období → period_closed false.');
    }

    /** @param list<array<string,mixed>> $items */
    private function flagForTx(array $items, int $txId): bool
    {
        foreach ($items as $it) {
            if ((int) $it['transaction']['id'] === $txId) {
                return (bool) $it['period_closed'];
            }
        }
        self::fail('Návrh transakce ' . $txId . ' nenalezen v seznamu.');
    }
}
