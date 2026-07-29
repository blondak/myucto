<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Klíčové integrační testy BankPostingService (mini-epic AUTOMATIZACE, §8):
 *   - spárovaná CZK FV platba → zápis 221/311 + idempotence (H1 guard předpisu),
 *   - pravidlo suggest od 2. výskytu + auto při mode=auto,
 *   - avízo / cizí měna → skip.
 * Vše ve sdílené DB transakci + rollback v tearDown (izolace, rok 2099).
 */
#[Group('integration')]
final class BankPostingServiceTest extends TestCase
{
    private const YEAR = 2099;
    private const ACCOUNT = '112866706'; // currencies řádek supplier 1 (tenant resolution)
    private const BANK_CODE = '2250';

    private Connection $db;
    private BankPostingService $service;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsRepository $accounts;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->service = $container->get(BankPostingService::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->accounts = $container->get(ChartOfAccountsRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query("SELECT id FROM supplier WHERE accounting_mode='double_entry' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier double_entry/currency/user/country).');
        }
        // Účet výpisu musí patřit tomuto supplierovi (tenant resolution).
        $ownsAccount = (int) $pdo->query(
            "SELECT COUNT(*) FROM currencies WHERE supplier_id={$this->supplierId} AND account_number='" . self::ACCOUNT . "'"
        )->fetchColumn();
        if ($ownsAccount === 0) {
            $this->markTestSkipped('Testovací účet ' . self::ACCOUNT . ' nepatří supplierovi ' . $this->supplierId . '.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $pdo->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'bank.payment.matched', 'auto', ?)
             ON DUPLICATE KEY UPDATE level = 'auto', updated_by = VALUES(updated_by)"
        )->execute([$this->supplierId, $this->userId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testMatchedIncomingFvPostsAndIsIdempotent(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $invoiceId = $this->saleInvoice('FV-2099-900', $client, 5000.00);
        $this->postPredpis('invoice', $invoiceId, '311', '602', 5000.00);

        $stmtId = $this->statement();
        $txId = $this->transaction($stmtId, 5000.00, [
            'match_status' => 'auto_exact', 'matched_invoice_id' => $invoiceId,
        ]);
        $this->invoicePayment($invoiceId, $txId, 5000.00);

        $res = $this->service->handleTransaction($txId, $this->userId);
        self::assertSame('posted', $res['action'], 'Spárovaná CZK FV platba se zaúčtuje.');

        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(5000.00, $byAcc['221']['debit'], 0.001, '221 MD = částka.');
        self::assertEqualsWithDelta(5000.00, $byAcc['311']['credit'], 0.001, '311 D = alokace.');
        self::assertSame('bank', $this->sourceType((int) $res['entry_id']));

        // Idempotence: druhé volání = stejný zápis, žádná duplicita.
        $res2 = $this->service->handleTransaction($txId, $this->userId);
        self::assertSame('posted', $res2['action']);
        self::assertSame($res['entry_id'], $res2['entry_id'], 'Idempotentní rewrite → stejné entry id.');
        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id={$this->supplierId}
              AND source_type='bank' AND source_id={$txId}"
        )->fetchColumn();
        self::assertSame(1, $count, 'Právě jeden zápis pro transakci (unique slot).');
        $autoRows = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions
              WHERE supplier_id={$this->supplierId} AND bank_transaction_id={$txId}
                AND source='payment_match' AND status='auto_posted'"
        )->fetchColumn();
        self::assertSame(1, $autoRows, 'Idempotentní běh ponechá právě jeden aktivní protokol automatiky.');
    }

    public function testRuleSuggestThenAutoPost(): void
    {
        $ruleId = $this->rule([
            'name' => 'Odvod OSSZ', 'direction' => 'outgoing',
            'counterparty_account' => '77621', 'amount_min' => 20000.00, 'amount_max' => 30000.00,
            'debit_account_code' => '568', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);

        $stmtId = $this->statement();
        $tx1 = $this->transaction($stmtId, -24836.00, [
            'match_status' => 'unmatched', 'counterparty_account' => '77621', 'variable_symbol' => '1234',
        ]);
        $r1 = $this->service->handleTransaction($tx1, $this->userId);
        self::assertSame('suggested', $r1['action'], 'Pravidlo v režimu suggest → návrh.');
        $sug = $this->suggestionRow((int) $r1['suggestion_id']);
        self::assertSame('pending', $sug['status']);
        self::assertSame('rule', $sug['source']);
        self::assertSame('568', $sug['debit_account_code']);
        self::assertSame('221', $sug['credit_account_code']);
        self::assertEqualsWithDelta(24836.00, (float) $sug['amount'], 0.001);

        // Přepnutí na auto (má amount band; simulace po ověření).
        $this->db->pdo()->exec("UPDATE bank_posting_rules SET mode='auto', hit_count=3 WHERE id={$ruleId}");
        $this->db->pdo()->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'bank.rule.custom', 'auto', ?)
             ON DUPLICATE KEY UPDATE level=VALUES(level), updated_by=VALUES(updated_by)"
        )->execute([$this->supplierId, $this->userId]);

        $tx2 = $this->transaction($stmtId, -24836.00, [
            'match_status' => 'unmatched', 'counterparty_account' => '77621', 'variable_symbol' => '1234',
        ]);
        $r2 = $this->service->handleTransaction($tx2, $this->userId);
        self::assertSame('posted', $r2['action'], 'Ověřené auto pravidlo → přímý zápis.');
        $byAcc = $this->linesByAccountCode((int) $r2['entry_id']);
        self::assertEqualsWithDelta(24836.00, $byAcc['568']['debit'], 0.001);
        self::assertEqualsWithDelta(24836.00, $byAcc['221']['credit'], 0.001);

        // Protokol automatiky + hit_count.
        $autoRow = $this->db->pdo()->query(
            "SELECT status, journal_entry_id FROM bank_posting_suggestions
              WHERE bank_transaction_id={$tx2} AND status='auto_posted'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($autoRow, 'Vznikl protokolový řádek auto_posted.');
        self::assertSame((int) $r2['entry_id'], (int) $autoRow['journal_entry_id']);
        $hits = (int) $this->db->pdo()->query("SELECT hit_count FROM bank_posting_rules WHERE id={$ruleId}")->fetchColumn();
        self::assertSame(4, $hits, 'hit_count po auto zápisu.');
    }

    public function testEmailNoticeAndForeignCurrencySkipped(): void
    {
        $stmtId = $this->statement();

        // Avízo (source=email_notice) — nikdy se neúčtuje.
        $notice = $this->transaction($stmtId, 1000.00, ['match_status' => 'unmatched', 'source' => 'email_notice']);
        $rNotice = $this->service->handleTransaction($notice, $this->userId);
        self::assertSame('skipped', $rNotice['action']);
        self::assertSame('email_notice_provisional', $rNotice['reason']);

        // Cizí měna (EUR) — CZK-only guard.
        $eur = $this->transaction($stmtId, 1000.00, ['match_status' => 'unmatched', 'currency' => 'EUR']);
        $rEur = $this->service->handleTransaction($eur, $this->userId);
        self::assertSame('skipped', $rEur['action']);
        self::assertSame('fx_not_supported', $rEur['reason']);

        // Žádný z nich nevytvořil zápis ani suggestion.
        foreach ([$notice, $eur] as $txId) {
            $entries = (int) $this->db->pdo()->query(
                "SELECT COUNT(*) FROM journal_entries WHERE source_type='bank' AND source_id={$txId}"
            )->fetchColumn();
            self::assertSame(0, $entries, 'Skip nevytvořil zápis (tx ' . $txId . ').');
            $sugs = (int) $this->db->pdo()->query(
                "SELECT COUNT(*) FROM bank_posting_suggestions WHERE bank_transaction_id={$txId}"
            )->fetchColumn();
            self::assertSame(0, $sugs, 'Skip nevytvořil suggestion (tx ' . $txId . ').');
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function statement(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements (file_name, file_hash, account_number, bank_code, currency, statement_date, imported_by)
             VALUES (?, ?, ?, ?, "CZK", ?, ?)'
        )->execute([
            'test.gpc', hash('sha256', uniqid('bpst', true)), self::ACCOUNT, self::BANK_CODE,
            self::YEAR . '-06-15', $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $over
     */
    private function transaction(int $statementId, float $amount, array $over = []): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, variable_symbol,
                 counterparty_account, counterparty_bank, counterparty_name, description, match_status, matched_invoice_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $statementId,
            $over['source'] ?? 'statement',
            self::YEAR . '-06-15',
            $amount,
            $over['currency'] ?? 'CZK',
            $over['variable_symbol'] ?? null,
            $over['counterparty_account'] ?? null,
            $over['counterparty_bank'] ?? null,
            $over['counterparty_name'] ?? 'Protistrana',
            $over['description'] ?? 'Platba',
            $over['match_status'] ?? 'unmatched',
            $over['matched_invoice_id'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function invoicePayment(int $invoiceId, int $txId, float $amount): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, ?, "CZK", "bank", ?)'
        )->execute([$this->supplierId, $invoiceId, self::YEAR . '-06-15', $amount, $txId]);
    }

    /** Vloží zaúčtovaný nestornovaný předpis dokladu (guard H1). */
    private function postPredpis(string $sourceType, int $sourceId, string $debit, string $credit, float $amount): int
    {
        $map = $this->accounts->codeToIdMap($this->supplierId);
        return $this->journal->insert([
            'supplier_id'   => $this->supplierId,
            'period_id'     => $this->periodId,
            'entry_date'    => self::YEAR . '-06-10',
            'document_no'   => 'PREDPIS-' . $sourceId,
            'description'   => 'Předpis',
            'source_type'   => $sourceType,
            'source_id'     => $sourceId,
            'posted_at'     => date('Y-m-d H:i:s'),
            'posted_by'     => $this->userId,
        ], [
            ['account_id' => $map[$debit]['id'], 'side' => 'debit', 'amount' => $amount],
            ['account_id' => $map[$credit]['id'], 'side' => 'credit', 'amount' => $amount],
        ]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function rule(array $data): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO bank_posting_rules
                (supplier_id, name, direction, counterparty_account, amount_min, amount_max,
                 debit_account_code, credit_account_code, mode, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            $this->supplierId, $data['name'], $data['direction'], $data['counterparty_account'],
            $data['amount_min'], $data['amount_max'], $data['debit_account_code'],
            $data['credit_account_code'], $data['mode'],
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function client(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "t@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function saleInvoice(string $varsymbol, int $clientId, float $total): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "issued", "1", ?)'
        );
        $issue = self::YEAR . '-06-10';
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $total, $total, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    // ── asserts / lookups ────────────────────────────────────────────────────

    /**
     * @return array<string,array{debit:float,credit:float}>
     */
    private function linesByAccountCode(int $entryId): array
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        $out = [];
        foreach ($entry['lines'] as $l) {
            $code = $this->accountCode((int) $l['account_id']);
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][$l['side']] += (float) $l['amount'];
        }
        return $out;
    }

    private function accountCode(int $accountId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code FROM chart_of_accounts WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$accountId, $this->supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false ? '?' : (string) $v;
    }

    private function sourceType(int $entryId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT source_type FROM journal_entries WHERE id = ?');
        $stmt->execute([$entryId]);
        return (string) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function suggestionRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM bank_posting_suggestions WHERE id = ?');
        $stmt->execute([$id]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
