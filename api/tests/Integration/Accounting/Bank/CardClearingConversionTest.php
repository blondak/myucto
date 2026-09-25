<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\Bank\CardClearingConversion;
use PHPUnit\Framework\Attributes\Group;

/**
 * Převod plateb kartou zaúčtovaných přes mezičlen na přímé účtování banky
 * ({@see CardClearingConversion}): bankovní zápis 378.x/221 a jeho vypořádání 321/378.x
 * se sloučí na místě do jednoho zápisu 321 [+563] / 221, vypořádání se smaže, mezičlen
 * nemá obrat, prázdná analytika karty zmizí z osnovy. Schválené období zůstává.
 *
 * Zápisy starého tvaru se zakládají přímo v deníku (aplikace je už neumí vytvořit).
 * Izolace: rok 2099, sdílená transakce BankPostingTestCase (rollback v tearDown).
 */
#[Group('integration')]
final class CardClearingConversionTest extends BankPostingTestCase
{
    private const CARD_CODE = '378.101';

    private CardClearingConversion $conversion;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->db->pdo();
        if (!(bool) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_cards' AND COLUMN_NAME = 'analytic_suffix'")->fetchColumn()) {
            self::markTestSkipped('Schéma už sloupce mezičlenu nemá - převod není co ověřovat.');
        }
        $pdo->prepare(
            "INSERT INTO card_clearing_settings (supplier_id, enabled, effective_from, clearing_synthetic)
             VALUES (?, 1, '" . self::YEAR . "-01-01', '378')
             ON DUPLICATE KEY UPDATE enabled = 1, clearing_synthetic = '378'"
        )->execute([$this->supplierId]);
        $pdo->prepare('UPDATE payment_cards SET analytic_suffix = NULL WHERE supplier_id = ? AND analytic_suffix = ?')->execute([$this->supplierId, '101']);
        $pdo->prepare(
            "INSERT INTO payment_cards (supplier_id, label, last4, card_type, is_active, analytic_suffix)
             VALUES (?, 'Karta převod', '5151', 'debit', 1, '101')"
        )->execute([$this->supplierId]);
        if ($this->accounts->findByCode($this->supplierId, self::CARD_CODE) === null) {
            $parent = $this->accounts->findByCode($this->supplierId, '378');
            self::assertNotNull($parent);
            $this->accounts->insert($this->supplierId, [
                'account_code' => self::CARD_CODE, 'name' => 'Karta ****5151', 'account_type' => 'asset',
                'normal_side' => 'debit', 'is_synthetic' => false, 'parent_id' => (int) $parent['id'], 'is_active' => true,
            ]);
        }
        $this->conversion = new CardClearingConversion($this->db, null, $this->service);
    }

    public function testBankEntryAndSettlementMergeIntoOneDirectEntry(): void
    {
        $tx = $this->transaction($this->statement(), -1000.00, ['match_status' => 'manual']);
        $bank = $this->entry('bank', $tx, 'BCR-06', [[self::CARD_CODE, 'debit', 1000.00], ['221', 'credit', 1000.00]]);
        $settlement = $this->entry('card_settlement', $tx, 'BCR-06', [['321', 'debit', 990.00], ['563', 'debit', 10.00], [self::CARD_CODE, 'credit', 1000.00]]);

        $result = $this->conversion->run($this->supplierId, true, $this->userId);

        self::assertSame(1, $result['merged'], json_encode($result['suppliers'][$this->supplierId] ?? $result));
        $lines = $this->linesByAccountCode($bank);
        self::assertEqualsWithDelta(990.00, $lines['321']['debit'] ?? 0.0, 0.001, json_encode($lines));
        self::assertEqualsWithDelta(10.00, $lines['563']['debit'] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(1000.00, $lines['221']['credit'] ?? 0.0, 0.001);
        self::assertArrayNotHasKey(self::CARD_CODE, $lines);
        self::assertNull($this->journal->find($settlement, $this->supplierId), 'Vypořádání se fyzicky smaže, žádné storno.');
        self::assertSame('BCR-06', $this->journal->find($bank, $this->supplierId)['document_no'], 'Číslo dokladu bankovního zápisu zůstává.');
        self::assertSame(0, $this->reversalCount(), 'Převod nestornuje, přepisuje na místě.');
        self::assertNull($this->accounts->findByCode($this->supplierId, self::CARD_CODE), 'Prázdná analytika karty zmizí z osnovy.');

        $again = $this->conversion->run($this->supplierId, true, $this->userId);
        self::assertSame(0, $again['merged'] + $again['released'] + $again['pairs_deleted'] + $again['accounts_deleted'], 'Druhý běh nemá co převádět.');
        self::assertSame(0, $this->actionable());
    }

    public function testWriteOffWithoutDocumentBecomesDirectExpenseAgainstBank(): void
    {
        $tx = $this->transaction($this->statement(), -80.00, ['match_status' => 'unmatched']);
        $bank = $this->entry('bank', $tx, 'BCR-06', [[self::CARD_CODE, 'debit', 80.00], ['221', 'credit', 80.00]]);
        $writeOff = $this->entry('card_writeoff', $tx, 'KARTA-' . $tx, [['548', 'debit', 80.00], [self::CARD_CODE, 'credit', 80.00]]);

        $this->conversion->run($this->supplierId, true, $this->userId);

        $lines = $this->linesByAccountCode($bank);
        self::assertEqualsWithDelta(80.00, $lines['548']['debit'] ?? 0.0, 0.001, json_encode($lines));
        self::assertEqualsWithDelta(80.00, $lines['221']['credit'] ?? 0.0, 0.001);
        self::assertArrayNotHasKey(self::CARD_CODE, $lines);
        self::assertNull($this->journal->find($writeOff, $this->supplierId));
    }

    public function testClearingPaymentWithoutSettlementReturnsToBankQueue(): void
    {
        $tx = $this->transaction($this->statement(), -45.00, ['match_status' => 'unmatched']);
        $bank = $this->entry('bank', $tx, 'BCR-06', [[self::CARD_CODE, 'debit', 45.00], ['221', 'credit', 45.00]]);

        $result = $this->conversion->run($this->supplierId, true, $this->userId);

        self::assertSame(1, $result['released'], json_encode($result['suppliers'][$this->supplierId] ?? $result));
        self::assertNull($this->journal->find($bank, $this->supplierId));
        self::assertSame([], $this->conversion->turnover($this->supplierId, [self::CARD_CODE]));
    }

    public function testApprovedPeriodIsLeftUntouchedAndReported(): void
    {
        $tx = $this->transaction($this->statement(), -300.00, ['match_status' => 'manual']);
        $bank = $this->entry('bank', $tx, 'BCR-06', [[self::CARD_CODE, 'debit', 300.00], ['221', 'credit', 300.00]]);
        $settlement = $this->entry('card_settlement', $tx, 'BCR-06', [['321', 'debit', 300.00], [self::CARD_CODE, 'credit', 300.00]]);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'approved' WHERE id = ?")->execute([$this->periodId]);

        $result = $this->conversion->run($this->supplierId, true, $this->userId);

        self::assertSame(0, $result['merged']);
        $blocked = $result['suppliers'][$this->supplierId]['blocked'];
        self::assertContains('period_approved', array_column($blocked, 'reason'), json_encode($blocked));
        self::assertNotNull($this->journal->find($settlement, $this->supplierId));
        self::assertArrayHasKey(self::CARD_CODE, $this->linesByAccountCode($bank));
        self::assertNotNull($this->accounts->findByCode($this->supplierId, self::CARD_CODE), 'Analytika s obratem zůstává.');
    }

    public function testDryRunChangesNothing(): void
    {
        $tx = $this->transaction($this->statement(), -1000.00, ['match_status' => 'manual']);
        $bank = $this->entry('bank', $tx, 'BCR-06', [[self::CARD_CODE, 'debit', 1000.00], ['221', 'credit', 1000.00]]);
        $settlement = $this->entry('card_settlement', $tx, 'BCR-06', [['321', 'debit', 1000.00], [self::CARD_CODE, 'credit', 1000.00]]);

        $plan = $this->conversion->run($this->supplierId, false);

        self::assertSame(1, $plan['merged']);
        self::assertGreaterThan(0, $this->actionable());
        self::assertNotNull($this->journal->find($settlement, $this->supplierId));
        self::assertArrayHasKey(self::CARD_CODE, $this->linesByAccountCode($bank));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    /** @param list<array{0:string,1:string,2:float}> $lines */
    private function entry(string $sourceType, int $sourceId, string $documentNo, array $lines): int
    {
        $map = $this->accounts->codeToIdMap($this->supplierId);
        return $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => self::YEAR . '-06-15',
            'document_no' => $documentNo,
            'description' => 'Platba kartou (starý tvar)',
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], array_map(static fn (array $l): array => [
            'account_id' => $map[$l[0]]['id'], 'side' => $l[1], 'amount' => $l[2],
        ], $lines));
    }

    /** Co by převod u testovací firmy ještě udělal. */
    private function actionable(): int
    {
        $plan = $this->conversion->plan($this->supplierId);
        return count($plan['merge']) + count($plan['release']) + count($plan['pairs']) + count($plan['empty_accounts']);
    }

    private function reversalCount(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId} AND reversed_by IS NOT NULL
               AND entry_date BETWEEN '" . self::YEAR . "-01-01' AND '" . self::YEAR . "-12-31'"
        )->fetchColumn();
    }
}
