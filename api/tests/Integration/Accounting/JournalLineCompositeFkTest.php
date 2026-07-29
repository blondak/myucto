<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #15 část C — composite FK (supplier_id, account_id) → chart_of_accounts
 * (supplier_id, id) na journal_entry_lines. Databáze musí odmítnout řádek deníku,
 * jehož účet patří jinému tenantovi (defense-in-depth za PostingService).
 *
 * Dva čerství suppliers (vlastní osnovy), transakce s rollbackem v tearDown.
 * Soft-skip bez cfg.php. FK chyba abortuje jen statement, ne celou transakci
 * (InnoDB), takže ji lze v rámci téže tx odchytit a pokračovat.
 */
#[Group('integration')]
final class JournalLineCompositeFkTest extends TestCase
{
    private const YEAR = 2097;

    private Connection $db;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;

    private int $supplierA = 0;
    private int $supplierB = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->seeder  = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->supplierA = $this->makeSupplier('FK test A s.r.o.', $czId, $currencyId, $vatRateId);
        $this->supplierB = $this->makeSupplier('FK test B s.r.o.', $czId, $currencyId, $vatRateId);
        $this->seeder->seedForSupplier($this->supplierA);
        $this->seeder->seedForSupplier($this->supplierB);
        $this->periods->create($this->supplierA, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    public function testCrossTenantAccountIsRejectedBySameSupplierAccountSucceeds(): void
    {
        // Platný zápis pro supplier A (211 MD / 602 D) → máme reálné entry_id.
        $entryId = $this->posting->postDocument($this->supplierA, 'manual', null, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 500.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 500.00],
        ], ['entry_date' => self::YEAR . '-06-15']);
        self::assertGreaterThan(0, $entryId);

        $accA = $this->accountId($this->supplierA, '518'); // vlastní účet A
        $accB = $this->accountId($this->supplierB, '518'); // cizí účet (supplier B)
        self::assertGreaterThan(0, $accA);
        self::assertGreaterThan(0, $accB);
        self::assertNotSame($accA, $accB, 'Každý tenant má vlastní řádky osnovy (jiná id).');

        // (1) Cross-tenant: řádek supplieru A s účtem supplieru B → composite FK musí odmítnout.
        $rejected = false;
        try {
            $this->insertLine($entryId, $this->supplierA, $accB);
        } catch (\PDOException $e) {
            $rejected = true;
            self::assertSame('23000', $e->errorInfo[0] ?? null, 'FK violation (integrity constraint).');
        }
        self::assertTrue($rejected, 'Composite FK odmítne řádek s účtem jiného tenanta.');

        // (2) Pozitivní kontrola: týž řádek s VLASTNÍM účtem A projde.
        $this->insertLine($entryId, $this->supplierA, $accA);
        $cnt = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entry_lines WHERE entry_id = {$entryId} AND account_id = {$accA} AND line_no = 99"
        )->fetchColumn();
        self::assertSame(1, $cnt, 'Řádek s vlastním účtem se uloží.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function insertLine(int $entryId, int $supplierId, int $accountId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
             VALUES (?, ?, ?, "debit", 1.00, 99)'
        )->execute([$entryId, $supplierId, $accountId]);
    }

    private function accountId(int $supplierId, string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function makeSupplier(string $name, int $czId, int $currencyId, int $vatRateId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "fk-composite@example.com", ?, ?)'
        );
        $stmt->execute([$name, $czId, $currencyId, $vatRateId]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
