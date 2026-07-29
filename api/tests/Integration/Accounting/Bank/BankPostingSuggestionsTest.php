<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Fronta návrhů: approve (+override), reject streak/deaktivace (R7+M3), žádná
 * re-suggestion po rejectu, unique pending (M2), 409 na ne-pending. §8.
 */
#[Group('integration')]
final class BankPostingSuggestionsTest extends BankPostingTestCase
{
    /** @return array{tx:int, suggestion:int} */
    private function suggestFromRule(string $account, float $amount = -1000.00): array
    {
        $this->rule([
            'name' => 'Odvod', 'direction' => 'outgoing', 'counterparty_account' => $account,
            'amount_min' => 100.00, 'amount_max' => 90000.00,
            'debit_account_code' => '336', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, $amount, ['counterparty_account' => $account]);
        $res = $this->service->handleTransaction($tx, $this->userId);
        return ['tx' => $tx, 'suggestion' => (int) $res['suggestion_id']];
    }

    public function testApprovePostsEntry(): void
    {
        ['tx' => $tx, 'suggestion' => $sid] = $this->suggestFromRule('80001');
        $entryId = $this->service->approveSuggestion($this->supplierId, $sid, $this->meta());
        self::assertGreaterThan(0, $entryId);
        $row = $this->suggestionRow($sid);
        self::assertSame('approved', $row['status']);
        self::assertSame($entryId, (int) $row['journal_entry_id']);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(1000.00, $byAcc['336']['debit'], 0.001);
    }

    public function testApproveWithAccountOverride(): void
    {
        ['tx' => $tx, 'suggestion' => $sid] = $this->suggestFromRule('80002');
        $entryId = $this->service->approveSuggestion($this->supplierId, $sid, $this->meta(), [
            'debit_account_code' => '518', 'credit_account_code' => '221',
        ]);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertArrayHasKey('518', $byAcc, 'Override kontace se použil.');
        self::assertArrayNotHasKey('336', $byAcc);
    }

    public function testThreeRejectsDistinctTxDisableRule(): void
    {
        $ruleId = $this->rule([
            'name' => 'Odvod', 'direction' => 'outgoing', 'counterparty_account' => '80003',
            'amount_min' => 100.00, 'amount_max' => 90000.00,
            'debit_account_code' => '336', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $stmt = $this->statement();
        for ($i = 0; $i < 3; $i++) {
            $tx = $this->transaction($stmt, -1000.00 - $i, ['counterparty_account' => '80003']);
            $res = $this->service->handleTransaction($tx, $this->userId);
            $this->service->rejectSuggestion($this->supplierId, (int) $res['suggestion_id'], $this->meta(), 'nechci');
        }
        $rule = $this->ruleRow($ruleId);
        self::assertFalse($rule['is_active'], 'Pravidlo deaktivováno po 3 odmítnutích (distinct tx).');
        self::assertSame(3, $rule['rejected_streak']);
    }

    public function testThreeRejectsSameTxKeepStreakOne(): void
    {
        // M3b: streak++ jen při JINÉ tx — recordReject téže tx opakovaně nezvyšuje.
        $ruleId = $this->rule([
            'name' => 'Odvod', 'direction' => 'outgoing', 'counterparty_account' => '80004',
            'amount_min' => 100.00, 'amount_max' => 90000.00,
            'debit_account_code' => '336', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $this->ruleRepo->recordReject($ruleId, 12345);
        $this->ruleRepo->recordReject($ruleId, 12345);
        $r = $this->ruleRepo->recordReject($ruleId, 12345);
        self::assertSame(1, $r['streak']);
        self::assertFalse($r['disabled']);
        self::assertTrue($this->ruleRow($ruleId)['is_active']);
    }

    public function testNoResuggestionAfterReject(): void
    {
        ['tx' => $tx, 'suggestion' => $sid] = $this->suggestFromRule('80005');
        $this->service->rejectSuggestion($this->supplierId, $sid, $this->meta(), 'ne');

        // Rematch téže tx → pravidlo s existujícím rejectem (tx,rule) se přeskočí (M3a).
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action']);
        self::assertSame(1, $this->suggestionCountForTx($tx), 'Žádná nová suggestion po rejectu.');
    }

    public function testUniquePendingPerTransaction(): void
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -500.00, ['counterparty_account' => '80006']);
        $a = $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $this->supplierId, 'bank_transaction_id' => $tx, 'rule_id' => null,
            'source' => 'learned', 'debit_account_code' => '336', 'credit_account_code' => '221', 'amount' => 500.00,
        ]);
        $b = $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $this->supplierId, 'bank_transaction_id' => $tx, 'rule_id' => null,
            'source' => 'learned', 'debit_account_code' => '518', 'credit_account_code' => '221', 'amount' => 500.00,
        ]);
        self::assertTrue($a['created']);
        self::assertFalse($b['created'], 'Druhý insert chycen na uq_bps_pending (M2).');
        self::assertSame($a['id'], $b['id']);
        self::assertSame(1, $this->suggestionCountForTx($tx));
    }

    public function testApproveNonPendingThrows409(): void
    {
        ['tx' => $tx, 'suggestion' => $sid] = $this->suggestFromRule('80007');
        $this->service->approveSuggestion($this->supplierId, $sid, $this->meta());
        try {
            $this->service->approveSuggestion($this->supplierId, $sid, $this->meta());
            self::fail('Approve ne-pending musí selhat.');
        } catch (PostingException $e) {
            self::assertSame('suggestion_not_pending', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public function testRejectNonPendingThrows409(): void
    {
        ['tx' => $tx, 'suggestion' => $sid] = $this->suggestFromRule('80008');
        $this->service->approveSuggestion($this->supplierId, $sid, $this->meta());
        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/vyřízen/');
        $this->service->rejectSuggestion($this->supplierId, $sid, $this->meta(), 'pozdě');
    }
}
