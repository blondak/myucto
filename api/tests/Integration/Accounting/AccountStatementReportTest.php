<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\AccountStatementService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační test opisu účtu (Epic F2, T6): běžící zůstatek řádek po řádku,
 * opening + Σ pohybů == closing, stránkování nemění zůstatky a položky nesou
 * source_type/source_id (drill-down na prvotní doklad).
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class AccountStatementReportTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private AccountStatementService $statement;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db        = $container->get(Connection::class);
            $this->posting   = $container->get(PostingService::class);
            $this->statement = $container->get(AccountStatementService::class);
            $this->periods   = $container->get(AccountingPeriodRepository::class);
            $seeder          = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Izolovaný supplier (kopie FK hodnot z prvního): kumulativní PS rozvahových
        // účtů (R6) jde přes celou historii deníku, sdílený dev supplier s reálnými
        // zápisy by rozbil bilanční asserty a previousPeriod.
        $isoStmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        );
        $isoStmt->execute(['Izolovaný test s.r.o.', 'izolace@example.com', $this->supplierId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    // ── T6 ────────────────────────────────────────────────────────────────

    public function testRunningBalanceAndTotals(): void
    {
        $accountId = $this->accountId('211');
        $this->manual([
            self::l('211', 'debit', 100.00),
            self::l('602', 'credit', 100.00),
        ], self::YEAR . '-01-10');
        $this->manual([
            self::l('518', 'debit', 30.00),
            self::l('211', 'credit', 30.00),
        ], self::YEAR . '-02-05');
        $this->manual([
            self::l('211', 'debit', 50.00),
            self::l('602', 'credit', 50.00),
        ], self::YEAR . '-03-03');

        $data = $this->statement->build($this->supplierId, $accountId, self::YEAR . '-01-01', self::YEAR . '-12-31', 1, 50);

        self::assertSame('211', $data['account']['code']);
        self::assertSame(0, self::cents($data['opening_balance']));
        self::assertSame(3, $data['total']);
        self::assertCount(3, $data['items']);

        // běžící zůstatek řádek po řádku
        self::assertSame(self::cents(100.00), self::cents($data['items'][0]['balance']));
        self::assertSame(self::cents(70.00), self::cents($data['items'][1]['balance']));
        self::assertSame(self::cents(120.00), self::cents($data['items'][2]['balance']));

        // opening + Σ pohybů == closing
        $sum = self::cents($data['opening_balance']);
        foreach ($data['items'] as $item) {
            $sum += $item['side'] === 'debit' ? self::cents($item['amount']) : -self::cents($item['amount']);
        }
        self::assertSame($sum, self::cents($data['closing_balance']), 'opening + Σ pohybů == closing (haléře).');
        self::assertSame(self::cents(150.00), self::cents($data['turnover_md']));
        self::assertSame(self::cents(30.00), self::cents($data['turnover_d']));

        // source_type v položkách (manual zápis nemá source_id)
        foreach ($data['items'] as $item) {
            self::assertSame('manual', $item['source_type']);
            self::assertNull($item['source_id']);
        }
    }

    public function testPaginationDoesNotChangeBalances(): void
    {
        $accountId = $this->accountId('211');
        $this->manual([self::l('211', 'debit', 100.00), self::l('602', 'credit', 100.00)], self::YEAR . '-01-10');
        $this->manual([self::l('518', 'debit', 30.00), self::l('211', 'credit', 30.00)], self::YEAR . '-02-05');
        $this->manual([self::l('211', 'debit', 50.00), self::l('602', 'credit', 50.00)], self::YEAR . '-03-03');

        $all = $this->statement->build($this->supplierId, $accountId, self::YEAR . '-01-01', self::YEAR . '-12-31', 1, 50);

        foreach ([1 => 100.00, 2 => 70.00, 3 => 120.00] as $page => $expected) {
            $paged = $this->statement->build($this->supplierId, $accountId, self::YEAR . '-01-01', self::YEAR . '-12-31', $page, 1);
            self::assertCount(1, $paged['items'], "Stránka {$page} má 1 položku.");
            self::assertSame(3, $paged['total']);
            self::assertSame(self::cents($expected), self::cents($paged['items'][0]['balance']), "Zůstatek na stránce {$page} sedí s celkovým výpisem.");
            self::assertSame($all['items'][$page - 1]['entry_id'], $paged['items'][0]['entry_id']);
            self::assertSame(self::cents($all['closing_balance']), self::cents($paged['closing_balance']), 'closing_balance nezávisí na stránce.');
        }
    }

    public function testOpeningWindowAndInvoiceSourceDrilldown(): void
    {
        // PS: pohyb v lednu, opis od února → opening = leden
        $accountId311 = $this->accountId('311');
        $clientId  = $this->client('Odběratel s.r.o.');
        $invoiceId = $this->sale('FV-2099-OPIS-1', $clientId, '1', 1000.00, 210.00, 21.00);
        $lines     = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, [
            'entry_date' => self::YEAR . '-01-15', 'document_no' => 'FV-2099-OPIS-1', 'posted_by' => $this->userId,
        ]);

        $full = $this->statement->build($this->supplierId, $accountId311, self::YEAR . '-01-01', self::YEAR . '-12-31', 1, 50);
        self::assertCount(1, $full['items']);
        self::assertSame('invoice', $full['items'][0]['source_type'], 'Položka nese source_type prvotního dokladu.');
        self::assertSame($invoiceId, (int) $full['items'][0]['source_id'], 'Položka nese source_id prvotního dokladu.');
        self::assertSame(self::cents(1210.00), self::cents($full['items'][0]['balance']));

        $fromFeb = $this->statement->build($this->supplierId, $accountId311, self::YEAR . '-02-01', self::YEAR . '-12-31', 1, 50);
        self::assertSame(self::cents(1210.00), self::cents($fromFeb['opening_balance']), 'PS opisu od února = lednový pohyb.');
        self::assertCount(0, $fromFeb['items']);
        self::assertSame(self::cents(1210.00), self::cents($fromFeb['closing_balance']));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function accountId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?'
        );
        $stmt->execute([$this->supplierId, $code]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     * @param array<string,mixed> $meta
     */
    private function manual(array $lines, string $date, array $meta = []): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            $lines,
            array_merge(['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId], $meta),
        );
    }

    /**
     * @return array{account_code:string, side:string, amount:float}
     */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    private function client(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function sale(string $varsymbol, int $clientId, string $code, float $base, float $vat, float $rate): int
    {
        $with = $base + $vat;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", ?, ?)'
        );
        $issue = self::YEAR . '-01-15';
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $base, $vat, $with, $code, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $itemStmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, 0)'
        );
        $itemStmt->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $with]);
        return $id;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
