<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class BankPostingBulkTriageTest extends BankPostingTestCase
{
    private BankPostingSuggestionAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->container->get(BankPostingSuggestionAction::class);
        $hasBatch = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'bank_posting_suggestions'
                AND column_name = 'batch_id'"
        )->fetchColumn();
        if ($hasBatch === 0) $this->markTestSkipped('Migrace 1081 není aplikovaná.');
    }

    public function testPreviewDoesNotWriteAndBulkBatchCanBeUndoneAtomically(): void
    {
        $statementId = $this->statement();
        $firstTx = $this->transaction($statementId, -120.50, ['description' => 'Syntetický poplatek A']);
        $secondTx = $this->transaction($statementId, -79.50, ['description' => 'Syntetický poplatek B']);
        $first = $this->suggestion($firstTx, 120.50);
        $second = $this->suggestion($secondTx, 79.50);

        $preview = $this->callAction(
            $this->action,
            'bulkPreview',
            'POST',
            'accountant',
            ['ids' => [$first, $second]],
        );
        self::assertSame(200, $preview['status']);
        self::assertSame(2, $preview['body']['count']);
        self::assertSame(0, $this->entryCountForTx($firstTx));
        self::assertSame('pending', $this->suggestionRow($first)['status']);
        $accounts = array_column($preview['body']['accounts'], null, 'account_code');
        self::assertSame(200.0, (float) $accounts['568']['debit']);
        self::assertSame(200.0, (float) $accounts['221']['credit']);

        $approved = $this->callAction(
            $this->action,
            'bulkApprove',
            'POST',
            'accountant',
            ['ids' => [$first, $second]],
        );
        self::assertSame(200, $approved['status']);
        self::assertSame(2, $approved['body']['approved']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $approved['body']['batch_id']);
        self::assertSame(1, $this->entryCountForTx($firstTx));
        self::assertSame(1, $this->entryCountForTx($secondTx));

        $undone = $this->callAction(
            $this->action,
            'undoBatch',
            'POST',
            'accountant',
            [],
            ['batchId' => $approved['body']['batch_id']],
        );
        self::assertSame(200, $undone['status']);
        self::assertSame(2, $undone['body']['reversed']);
        self::assertSame(0, $undone['body']['already_reversed']);
        self::assertCount(2, $undone['body']['reversal_entry_ids']);
        self::assertNull($this->journal->findBySource($this->supplierId, 'bank', $firstTx));
        self::assertNull($this->journal->findBySource($this->supplierId, 'bank', $secondTx));
    }

    public function testSnoozeIsTenantScopedAndBulkRejectStoresStructuredReason(): void
    {
        $statementId = $this->statement();
        $foreignTxId = $this->transaction($statementId, -41.00);
        $foreignSuggestionId = $this->suggestionForSupplier($this->otherSupplierId(), $foreignTxId, 41.00);
        $foreign = $this->callAction(
            $this->action,
            'snooze',
            'POST',
            'accountant',
            ['until' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d'), 'reason' => 'later'],
            ['id' => (string) $foreignSuggestionId],
        );
        self::assertSame(404, $foreign['status']);
        self::assertNull($this->suggestionRow($foreignSuggestionId)['snoozed_until']);

        $txId = $this->transaction($statementId, -42.00);
        $suggestionId = $this->suggestion($txId, 42.00);
        $until = new \DateTimeImmutable('+1 day');

        $snoozed = $this->callAction(
            $this->action,
            'snooze',
            'POST',
            'accountant',
            ['until' => $until->format('Y-m-d'), 'reason' => 'later'],
            ['id' => (string) $suggestionId],
        );
        self::assertSame(200, $snoozed['status']);
        self::assertSame($until->format('Y-m-d 23:59:59'), $snoozed['body']['snoozed_until']);

        $rejected = $this->callAction(
            $this->action,
            'bulkReject',
            'POST',
            'accountant',
            ['ids' => [$suggestionId], 'reason' => 'wrong_account'],
        );
        self::assertSame(200, $rejected['status']);
        self::assertSame(1, $rejected['body']['rejected']);
        $row = $this->suggestionRow($suggestionId);
        self::assertSame('rejected', $row['status']);
        self::assertSame('wrong_account', $row['note']);
    }

    public function testUndoBatchRollsBackEarlierReversalsWhenOnePeriodIsClosed(): void
    {
        $secondPeriodId = $this->periods->create($this->supplierId, 2100, '2100-01-01', '2100-12-31');
        $statementId = $this->statement();
        $firstTx = $this->transaction($statementId, -50.00, ['posted_at' => '2099-06-15']);
        $secondTx = $this->transaction($statementId, -60.00, ['posted_at' => '2100-06-15']);
        $first = $this->suggestion($firstTx, 50.00);
        $second = $this->suggestion($secondTx, 60.00);
        $approved = $this->callAction(
            $this->action,
            'bulkApprove',
            'POST',
            'accountant',
            ['ids' => [$first, $second]],
        );
        self::assertSame(2, $approved['body']['approved']);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE id=? AND supplier_id=?")
            ->execute([$secondPeriodId, $this->supplierId]);

        $undone = $this->callAction(
            $this->action,
            'undoBatch',
            'POST',
            'accountant',
            [],
            ['batchId' => $approved['body']['batch_id']],
        );
        self::assertSame(409, $undone['status']);
        self::assertSame('period_closed', $undone['body']['error']['code']);
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'bank', $firstTx));
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'bank', $secondTx));
    }

    private function suggestion(int $transactionId, float $amount): int
    {
        return $this->suggestionForSupplier($this->supplierId, $transactionId, $amount);
    }

    private function suggestionForSupplier(int $supplierId, int $transactionId, float $amount): int
    {
        return $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $supplierId,
            'bank_transaction_id' => $transactionId,
            'rule_id' => null,
            'source' => 'learned',
            'debit_account_code' => '568',
            'credit_account_code' => '221',
            'amount' => $amount,
            'description' => 'Syntetický návrh',
            'status' => 'pending',
            'note' => null,
            'confidence' => 0.95,
            'detector' => null,
            'operation_type' => 'bank.learned',
        ])['id'];
    }
}
