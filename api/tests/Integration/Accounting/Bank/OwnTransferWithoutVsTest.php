<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Bank\StatementMatcher;
use PHPUnit\Framework\Attributes\Group;

/**
 * Převod mezi vlastními účty bez VS je jistá shoda, když protiúčet je evidovaný vlastní
 * účet firmy a na něm leží právě jeden zrcadlový protipohyb. Matcher ho spáruje dřív,
 * než by odchozí nohu nabídl k úhradě přijaté faktury (podle částky a data nebo podle
 * podobného názvu), takže převod se zaúčtuje automaticky a nečeká v dialogu.
 */
#[Group('integration')]
final class OwnTransferWithoutVsTest extends BankPostingTestCase
{
    private const SECOND_ACCOUNT = '1000000005';
    private const SECOND_BANK = '0100';
    private const FOREIGN_ACCOUNT = '1000000013';
    private const AMOUNT = 400000.00;

    private StatementMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, ?, 'auto', ?)
             ON DUPLICATE KEY UPDATE level = 'auto', updated_by = VALUES(updated_by)"
        );
        foreach (['bank.transfer.own', 'detector.own_transfer'] as $operationType) {
            $stmt->execute([$this->supplierId, $operationType, $this->userId]);
        }
        $this->matcher = $this->container->get(StatementMatcher::class);
        $this->registerOwnAccount($this->supplierId, self::ACCOUNT, self::BANK_CODE);
        $this->registerOwnAccount($this->supplierId, self::SECOND_ACCOUNT, self::SECOND_BANK);
        $this->currencyRow($this->supplierId, 'CZK', self::SECOND_ACCOUNT, self::SECOND_BANK);
    }

    /** Bez opravy: odchozí noha šla na podobnou přijatou fakturu ke kontrole a převod jen navržen. */
    public function testMirrorTransferWithoutVsIsPairedAndPostedInsteadOfInvoiceReview(): void
    {
        $vendor = $this->client('Převodní Testovací s.r.o.');
        $invoiceId = $this->purchaseInvoice('PF-0001', $vendor, self::AMOUNT);
        [$outTx, $inTx] = $this->mirrorLegs(self::YEAR . '-07-15', ['counterparty_name' => 'Převodní Testovací s.r.o.']);

        $results = $this->import([$outTx, $inTx]);

        self::assertEqualsWithDelta(self::AMOUNT, $this->linesByAccountCode($this->entryFor($outTx))['261']['debit'], 0.001);
        self::assertSame('own_transfer_matched', $results[$outTx]['reason'] ?? null);
        self::assertEmpty($results[$outTx]['requires_review'] ?? null);
        self::assertSame([$outTx, $inTx], $this->pairFor($outTx));
        self::assertSame(0, $this->pendingMatchSuggestions($outTx));
        self::assertEqualsWithDelta(self::AMOUNT, $this->linesByAccountCode($this->entryFor($inTx))['261']['credit'], 0.001);
        self::assertSame('received', $this->purchaseStatus($invoiceId));
    }

    /** Bez opravy: odchozí noha bez VS uhradila přijatou fakturu stejné částky (párování podle částky a data). */
    public function testMirrorTransferWithoutVsDoesNotPayPurchaseInvoiceByAmountAndDate(): void
    {
        $vendor = $this->client('Dodavatel Testovací s.r.o.');
        $invoiceId = $this->purchaseInvoice('PF-0002', $vendor, self::AMOUNT);
        [$outTx, $inTx] = $this->mirrorLegs(self::YEAR . '-06-15');

        $this->import([$outTx, $inTx]);

        self::assertSame([$outTx, $inTx], $this->pairFor($outTx));
        self::assertSame('received', $this->purchaseStatus($invoiceId));
        self::assertNotNull($this->entryFor($outTx));
        self::assertNotNull($this->entryFor($inTx));
    }

    public function testCounterpartyThatIsNotOwnAccountStaysUnpaired(): void
    {
        $outTx = $this->transaction($this->statement(), -self::AMOUNT, [
            'counterparty_account' => self::FOREIGN_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $inTx = $this->transaction($this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK), self::AMOUNT, [
            'counterparty_account' => self::ACCOUNT,
            'counterparty_bank' => self::BANK_CODE,
        ]);

        $results = $this->matcher->matchBatch([$outTx]);

        self::assertNotSame('own_transfer_matched', $results[$outTx]['reason'] ?? null);
        self::assertNull($this->pairFor($outTx));
        self::assertNull($this->pairFor($inTx));
    }

    public function testTwoCandidateLegsAreNotPairedAutomatically(): void
    {
        $outTx = $this->transaction($this->statement(), -self::AMOUNT, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $second = $this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK);
        foreach (['-06-15', '-06-16'] as $day) {
            $this->transaction($second, self::AMOUNT, [
                'posted_at' => self::YEAR . $day,
                'counterparty_account' => self::ACCOUNT,
                'counterparty_bank' => self::BANK_CODE,
            ]);
        }

        $results = $this->matcher->matchBatch([$outTx]);

        self::assertNotSame('own_transfer_matched', $results[$outTx]['reason'] ?? null);
        self::assertNull($this->pairFor($outTx));
    }

    public function testLegOnOtherCompanyStatementIsNotPaired(): void
    {
        $other = $this->otherSupplierId();
        $outTx = $this->transaction($this->statement(), -self::AMOUNT, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $this->transaction($this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK, $other), self::AMOUNT, [
            'counterparty_account' => self::ACCOUNT,
            'counterparty_bank' => self::BANK_CODE,
        ]);

        $results = $this->matcher->matchBatch([$outTx]);

        self::assertNotSame('own_transfer_matched', $results[$outTx]['reason'] ?? null);
        self::assertNull($this->pairFor($outTx));
    }

    public function testLegInOtherCurrencyIsNotPaired(): void
    {
        $outTx = $this->transaction($this->statement(), -self::AMOUNT, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $this->transaction($this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK), self::AMOUNT, [
            'currency' => 'EUR',
            'counterparty_account' => self::ACCOUNT,
            'counterparty_bank' => self::BANK_CODE,
        ]);

        $results = $this->matcher->matchBatch([$outTx]);

        self::assertNotSame('own_transfer_matched', $results[$outTx]['reason'] ?? null);
        self::assertNull($this->pairFor($outTx));
    }

    public function testIgnoredCounterLegIsNotPaired(): void
    {
        $outTx = $this->transaction($this->statement(), -self::AMOUNT, [
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $this->transaction($this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK), self::AMOUNT, [
            'counterparty_account' => self::ACCOUNT,
            'counterparty_bank' => self::BANK_CODE,
            'match_status' => 'ignored',
        ]);

        $results = $this->matcher->matchBatch([$outTx]);

        self::assertNotSame('own_transfer_matched', $results[$outTx]['reason'] ?? null);
        self::assertNull($this->pairFor($outTx));
    }

    /**
     * Stejné pořadí jako import výpisu ({@see \MyInvoice\Service\Bank\StatementImporter}):
     * párování dávky, potom zaúčtování; nález ke kontrole účtuje jen návrhem.
     *
     * @param list<int> $txIds
     * @return array<int,array<string,mixed>>
     */
    private function import(array $txIds): array
    {
        $results = $this->matcher->matchBatch($txIds);
        foreach ($results as $txId => $result) {
            $this->service->handleTransaction((int) $txId, $this->userId, !empty($result['requires_review']));
        }
        return $results;
    }

    /**
     * @param array<string,mixed> $outOver
     * @return array{0:int,1:int}
     */
    private function mirrorLegs(string $postedAt, array $outOver = []): array
    {
        $outTx = $this->transaction($this->statement(), -self::AMOUNT, $outOver + [
            'posted_at' => $postedAt,
            'counterparty_account' => self::SECOND_ACCOUNT,
            'counterparty_bank' => self::SECOND_BANK,
        ]);
        $inTx = $this->transaction($this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK), self::AMOUNT, [
            'posted_at' => $postedAt,
            'counterparty_account' => self::ACCOUNT,
            'counterparty_bank' => self::BANK_CODE,
        ]);
        return [$outTx, $inTx];
    }

    private function registerOwnAccount(int $supplierId, string $number, string $bank): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency,
                 account_canonical, kind, source, is_active)
             VALUES (?, "Testovací vlastní účet", ?, ?, ?, "CZK", ?, "current", "manual", 1)'
            . ' ON DUPLICATE KEY UPDATE currency = VALUES(currency), is_active = 1'
        )->execute([$supplierId, $number, $bank, $bank, $number]);
    }

    /** @return array{0:int,1:int}|null */
    private function pairFor(int $txId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT out_transaction_id, in_transaction_id FROM bank_transfer_matches
              WHERE supplier_id = ? AND (out_transaction_id = ? OR in_transaction_id = ?)'
        );
        $stmt->execute([$this->supplierId, $txId, $txId]);
        $row = $stmt->fetch(\PDO::FETCH_NUM);
        return $row === false ? null : [(int) $row[0], (int) $row[1]];
    }

    private function entryFor(int $txId): int
    {
        $entry = $this->journal->findBySource($this->supplierId, 'bank', $txId);
        self::assertNotNull($entry, 'Noha převodu musí být zaúčtovaná.');
        return (int) $entry['id'];
    }

    private function pendingMatchSuggestions(int $txId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM bank_match_suggestions
              WHERE supplier_id = ? AND bank_transaction_id = ? AND status = 'pending'"
        );
        $stmt->execute([$this->supplierId, $txId]);
        return (int) $stmt->fetchColumn();
    }

    private function purchaseStatus(int $invoiceId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        return (string) $stmt->fetchColumn();
    }
}
