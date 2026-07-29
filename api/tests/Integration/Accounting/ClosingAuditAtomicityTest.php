<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\ActivityLogger;
use DI\Container;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * EP-4 — atomická auditní stopa uzávěrky. Ověřuje, že workflow auditní událost
 * (accounting.books_closed) se zapisuje ve STEJNÉ DB transakci jako účetní mutace
 * (uzavření knih), takže:
 *   - selhání zápisu auditu ROLLBACKNE účetní mutaci (období zůstane 'closing',
 *     žádný closing zápis v deníku, krok close_books není 'done', žádná událost),
 *   - úspěšný běh zapíše PŘESNĚ JEDNU událost (idempotence plyne z atomicity:
 *     selhaný pokus zaloguje 0, úspěšný retry právě 1 — nikdy duplicita).
 *
 * Nutná COMMITNUTÁ data (uzávěrka vlastní vlastní transakci — ownTx; ambientní
 * transakce testu by rollback uvnitř služby potlačila), proto explicitní úklid
 * v tearDown (DELETE dodavatele → CASCADE osnovy/období/deníku/kroků + activity_log).
 * Soft-skip bez cfg.php / DB.
 */
#[Group('integration')]
final class ClosingAuditAtomicityTest extends TestCase
{
    private const YEAR = 2099;
    private const ENDS_ON = self::YEAR . '-12-31';

