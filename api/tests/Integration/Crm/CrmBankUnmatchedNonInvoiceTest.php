<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\NonInvoiceBankTransactionScope;
use MyInvoice\Service\Crm\CrmAggregationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Příchozí noha vlastního převodu nemá fakturu, se kterou by se spárovala — protějškem
 * je druhá noha na jiném vlastním účtu. `match_status` jí přesto zůstává `unmatched`,
 * takže svítila v akci „Spáruj platby z banky" i v počítadle výpisu.
 *
 * Mzdová větev {@see NonInvoiceBankTransactionScope} se tu nesedí: mzdové tabulky jsou
 * immutable a po řádcích z testovací DB uklidit nejdou.
 */
#[Group('integration')]
final class CrmBankUnmatchedNonInvoiceTest extends TestCase
{
    private const TEST_ACCOUNT = '1000000005';
    private const TEST_BANK_CODE = '0100';

    private Connection $db;
    private CrmAggregationService $crm;
    private int $supplierId = 0;
    private int $statementId = 0;
    private int $currencyId = 0;
    /** @var int[] */
    private array $transactions = [];
    /** @var int[] */
    private array $suggestions = [];
    /** @var int[] */
    private array $entries = [];
    private string $today;

    protected function setUp(): void
    {
        $this->today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->crm = $c->get(CrmAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->suggestions as $id) {
            $pdo->prepare('DELETE FROM bank_posting_suggestions WHERE id = ?')->execute([$id]);
        }
        foreach ($this->entries as $id) {
            $pdo->prepare('DELETE FROM journal_entry_lines WHERE entry_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM journal_entries WHERE id = ?')->execute([$id]);
        }
        foreach ($this->transactions as $id) {
            $pdo->prepare('DELETE FROM bank_transactions WHERE id = ?')->execute([$id]);
        }
        if ($this->statementId > 0) {
            $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$this->statementId]);
        }
        if ($this->currencyId > 0) {
            $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$this->currencyId]);
        }
    }

    public function testVlastniPrevodNeniNesparovanaPlatba(): void
    {
        $before = $this->bankUnmatchedCount();

        $regular = $this->insertIncoming();
        $transfer = $this->insertIncoming();
        $this->insertSuggestion($transfer, 'auto_posted');
        // Zamítnutý návrh převodu pohyb za vlastní převod nepovažuje — faktura se čeká dál.
        $rejected = $this->insertIncoming();
        $this->insertSuggestion($rejected, 'rejected');

        self::assertSame($before + 2, $this->bankUnmatchedCount());
        self::assertSame([$transfer], $this->nonInvoiceIds());
        self::assertNotContains($regular, $this->nonInvoiceIds());
    }

    private function bankUnmatchedCount(): int
    {
        foreach ($this->crm->actionItems($this->supplierId, null)['items'] as $item) {
            if ($item['type'] === 'bank_unmatched') {
                return (int) $item['count'];
            }
        }
        return 0;
    }

    /** @return list<int> pohyby testovacího výpisu, které počítadlo výpisu bere jako vyřešené bez faktury */
    private function nonInvoiceIds(): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id FROM bank_transactions bt
              WHERE bt.statement_id = ? AND bt.match_status = 'unmatched'
                AND " . NonInvoiceBankTransactionScope::sql($this->supplierId, 'bt.id') . '
              ORDER BY bt.id'
        );
        $stmt->execute([$this->statementId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function insertIncoming(): int
    {
        $pdo = $this->db->pdo();
        if ($this->statementId === 0) {
            // Vlastnictví pohybu se odvozuje shodou účtu výpisu s účtem firmy v currencies.
            $pdo->prepare(
                "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, account_number, bank_code)
                 VALUES (?, 'CZK', 'Test převodu', 'Kč', 'Koruna', 'Koruna', ?, ?)"
            )->execute([$this->supplierId, self::TEST_ACCOUNT, self::TEST_BANK_CODE]);
            $this->currencyId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, statement_date)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $this->supplierId, 'crm-own-transfer-test.gpc', bin2hex(random_bytes(32)),
                self::TEST_ACCOUNT, self::TEST_BANK_CODE, $this->today,
            ]);
            $this->statementId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, source, match_status)
             VALUES (?, ?, 10000.00, 'statement', 'unmatched')"
        )->execute([$this->statementId, $this->today]);
        $id = (int) $pdo->lastInsertId();
        $this->transactions[] = $id;
        return $id;
    }

    /**
     * Platba daně / poplatku fakturu nikdy mít nebude: zápis se nedotkne žádného
     * saldokontního účtu, takže pohyb uzavírá sám. Naopak zápis přes 311 doklad
     * pořád čeká — kdyby ho scope pohltil, zmizely by z počítadla i skutečné
     * nespárované úhrady faktur.
     */
    public function testPohybZauctovanyMimoSaldoNecekaFakturu(): void
    {
        $tax = $this->accountId('341');
        $bank = $this->accountId('221');
        $receivable = $this->accountId('311');
        if ($tax === 0 || $bank === 0 || $receivable === 0) {
            self::markTestSkipped('Osnova tenanta nemá 341/221/311.');
        }

        $before = $this->bankUnmatchedCount();

        $taxPayment = $this->insertIncoming();
        $this->insertPostedEntry($taxPayment, $tax, $bank);
        $invoicePayment = $this->insertIncoming();
        $this->insertPostedEntry($invoicePayment, $bank, $receivable);

        self::assertContains($taxPayment, $this->nonInvoiceIds(), 'Platba daně fakturu nečeká.');
        self::assertNotContains($invoicePayment, $this->nonInvoiceIds(),
            'Zápis přes 311 je úhrada faktury — ta se párovat má.');
        self::assertSame($before + 1, $this->bankUnmatchedCount(),
            'Do počítadla nespárovaných přibude jen ten pohyb přes 311.');
    }

    private function accountId(string $prefix): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code LIKE ? ORDER BY is_synthetic DESC, id LIMIT 1'
        );
        $stmt->execute([$this->supplierId, $prefix . '%']);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function insertPostedEntry(int $txId, int $debitAccountId, int $creditAccountId): void
    {
        $pdo = $this->db->pdo();
        $period = $pdo->prepare(
            'SELECT id FROM accounting_periods WHERE supplier_id = ? AND ? BETWEEN starts_on AND ends_on LIMIT 1'
        );
        $period->execute([$this->supplierId, $this->today]);
        $periodId = (int) ($period->fetchColumn() ?: 0);
        if ($periodId === 0) {
            self::markTestSkipped('Pro dnešek není založené účetní období.');
        }

        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, source_id, description, posted_at)
             VALUES (?, ?, ?, 'bank', ?, 'Test scope', NOW())"
        )->execute([$this->supplierId, $periodId, $this->today, $txId]);
        $entryId = (int) $pdo->lastInsertId();
        $this->entries[] = $entryId;

        $line = $pdo->prepare(
            'INSERT INTO journal_entry_lines (supplier_id, entry_id, account_id, side, amount)
             VALUES (?, ?, ?, ?, 10000.00)'
        );
        $line->execute([$this->supplierId, $entryId, $debitAccountId, 'debit']);
        $line->execute([$this->supplierId, $entryId, $creditAccountId, 'credit']);
    }

    private function insertSuggestion(int $txId, string $status): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_posting_suggestions
                (supplier_id, bank_transaction_id, source, debit_account_code, credit_account_code, amount, status)
             VALUES (?, ?, 'transfer', '221', '261', 10000.00, ?)"
        )->execute([$this->supplierId, $txId, $status]);
        $this->suggestions[] = (int) $pdo->lastInsertId();
    }
}
