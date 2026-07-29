<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use PHPUnit\Framework\TestCase;

/**
 * Regrese: uložený precheck snímek zastará, když se po jeho uložení uzavře
 * PŘEDCHOZÍ období (prior_period_open přejde z chyby na ok). state() to musí
 * hlásit přes precheck_stale, aby průvodce nezobrazoval už neplatné červené
 * chyby a vyzval k opětovnému spuštění prechecku. Opětovný běh staleness zruší.
 */
final class PrecheckStaleTest extends TestCase
{
    private Connection $db;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $prevPeriodId = 0;
    private int $nextPeriodId = 0;
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
            $this->closing = $container->get(ClosingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
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

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute(['Precheck stale test s.r.o.', $czId, 'precheck-stale@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);

        $this->prevPeriodId = $this->periods->create($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $this->nextPeriodId = $this->periods->create($this->supplierId, 2099, '2099-01-01', '2099-12-31');
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

    public function testPrecheckSnapshotGoesStaleWhenPreviousPeriodCloses(): void
    {
        // 1) Precheck 2099 zatímco 2098 je OTEVŘENÉ → snímek nese prior_period_open=false.
        $rv = (int) $this->periods->findById($this->supplierId, $this->nextPeriodId)['row_version'];
        $payload = $this->closing->runPrecheck($this->supplierId, $this->nextPeriodId, $rv, $this->meta());
        self::assertFalse(
            $this->checkOk($payload['checks'], 'prior_period_open'),
            'Snímek při otevřeném 2098 musí mít prior_period_open=false.',
        );

        // Hned po uložení snímek odpovídá živému stavu → není stale.
        self::assertFalse(
            $this->closing->state($this->supplierId, $this->nextPeriodId)['precheck_stale'],
            'Čerstvý precheck není zastaralý.',
        );

        // 2) 2098 se uzavře (simulace close — prior_period_open živě přejde na ok).
        $upd = $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ? AND supplier_id = ?");
        $upd->execute([$this->prevPeriodId, $this->supplierId]);

        // 3) Snímek 2099 je teď zastaralý (živě prior_period_open=ok, snímek=false).
        self::assertTrue(
            $this->closing->state($this->supplierId, $this->nextPeriodId)['precheck_stale'],
            'Po uzavření předchozího období musí být precheck 2099 označen jako zastaralý.',
        );

        // 4) Opětovné spuštění prechecku staleness zruší a prior_period_open projde.
        $rv = (int) $this->periods->findById($this->supplierId, $this->nextPeriodId)['row_version'];
        $payload = $this->closing->runPrecheck($this->supplierId, $this->nextPeriodId, $rv, $this->meta());
        self::assertTrue(
            $this->checkOk($payload['checks'], 'prior_period_open'),
            'Po uzavření 2098 nový snímek 2099 má prior_period_open=ok.',
        );
        self::assertFalse(
            $this->closing->state($this->supplierId, $this->nextPeriodId)['precheck_stale'],
            'Po opětovném běhu už precheck není zastaralý.',
        );
    }

    /** @param list<array<string,mixed>> $checks */
    private function checkOk(array $checks, string $key): bool
    {
        foreach ($checks as $c) {
            if (($c['key'] ?? null) === $key) {
                return (bool) $c['ok'];
            }
        }
        self::fail('Kontrola ' . $key . ' ve výsledku prechecku chybí.');
    }

    /** @return array{user_id:int, posted_by:int} */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }
}
