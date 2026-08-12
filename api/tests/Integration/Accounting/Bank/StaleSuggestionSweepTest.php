<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\Accounting\Bank\StaleSuggestionSweep;
use MyInvoice\Service\Accounting\OperationType;
use PHPUnit\Framework\Attributes\Group;

/**
 * Periodický přepočet návrhů, jejichž důvod přestal platit.
 *
 * Úhrada vyhodnocená dřív, než v deníku vznikl předpis závazku, dostane „předpis
 * chybí" — a bez tohohle přepočtu se ta věta už nikdy nepřepíše. Testy hlídají obě
 * strany mince: co se přepočítat MÁ (transientní důvod), a čeho se přepočet nesmí
 * dotknout ani omylem (netransientní důvod, zavřené období, odmítnutý návrh).
 */
#[Group('integration')]
final class StaleSuggestionSweepTest extends BankPostingTestCase
{
    private StaleSuggestionSweep $sweep;
    private AutoPostingPolicyService $policy;
    private const LIABILITY_ACCOUNT = '343991';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sweep = $this->container->get(StaleSuggestionSweep::class);
        $this->policy = $this->container->get(AutoPostingPolicyService::class);
        $this->policy->upsertRow($this->supplierId, OperationType::REMITTANCE_VAT, 'auto', $this->userId);
        $this->policy->upsertRow($this->supplierId, OperationType::DETECTOR_TAX_REMITTANCE, 'auto', $this->userId);
        $parentId = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id={$this->supplierId} AND account_code='343'"
        )->fetchColumn();
        $this->db->pdo()->prepare(
            'INSERT INTO chart_of_accounts
                (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id)
             VALUES (?, ?, "Testovací zúčtování DPH", "liability", "credit", 0, ?)'
        )->execute([$this->supplierId, self::LIABILITY_ACCOUNT, $parentId]);
    }

    public function testTransientReasonIsReevaluatedAndPosted(): void
    {
        [$txId, $suggestionId] = $this->stalePayment(10000.00);
        self::assertSame('liability_prescription_missing', (string) $this->suggestionRow($suggestionId)['note']);
        self::assertSame(0, $this->entryCountForTx($txId), 'Bez předpisu se úhrada zaúčtovat nesmí.');

        // Předpis dorazí až teď (zúčtování DPH, závěrka, počáteční stav …) — a nikdo
        // se enginu na tenhle pohyb podruhé nezeptá. Od toho je přepočet.
        $this->postPredpis('manual', 990101, '548', self::LIABILITY_ACCOUNT, 10000.00);

        $report = $this->sweep->run(false, $this->supplierId);
        self::assertSame(1, $report['posted'], 'Úhrada krytá předpisem se má zaúčtovat.');
        self::assertSame(1, $this->entryCountForTx($txId));
        self::assertSame('superseded', (string) $this->suggestionRow($suggestionId)['status']);

        // Idempotence: druhý běh hned po prvním nesmí přidat nic.
        $second = $this->sweep->run(false, $this->supplierId);
        self::assertSame(0, $second['posted']);
        self::assertSame(0, $second['refreshed']);
        self::assertSame(1, $this->entryCountForTx($txId));
    }

    /**
     * Předpis existuje, jen nestačí — návrh zůstává návrhem, ALE s pravdivým důvodem.
     * Přesně tenhle případ (odvod 2 808 Kč proti předpisu 2 801 Kč) by jinak ve frontě
     * navždy tvrdil, že předpis chybí, a sedmikorunový rozdíl by nikdo nehledal.
     */
    public function testStillSuggestedButNoteIsRefreshedToCurrentTruth(): void
    {
        [$txId] = $this->stalePayment(2808.00);
        $this->postPredpis('manual', 990102, '548', self::LIABILITY_ACCOUNT, 2801.00);

        $report = $this->sweep->run(false, $this->supplierId);
        self::assertSame(0, $report['posted']);
        self::assertSame(1, $report['refreshed']);
        self::assertSame(0, $this->entryCountForTx($txId));

        $note = $this->currentNoteForTx($txId);
        self::assertSame('liability_prescription_short', $note, 'Poznámka musí říkat aktuální pravdu.');

        $second = $this->sweep->run(false, $this->supplierId);
        self::assertSame(0, $second['refreshed'], 'Přepsaná poznámka se už nemá o čem měnit.');
        self::assertSame(1, $second['unchanged']);
    }

    public function testDryRunChangesNothing(): void
    {
        [$txId, $suggestionId] = $this->stalePayment(10000.00);
        $this->postPredpis('manual', 990103, '548', self::LIABILITY_ACCOUNT, 10000.00);

        $report = $this->sweep->run(true, $this->supplierId);
        self::assertSame(1, $report['posted'], 'Report říká, co by se stalo …');
        self::assertSame(0, $this->entryCountForTx($txId), '… ale v DB nesmí zůstat nic.');
        self::assertSame('pending', (string) $this->suggestionRow($suggestionId)['status']);
    }

    public function testNonTransientReasonIsLeftAlone(): void
    {
        [$txId, $suggestionId] = $this->stalePayment(10000.00);
        // Důvod, který další zápis v deníku nezmění — přepočet by byl jen churn.
        $this->db->pdo()->prepare('UPDATE bank_posting_suggestions SET note = "low_confidence" WHERE id = ?')
            ->execute([$suggestionId]);
        $this->postPredpis('manual', 990104, '548', self::LIABILITY_ACCOUNT, 10000.00);

        $report = $this->sweep->run(false, $this->supplierId);
        self::assertSame(0, $report['candidates'], 'Netransientní důvod se nepřepočítává.');
        self::assertSame(0, $this->entryCountForTx($txId));
        $row = $this->suggestionRow($suggestionId);
        self::assertSame('pending', (string) $row['status']);
        self::assertSame('low_confidence', (string) $row['note']);
    }

    public function testClosedPeriodIsReportedNotPosted(): void
    {
        [$txId, $suggestionId] = $this->stalePayment(10000.00);
        $this->postPredpis('manual', 990105, '548', self::LIABILITY_ACCOUNT, 10000.00);
        $this->db->pdo()->prepare('UPDATE accounting_periods SET status = "approved" WHERE id = ?')
            ->execute([$this->periodId]);

        $report = $this->sweep->run(false, $this->supplierId);
        self::assertSame(1, $report['candidates']);
        self::assertSame(0, $report['reevaluated'], 'Do zavřeného roku se engine nesmí ani pustit.');
        self::assertSame(1, $report['skip_reasons']['period_closed'] ?? 0);
        self::assertSame(0, $this->entryCountForTx($txId));
        self::assertSame('pending', (string) $this->suggestionRow($suggestionId)['status']);
    }

    public function testSoftLockedPeriodIsReportedNotPosted(): void
    {
        [$txId] = $this->stalePayment(10000.00);
        $this->postPredpis('manual', 990106, '548', self::LIABILITY_ACCOUNT, 10000.00);
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, self::YEAR . '-12-31']);

        $report = $this->sweep->run(false, $this->supplierId);
        self::assertSame(1, $report['skip_reasons']['period_closed'] ?? 0);
        self::assertSame(0, $this->entryCountForTx($txId));
    }

    public function testRejectedSuggestionIsNotResurrected(): void
    {
        [$txId, $suggestionId] = $this->stalePayment(10000.00);
        $this->service->rejectSuggestion($this->supplierId, $suggestionId, $this->meta(), 'nechci');
        $this->postPredpis('manual', 990107, '548', self::LIABILITY_ACCOUNT, 10000.00);

        $report = $this->sweep->run(false, $this->supplierId);
        self::assertSame(0, $report['candidates'], 'Co člověk odmítl, přepočet neoživuje.');
        self::assertSame(0, $this->entryCountForTx($txId));
        self::assertSame('rejected', (string) $this->suggestionRow($suggestionId)['status']);
    }

    public function testSnoozedSuggestionIsLeftAlone(): void
    {
        [$txId, $suggestionId] = $this->stalePayment(10000.00);
        $this->suggestionRepo->snooze(
            $this->supplierId,
            $suggestionId,
            (new \DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s'),
            'later',
            $this->userId,
        );
        $this->postPredpis('manual', 990108, '548', self::LIABILITY_ACCOUNT, 10000.00);

        $report = $this->sweep->run(false, $this->supplierId);
        self::assertSame(0, $report['candidates'], 'Odložený návrh znamená „teď ne".');
        self::assertSame(0, $this->entryCountForTx($txId));
    }

    /**
     * Úhrada závazku bez předpisu → pending návrh s poznámkou „předpis chybí".
     *
     * Jde tou samou cestou jako import (pravidlo → politika → guard krytí závazku),
     * takže test nestaví umělý řádek v tabulce, ale reálný stav fronty.
     *
     * @return array{0:int,1:int} tx id, suggestion id
     */
    private function stalePayment(float $amount): array
    {
        $ruleId = $this->rule([
            'name' => 'Odvod DPH',
            'direction' => 'outgoing',
            'counterparty_account' => '7777777777',
            'debit_account_code' => self::LIABILITY_ACCOUNT,
            'credit_account_code' => '221',
            'mode' => 'auto',
            'amount_min' => 1.00,
            'amount_max' => 999999.00,
            'operation_type' => OperationType::REMITTANCE_VAT,
        ]);
        // Auto režim pravidla vyžaduje tři potvrzení (hit_count) — bez nich by politika
        // degradovala na „suggest" z úplně jiného důvodu, než jaký test zkoumá.
        $this->db->pdo()->prepare('UPDATE bank_posting_rules SET hit_count = 5 WHERE id = ?')->execute([$ruleId]);

        $txId = $this->transaction($this->statement(), -$amount, [
            'counterparty_account' => '7777777777',
            'description' => 'Odvod DPH',
        ]);
        $res = $this->service->handleTransaction($txId);
        self::assertSame('suggested', $res['action'], 'Bez předpisu musí engine skončit návrhem.');
        self::assertSame('liability_prescription_missing', $res['reason'] ?? null);

        return [$txId, (int) $res['suggestion_id']];
    }

    private function currentNoteForTx(int $txId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT note FROM bank_posting_suggestions
              WHERE supplier_id = ? AND bank_transaction_id = ?
                AND status IN ("pending","needs_input","blocked")
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$this->supplierId, $txId]);
        $note = $stmt->fetchColumn();
        return $note === false || $note === null ? null : (string) $note;
    }
}
