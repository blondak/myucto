<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class TaxRemittanceFlowTest extends BankPostingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->pdo()->prepare(
            "UPDATE supplier SET dic='CZ12345678', cssz_vsdp='87654321', taxpayer_type='fo' WHERE id=?"
        )->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'detector.tax_remittance', 'suggest', ?)
             ON DUPLICATE KEY UPDATE level=VALUES(level), updated_by=VALUES(updated_by)"
        )->execute([$this->supplierId, $this->userId]);
    }

    public function testOutgoingCnbPaymentCreatesExplainableSuggestion(): void
    {
        $this->postPredpis('manual', 991235, '343', '648', 999999999.00);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000, [
            'counterparty_account' => '705-77628031',
            'counterparty_bank' => '0710',
            'variable_symbol' => '12345678',
        ]);
        $result = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $result['action']);
        $suggestion = $this->suggestionRow((int) $result['suggestion_id']);
        self::assertSame('detector', $suggestion['source']);
        self::assertSame('tax_remittance', $suggestion['detector']);
        self::assertSame(OperationType::REMITTANCE_VAT, $suggestion['operation_type']);
        self::assertEqualsWithDelta(0.90, (float) $suggestion['confidence'], 0.001);
        self::assertSame('liability_prescription_missing', $suggestion['note']);
    }

    public function testPriorityRuleOverridesSystemDetector(): void
    {
        $ruleId = $this->rule([
            'name' => 'Tenant override',
            'direction' => 'outgoing',
            'counterparty_bank' => '0710',
            'counterparty_prefix' => '705',
            'debit_account_code' => '568',
            'credit_account_code' => '221',
            'mode' => 'suggest',
            'priority' => 40,
        ]);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000, [
            'counterparty_account' => '705-77628031',
            'counterparty_bank' => '0710',
            'variable_symbol' => '12345678',
        ]);
        $result = $this->service->handleTransaction($tx, $this->userId);
        $suggestion = $this->suggestionRow((int) $result['suggestion_id']);
        self::assertSame('rule', $suggestion['source']);
        self::assertSame($ruleId, (int) $suggestion['rule_id']);
    }

    public function testRejectedDetectorIsNotOfferedAgain(): void
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000, [
            'counterparty_account' => '705-77628031',
            'counterparty_bank' => '0710',
            'variable_symbol' => '12345678',
        ]);
        $first = $this->service->handleTransaction($tx, $this->userId);
        $this->service->rejectSuggestion($this->supplierId, (int) $first['suggestion_id'], $this->meta());
        $second = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $second['action']);
        self::assertSame(1, $this->suggestionCountForTx($tx));
    }

    public function testDetectorReplacesStaleLowerTierSuggestion(): void
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000, [
            'counterparty_account' => '705-77628031',
            'counterparty_bank' => '0710',
            'variable_symbol' => '12345678',
        ]);
        $stale = $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $this->supplierId,
            'bank_transaction_id' => $tx,
            'rule_id' => null,
            'source' => 'learned',
            'debit_account_code' => '568',
            'credit_account_code' => '221',
            'amount' => 1000,
        ]);

        $result = $this->service->handleTransaction($tx, $this->userId);
        $fresh = $this->suggestionRow((int) $result['suggestion_id']);
        $old = $this->suggestionRow((int) $stale['id']);
        self::assertSame('detector', $fresh['source']);
        self::assertSame('tax_remittance', $fresh['detector']);
        self::assertSame('superseded', $old['status']);
    }

    public function testApprovedScheduleIsMarkedPaidAndUnpostReturnsItToPlanned(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO tax_advance_schedules
                (supplier_id, taxpayer_type, advance_kind, period_year, seq_no, amount, due_date, variable_symbol)
             VALUES (?, "fo", "social", ?, 92, 1000, ?, "87654321")'
        )->execute([$this->supplierId, self::YEAR, self::YEAR . '-06-15']);
        $scheduleId = (int) $this->db->pdo()->lastInsertId();
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000, [
            'counterparty_account' => '77628031',
            'counterparty_bank' => '0710',
            'variable_symbol' => '87654321',
        ]);
        $suggested = $this->service->handleTransaction($tx, $this->userId);
        $entryId = $this->service->approveSuggestion($this->supplierId, (int) $suggested['suggestion_id'], $this->meta());
        self::assertGreaterThan(0, $entryId);
        $status = $this->db->pdo()->query("SELECT status FROM tax_advance_schedules WHERE id={$scheduleId}")->fetchColumn();
        self::assertSame('paid', $status);

        $this->service->unpost($this->supplierId, $tx, [
            'user_id' => $this->userId,
            'posted_by' => $this->userId,
            'entry_date' => self::YEAR . '-06-16',
        ]);
        $status = $this->db->pdo()->query("SELECT status FROM tax_advance_schedules WHERE id={$scheduleId}")->fetchColumn();
        self::assertSame('planned', $status);
    }

    public function testUnpostDoesNotReopenSchedulePaidByAnotherTransaction(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO tax_advance_schedules
                (supplier_id, taxpayer_type, advance_kind, period_year, seq_no, amount, due_date, variable_symbol)
             VALUES (?, "fo", "social", ?, 93, 1000, ?, "87654321")'
        )->execute([$this->supplierId, self::YEAR, self::YEAR . '-06-15']);
        $scheduleId = (int) $this->db->pdo()->lastInsertId();
        $stmt = $this->statement();
        $otherTx = $this->transaction($stmt, -1000);
        $tx = $this->transaction($stmt, -1000, ['match_status' => 'manual']);
        $this->db->pdo()->prepare(
            'UPDATE tax_advance_schedules SET status="paid", paid_amount=1000, paid_on=?, matched_transaction_id=? WHERE id=?'
        )->execute([self::YEAR . '-06-15', $otherTx, $scheduleId]);
        $this->suggestionRepo->createAutoPosted([
            'supplier_id' => $this->supplierId,
            'bank_transaction_id' => $tx,
            'rule_id' => null,
            'source' => 'schedule',
            'debit_account_code' => '336',
            'credit_account_code' => '221',
            'amount' => 1000,
            'journal_entry_id' => $this->postPredpis('bank', $tx, '336', '221', 1000),
            'tax_advance_schedule_id' => $scheduleId,
        ]);

        $this->service->unpost($this->supplierId, $tx, $this->meta() + ['entry_date' => self::YEAR . '-06-16']);
        $row = $this->db->pdo()->query(
            "SELECT status, matched_transaction_id FROM tax_advance_schedules WHERE id={$scheduleId}"
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('paid', $row['status']);
        self::assertSame($otherTx, (int) $row['matched_transaction_id']);
    }

    public function testApprovalRejectsMalformedSaldoDetectorSuggestion(): void
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000);
        $suggestion = $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $this->supplierId,
            'bank_transaction_id' => $tx,
            'rule_id' => null,
            'source' => 'detector',
            'debit_account_code' => '321',
            'credit_account_code' => '221',
            'amount' => 1000,
        ]);

        try {
            $this->service->approveSuggestion($this->supplierId, (int) $suggestion['id'], $this->meta());
            self::fail('Saldokontní kontace detektoru nesmí projít schválením.');
        } catch (PostingException $e) {
            self::assertSame('rule_account_forbidden', $e->errorCode);
        }
    }
}