    private Container $container;
    private Connection $db;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildApp()->getContainer();
            $this->db      = $this->container->get(Connection::class);
            $this->periods = $this->container->get(AccountingPeriodRepository::class);
            $seeder        = $this->container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        // COMMITNUTÝ seed (bez ambientní transakce) — uzávěrka běží ve vlastní tx.
        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "double_entry")'
        );
        $stmt->execute(['EP-4 audit atomicity s.r.o.', $czId, 'ep4-audit-' . uniqid() . '@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::ENDS_ON);

        // Nenulový zůstatek, aby uzavření knih vyprodukovalo closing zápis (jinak by
        // prázdné období uzavřelo bez postDocument a nebylo by co rollbackovat).
        $this->container->get(PostingService::class)->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1000.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.00],
        ], ['entry_date' => self::YEAR . '-05-01', 'posted_by' => $this->userId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->supplierId === 0) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Deník maž explicitně PŘED supplierem: složené FK journal_entry_lines→
        // chart_of_accounts (fk_jel_account_supplier, RESTRICT) blokuje CASCADE
        // smazání osnovy, dokud řádky deníku existují (closeBooks je založil).
        $pdo->prepare('DELETE FROM journal_entry_lines WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplierId]);
        // activity_log nemá FK na supplier (cross-cutting) → maž explicitně; zbytek
        // (osnova/období/kroky/řady) padne přes ON DELETE CASCADE ze supplier.
        $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        $this->db->close();
    }

    /**
     * Selhání auditu (accounting.books_closed) při uzavření knih → rollback: období
     * zůstává 'closing', v deníku žádný closing zápis, krok close_books není done,
     * a nevznikla ani auditní událost (akceptační kritérium EP-4).
     */
    public function testAuditFailureRollsBackBookClosing(): void
    {
        // Injektuj logger, který shodí PRÁVĚ událost uzavření knih (accounting.books_closed).
        // Přepis binding PŘED prvním resolvem ClosingService, ať dostane dvojníka.
        $this->container->set(ActivityLogger::class, $this->failingLoggerFor('accounting.books_closed'));
        $closing = $this->container->get(ClosingService::class);

        $this->runChainToPreClose($closing);
        self::assertSame('closing', $this->periodStatus(), 'Po zahájení a krocích je období ve stavu closing.');

        $threw = false;
        try {
            $closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        } catch (\Throwable $e) {
            $threw = true;
        }
        self::assertTrue($threw, 'Selhání auditu musí probublat jako výjimka (request skončí chybou).');

        // Rollback: účetní mutace se NESMÍ propsat.
        self::assertSame('closing', $this->periodStatus(), 'Selhání auditu rollbacklo přechod na closed — období zůstává closing.');
        self::assertSame(0, $this->closingEntryCount(), 'Closing zápis se rollbackl — v deníku není.');
        self::assertNotSame('done', $this->stepStatus('close_books'), 'Krok close_books se rollbackl (není done).');
        self::assertSame(0, $this->auditCount('accounting.books_closed'), 'Auditní událost se rollbackla — v activity_log není.');
    }

    /**
     * Úspěšné uzavření knih zapíše PRÁVĚ JEDNU událost books_closed a closing zápis
     * je v deníku. Spolu s rollback testem to dává idempotenci: selhaný pokus loguje 0,
     * úspěšný běh 1 — retry po selhání nikdy nevyrobí duplicitní událost.
     */
    public function testSuccessfulCloseLogsExactlyOneEvent(): void
    {
        $closing = $this->container->get(ClosingService::class);

        $this->runChainToPreClose($closing);
        $result = $closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertSame('closed', (string) ($result['status'] ?? ''), 'Uzavření knih proběhlo.');
        self::assertSame('closed', $this->periodStatus());
        self::assertSame(1, $this->closingEntryCount(), 'Vznikl právě jeden closing zápis.');
        self::assertSame(1, $this->auditCount('accounting.books_closed'), 'Vznikla PRÁVĚ JEDNA událost books_closed.');
        self::assertSame('done', $this->stepStatus('close_books'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function failingLoggerFor(string $failAction): ActivityLogger
    {
        return new class($this->db, $failAction) extends ActivityLogger {
            public function __construct(Connection $db, private readonly string $failAction)
            {
                parent::__construct($db);
            }

            public function log(
                string $action,
                ?int $userId = null,
                ?string $entityType = null,
                ?int $entityId = null,
                ?array $payload = null,
                ?string $ip = null,
                ?string $userAgent = null,
                ?int $supplierId = null,
            ): void {
                if ($action === $this->failAction) {
                    throw new \RuntimeException('injected audit failure for ' . $action);
                }
                parent::log($action, $userId, $entityType, $entityId, $payload, $ip, $userAgent, $supplierId);
            }
        };
    }

    /** Uzávěrkový průvodce do stavu těsně před uzavřením knih (kroky skip/prázdné). */
    private function runChainToPreClose(ClosingService $closing): void
    {
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $closing->start($sid, $pid, $this->rv(), $this->meta());
        $closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        $closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $closing->runFxRevaluation($sid, $pid, [], $this->rv(), $this->meta());
        $closing->confirmStep($sid, $pid, 'estimates', 'skipped', null, $this->rv(), $this->meta());
        $closing->confirmStep($sid, $pid, 'deferrals', 'skipped', null, $this->rv(), $this->meta());
        $closing->confirmStep($sid, $pid, 'provisions', 'skipped', null, $this->rv(), $this->meta());
        $closing->confirmStep($sid, $pid, 'income_tax', 'skipped', null, $this->rv(), $this->meta());
        $this->completeInventory($closing);
    }

    /** EP-6: dokončí inventarizaci rozvahových účtů (skutečný = účetní → resolved), aby closeBooks neblokoval. */
    private function completeInventory(ClosingService $closing): void
    {
        $items = [];
        foreach ($closing->inventoryPreview($this->supplierId, $this->periodId)['rows'] as $r) {
            $items[(int) $r['account_id']] = ['counted_balance' => (float) $r['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $closing->saveInventory($this->supplierId, $this->periodId, $this->rv(), ['complete' => true], $items, ['user_id' => $this->userId]);
    }

    private function periodStatus(): string
    {
        return (string) $this->periods->findById($this->supplierId, $this->periodId)['status'];
    }

    private function rv(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    private function closingEntryCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'closing' AND source_id = ?"
        );
        $stmt->execute([$this->supplierId, $this->periodId]);
        return (int) $stmt->fetchColumn();
    }

    private function stepStatus(string $stepKey): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status FROM accounting_closing_steps WHERE period_id = ? AND step_key = ?'
        );
        $stmt->execute([$this->periodId, $stepKey]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private function auditCount(string $action): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = ? AND entity_id = ?'
        );
        $stmt->execute([$this->supplierId, $action, $this->periodId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{user_id:int, posted_by:int} */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }
}
