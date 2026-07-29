<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\Bank\TransferPairService;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class OwnTransferAdversarialTest extends BankPostingTestCase
{
    private const SECOND_ACCOUNT = '1000000005';
    private const SECOND_BANK = '0100';

    public function testCrossCurrencyDetectorReplacesOlderRuleSuggestionAndApprovalCannotBypassGuard(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'EUR');
        $statement = $this->statement();
        $tx = $this->transaction($statement, -24680.13, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $old = $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $this->supplierId,
            'bank_transaction_id' => $tx,
            'rule_id' => null,
            'source' => 'rule',
            'debit_account_code' => '518',
            'credit_account_code' => '221',
            'amount' => 24680.13,
        ]);

        try {
            $this->service->approveSuggestion($this->supplierId, (int) $old['id'], $this->meta());
            self::fail('Starší pravidlový návrh nesmí obejít cross-currency guard.');
        } catch (PostingException $e) {
            self::assertSame('cross_currency_manual_only', $e->errorCode);
        }

        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('cross_currency', $result['reason']);
        self::assertSame('superseded', $this->suggestionRow((int) $old['id'])['status']);
        $replacement = $this->suggestionRow((int) $result['suggestion_id']);
        self::assertSame('transfer', $replacement['source']);
        self::assertSame('cross_currency', $replacement['note']);
    }

    public function testApprovalPostsEquivalentLegacyRuleSuggestionWithoutSubstitution(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'CZK');
        $statement = $this->statement();
        $tx = $this->transaction($statement, -14500.83, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
            'description' => 'Převod na vlastní spořicí účet',
        ]);
        $legacy = $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $this->supplierId,
            'bank_transaction_id' => $tx,
            'rule_id' => null,
            'source' => 'rule',
            'debit_account_code' => '261',
            'credit_account_code' => '221',
            'amount' => 14500.83,
        ]);

        $entryId = $this->service->approveSuggestion($this->supplierId, (int) $legacy['id'], $this->meta());

        self::assertGreaterThan(0, $entryId);
        $row = $this->suggestionRow((int) $legacy['id']);
        self::assertSame('rule', $row['source']);
        self::assertSame('approved', $row['status']);
        self::assertSame((string) $entryId, (string) $row['journal_entry_id']);
        self::assertSame('261', $row['debit_account_code']);
        self::assertSame('221', $row['credit_account_code']);
        self::assertSame(1, $this->entryCountForTx($tx));
    }

    public function testApprovalReplacesDifferentLegacySuggestionButDoesNotPostIt(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'CZK');
        $statement = $this->statement();
        $tx = $this->transaction($statement, -3200.00, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $legacy = $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $this->supplierId,
            'bank_transaction_id' => $tx,
            'rule_id' => null,
            'source' => 'rule',
            'debit_account_code' => '518',
            'credit_account_code' => '221',
            'amount' => 3200.00,
        ]);

        try {
            $this->service->approveSuggestion($this->supplierId, (int) $legacy['id'], $this->meta());
            self::fail('Odlišný náhradní návrh musí uživatel zkontrolovat samostatně.');
        } catch (PostingException $e) {
            self::assertSame('suggestion_replaced', $e->errorCode);
        }

        self::assertSame(0, $this->entryCountForTx($tx));
        self::assertSame('superseded', $this->suggestionRow((int) $legacy['id'])['status']);
        $replacement = $this->suggestionRepo->pendingForTx($this->supplierId, $tx);
        self::assertNotNull($replacement);
        self::assertSame('transfer', $replacement['source']);
        self::assertSame('261', $replacement['debit_account_code']);
        self::assertSame('221', $replacement['credit_account_code']);
    }

    public function testSameCanonicalNumberAtDifferentBanksIsNotSelfReference(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, '0100', 'CZK');
        $this->registerAccount(self::SECOND_ACCOUNT, '0800', 'CZK');
        $statement = $this->statement(self::SECOND_ACCOUNT, '0100');
        $tx = $this->transaction($statement, -13579.24, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => '0800',
        ]);

        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertContains($result['action'], ['suggested', 'posted']);
        self::assertSame('own_transfer', $result['reason']);
    }

    public function testClosingDoesNotDocumentArbitraryBankEntryOn261(): void
    {
        $period = $this->periods->findById($this->supplierId, $this->periodId);
        $closing = $this->container->get(ClosingService::class);
        $before = $this->check($closing->runPrecheck(
            $this->supplierId,
            $this->periodId,
            (int) $period['row_version'],
            ['user_id' => $this->userId],
        )['checks'], 'transit_261_open');
        $statement = $this->statement();
        $tx = $this->transaction($statement, -11223.34, [
            'posted_at' => self::YEAR . '-12-30',
            'counterparty_account' => '2000000010',
            'counterparty_bank' => '0100',
        ]);
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            ['account_code' => '261', 'side' => 'debit', 'amount' => 11223.34],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 11223.34],
        ], [
            'entry_date' => self::YEAR . '-12-30',
            'posted' => true,
            'posted_by' => $this->userId,
        ]);

        $precheck = $closing->runPrecheck(
            $this->supplierId,
            $this->periodId,
            (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'],
            ['user_id' => $this->userId],
        );
        $transit = $this->check($precheck['checks'], 'transit_261_open');
        self::assertFalse($transit['ok']);
        self::assertSame($before['value']['documented'], $transit['value']['documented']);
        self::assertEqualsWithDelta(
            11223.34,
            (float) $transit['value']['unexplained'] - (float) $before['value']['unexplained'],
            0.001,
        );
    }

    public function testPairLogsResidualWhenFxLegsWerePostedIndependentlyWithDifferentRates(): void
    {
        $this->registerAccount(self::ACCOUNT, self::BANK_CODE, 'EUR');
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'EUR');
        $outStatement = $this->statement();
        $inStatement = $this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK);
        $this->db->pdo()->prepare('UPDATE bank_statements SET currency = "EUR" WHERE id IN (?, ?)')
            ->execute([$outStatement, $inStatement]);
        $foreign = 321.09;
        $outTx = $this->transaction($outStatement, -$foreign, [
            'currency' => 'EUR',
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $inTx = $this->transaction($inStatement, $foreign, [
            'currency' => 'EUR',
            'posted_at' => self::YEAR . '-06-16',
            'counterparty_account' => self::ACCOUNT,
            'counterparty_bank' => self::BANK_CODE,
        ]);
        $this->postFxLeg($outTx, '261', 'debit', '221', 'credit', $foreign, 25.00, self::YEAR . '-06-15');
        $this->postFxLeg($inTx, '221', 'debit', '261', 'credit', $foreign, 27.00, self::YEAR . '-06-16');

        $this->container->get(TransferPairService::class)->pair($this->supplierId, $outTx);
        $log = $this->db->pdo()->prepare(
            'SELECT payload FROM activity_log WHERE supplier_id = ? AND action = "bank_transfer.fx_residual" LIMIT 1'
        );
        $log->execute([$this->supplierId]);
        $payload = json_decode((string) $log->fetchColumn(), true);
        self::assertSame($outTx, $payload['out_transaction_id']);
        self::assertSame($inTx, $payload['in_transaction_id']);
        self::assertEqualsWithDelta(-642.18, $payload['residual'], 0.001);
    }

    /** #9 — okno párování je ±7 dní (PAIR_WINDOW_DAYS); nad ním se nohy převodu spárovat NESMÍ. */
    public function testTransfersBeyondSevenDayWindowAreNotPaired(): void
    {
        $this->registerAccount(self::SECOND_ACCOUNT, self::SECOND_BANK, 'CZK');
        $outStatement = $this->statement();
        $inStatement = $this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK);
        $amount = 33221.10;

        // 10 dní mezi nohama — nad oknem ±7, párovat se nesmí.
        $outTx = $this->transaction($outStatement, -$amount, [
            'posted_at' => self::YEAR . '-06-15',
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $inTx = $this->transaction($inStatement, $amount, [
            'posted_at' => self::YEAR . '-06-25',
            'counterparty_account' => self::ACCOUNT,
            'counterparty_bank' => self::BANK_CODE,
        ]);

        $this->service->handleTransaction($outTx, $this->userId);
        $this->service->handleTransaction($inTx, $this->userId);

        $count = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM bank_transfer_matches
              WHERE supplier_id = ? AND (out_transaction_id IN (?, ?) OR in_transaction_id IN (?, ?))'
        );
        $count->execute([$this->supplierId, $outTx, $inTx, $outTx, $inTx]);
        self::assertSame(0, (int) $count->fetchColumn(), 'Nad oknem ±7 dní se nohy nesmí spárovat.');
    }

    private function registerAccount(string $number, string $bank, string $currency): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency,
                 account_canonical, kind, source, is_active)
             VALUES (?, "Adversarial test účet", ?, ?, ?, ?, ?, "savings", "manual", 1)
             ON DUPLICATE KEY UPDATE currency = VALUES(currency), is_active = 1'
        )->execute([$this->supplierId, $number, $bank, $bank, $currency, $number]);
    }

    private function postFxLeg(
        int $txId,
        string $firstAccount,
        string $firstSide,
        string $secondAccount,
        string $secondSide,
        float $foreign,
        float $rate,
        string $date,
    ): void {
        $amount = round($foreign * $rate, 2);
        $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            ['account_code' => $firstAccount, 'side' => $firstSide, 'amount' => $amount,
             'currency_code' => 'EUR', 'fx_rate' => $rate, 'amount_foreign' => $foreign],
            ['account_code' => $secondAccount, 'side' => $secondSide, 'amount' => $amount,
             'currency_code' => 'EUR', 'fx_rate' => $rate, 'amount_foreign' => $foreign],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId]);
    }

    /** @param list<array<string,mixed>> $checks @return array<string,mixed> */
    private function check(array $checks, string $key): array
    {
        foreach ($checks as $check) {
            if ($check['key'] === $key) return $check;
        }
        self::fail('Kontrola ' . $key . ' chybí.');
    }
}
