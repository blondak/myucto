<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Report\VatLedgerService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Penalizační faktura (úrok z prodlení, NV 351/2013): účtuje se 311 MD / 644 D
 * v plné výši (žádná noha 343) a je MIMO předmět DPH (§2 ZDPH) → nesmí se objevit
 * v DPH evidenci (VatLedgerService). Vše v transakci, rollback v tearDown.
 */
#[Group('integration')]
final class PenaltyInvoicePostingTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;
    private VatLedgerService $vatLedger;

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
            $this->journal   = $container->get(JournalEntryRepository::class);
            $this->periods   = $container->get(AccountingPeriodRepository::class);
            $this->vatLedger = $container->get(VatLedgerService::class);
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

    public function testPenaltyInvoicePostsToRevenue644WithoutVat(): void
    {
        $client    = $this->client('Dlužník s.r.o.');
        $penaltyId = $this->penalty('PEN-2099-001', $client, 986.30);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $penaltyId);
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $penaltyId,
            $lines,
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );

        $entry     = $this->journal->find($entryId, $this->supplierId);
        $byAccount = $this->linesByAccountCode($entry['lines']);

        self::assertEqualsWithDelta(986.30, $byAccount['311']['debit'], 0.001, '311 MD = celý úrok z prodlení.');
        self::assertEqualsWithDelta(986.30, $byAccount['644']['credit'], 0.001, '644 D = výnos z úroků z prodlení.');
        self::assertArrayNotHasKey('343', $byAccount, 'Úrok z prodlení je mimo DPH → žádná noha 343.');
        self::assertArrayNotHasKey('602', $byAccount, 'Penalizace není běžný výnos (602).');
        $this->assertBalanced($entry['lines']);
    }

    public function testPenaltyInvoiceExcludedFromVatLedger(): void
    {
        $client    = $this->client('Dlužník s.r.o.');
        $penaltyId = $this->penalty('PEN-2099-002', $client, 986.30);
        $normalId  = $this->sale('FV-2099-777', $client, 1000.00, 210.00);

        $rows = $this->vatLedger->rows($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31', true);
        $invoiceIds = array_map(static fn (array $r): int => (int) ($r['invoice_id'] ?? 0), $rows);

        self::assertNotContains($penaltyId, $invoiceIds, 'Penalizační faktura NESMÍ být v DPH evidenci (mimo předmět DPH).');
        self::assertContains($normalId, $invoiceIds, 'Sanity: běžná faktura v DPH evidenci je.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

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

    private function penalty(string $varsymbol, int $clientId, float $amount): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "penalty", ?, ?, ?, ?, ?, 0, ?, 0, ?, "issued", "3", ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $amount, $amount, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem($id, $amount, 0.0, 0.0);
        return $id;
    }

    private function sale(string $varsymbol, int $clientId, float $base, float $vat): int
    {
        $with = $base + $vat;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", "1", ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $base, $vat, $with, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem($id, $base, $vat, 21.00);
        return $id;
    }

    private function insertItem(int $invoiceId, float $base, float $vat, float $rate): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Úrok z prodlení', 1, 'ks', ?, ?, ?, ?, ?, ?, 0)"
        );
        $stmt->execute([$invoiceId, $base, $this->vatRateId, $rate, $base, $vat, $base + $vat]);
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,array{debit:float,credit:float}>
     */
    private function linesByAccountCode(array $lines): array
    {
        $codeById = [];
        $stmt = $this->db->pdo()->prepare('SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $codeById[(int) $r['id']] = (string) $r['account_code'];
        }
        $out = [];
        foreach ($lines as $l) {
            $code = $codeById[(int) $l['account_id']] ?? '?';
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][$l['side']] += (float) $l['amount'];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $lines */
    private function assertBalanced(array $lines): void
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $l) {
            $cents = (int) round((float) $l['amount'] * 100);
            if ($l['side'] === 'debit') {
                $debit += $cents;
            } else {
                $credit += $cents;
            }
        }
        self::assertSame($debit, $credit, 'Σ MD == Σ D (v haléřích).');
    }
}
