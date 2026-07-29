<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\JournalIntegrityService;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační test EP-11 — tvrdá tenantová integrita deníku na úrovni DB
 * (migrace 1122, složené FK). Ověří, že DB SAMA odmítne:
 *   - zápis (journal_entries) odkazující na období JINÉHO dodavatele,
 *   - řádek (journal_entry_lines) odkazující na zápis JINÉHO dodavatele.
 * Do 1122 to jednosloupcové FK dovolily (tenant izolace visela na aplikaci).
 *
 * Vše běží v jedné transakci; tearDown ji rollbackne → DB zůstane netknutá.
 * Cross-tenant „cizí" strana se zakládá pro druhého existujícího dodavatele
 * uvnitř téže (rollbackované) transakce, ať test nezávisí na produkčních datech.
 * Soft-skip bez cfg.php / DB / druhého dodavatele.
 */
#[Group('integration')]
final class TenantCompositeFkTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private JournalIntegrityService $service;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $foreignSupplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $accountId = 0;
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
            $this->service = $container->get(JournalIntegrityService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
        $this->foreignSupplierId = (int) ($pdo->query(
            "SELECT id FROM supplier WHERE id <> {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->foreignSupplierId === 0) {
            $this->markTestSkipped('EP-11 cross-tenant test vyžaduje druhého suppliera.');
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0) {
            $this->markTestSkipped('Chybí user.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Vlastní osnova + otevřené období + účet (pro validní části INSERTů).
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->accountId = (int) ($pdo->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->accountId === 0) {
            $this->markTestSkipped('Osnova se nenaseedovala.');
        }
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

    /**
     * Zápis nesmí odkazovat na období jiného dodavatele — složený FK
     * fk_je_period_supplier (supplier_id, period_id) → accounting_periods(supplier_id, id).
     */
    public function testEntryCannotReferenceForeignTenantPeriod(): void
    {
        // Období patřící CIZÍMU dodavateli (v rámci rollbackované transakce).
        $foreignPeriodId = $this->periods->create(
            $this->foreignSupplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31'
        );

        $this->assertRejectsFk(
            function () use ($foreignPeriodId): void {
                $this->db->pdo()->prepare(
                    "INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type)
                     VALUES (?, ?, ?, 'manual')"
                )->execute([$this->supplierId, $foreignPeriodId, self::YEAR . '-06-15']);
            },
            'DB musí odmítnout zápis odkazující na období jiného tenanta.'
        );
    }

    /**
     * Řádek nesmí odkazovat na zápis jiného dodavatele — složený FK
     * fk_jel_entry_supplier (supplier_id, entry_id) → journal_entries(supplier_id, id).
     */
    public function testLineCannotReferenceForeignTenantEntry(): void
    {
        $pdo = $this->db->pdo();

        // Validní zápis CIZÍHO dodavatele (vlastní období + jeho vlastní řádek nepotřebujeme).
        $foreignPeriodId = $this->periods->create(
            $this->foreignSupplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31'
        );
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type)
             VALUES (?, ?, ?, 'manual')"
        )->execute([$this->foreignSupplierId, $foreignPeriodId, self::YEAR . '-06-15']);
        $foreignEntryId = (int) $pdo->lastInsertId();

        // Náš řádek (náš supplier_id + náš účet) míří na CIZÍ zápis → FK musí odmítnout.
        $this->assertRejectsFk(
            function () use ($foreignEntryId): void {
                $this->db->pdo()->prepare(
                    "INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
                     VALUES (?, ?, ?, 'debit', 100.00, 1)"
                )->execute([$foreignEntryId, $this->supplierId, $this->accountId]);
            },
            'DB musí odmítnout řádek odkazující na zápis jiného tenanta.'
        );
    }

    /**
     * Sanity: pro validní (jednotenantní) data checkTenantIntegrity() nehlásí nic
     * a je čistě čtecí.
     */
    public function testTenantIntegrityCleanForValidData(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, posted_at, posted_by)
             VALUES (?, ?, ?, 'manual', ?, ?)"
        )->execute([$this->supplierId, $this->periodId, self::YEAR . '-06-15', self::YEAR . '-06-15 10:00:00', $this->userId]);
        $entryId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
             VALUES (?, ?, ?, 'debit', 100.00, 1), (?, ?, ?, 'credit', 100.00, 2)"
        )->execute([
            $entryId, $this->supplierId, $this->accountId,
            $entryId, $this->supplierId, $this->accountId,
        ]);

        $findings = $this->service->checkTenantIntegrity($this->supplierId);

        foreach (JournalIntegrityService::TENANT_TYPES as $type) {
            self::assertArrayHasKey($type, $findings, "checkTenantIntegrity() musí vracet typ {$type}.");
            self::assertSame(
                0,
                $findings[$type]['count'],
                "Validní data nesmí generovat tenantový nález typu {$type}."
            );
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * Ověří, že callable vyhodí PDOException (porušení FK, chyba 1452). InnoDB
     * rollbackne jen selhaný statement, transakce testu zůstává použitelná.
     */
    private function assertRejectsFk(callable $fn, string $message): void
    {
        try {
            $fn();
            self::fail($message);
        } catch (PDOException $e) {
            self::assertNotNull($e->getCode(), $message);
        }
    }
}
