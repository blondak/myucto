<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\BalanceInventoryService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy inventarizace rozvahových účtů (§29–30 ZoÚ, T2):
 * jen účty tříd 0–4 (asset/liability/equity), výsledkové účty (5xx/6xx) mimo,
 * KZ = netto konečný zůstatek, doložení dle prefixu, tenant izolace.
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class BalanceInventoryReportTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private PostingService $posting;
    private BalanceInventoryService $inventory;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
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
            $this->inventory = $container->get(BalanceInventoryService::class);
            $this->periods   = $container->get(AccountingPeriodRepository::class);
            $this->seeder    = $container->get(ChartOfAccountsSeeder::class);
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

        $isoStmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        );
        $isoStmt->execute(['Izolovaná inventarizace s.r.o.', 'inventarizace@example.com', $this->supplierId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $this->seeder->seedForSupplier($this->supplierId);
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

    public function testOnlyBalanceSheetAccountsIncludedWithNettedClosingBalance(): void
    {
        // 311 (asset)/321 (liability) rozvahové — MAJÍ být v soupisu;
        // 602 (revenue)/518 (expense) výsledkové — NESMÍ být v soupisu.
        $this->manual([
            self::l('311', 'debit', 1210.00),
            self::l('602', 'credit', 1000.00),
            self::l('343', 'credit', 210.00),
        ], self::YEAR . '-03-10');
        $this->manual([
            self::l('518', 'debit', 500.00),
            self::l('321', 'credit', 500.00),
        ], self::YEAR . '-03-12');
        // 321 částečně uhrazen — KZ musí být netto (500 - 200 = 300), ne hrubé obraty
        $this->manual([
            self::l('321', 'debit', 200.00),
            self::l('211', 'credit', 200.00),
        ], self::YEAR . '-04-01');

        $data = $this->inventory->build($this->supplierId, $this->periodId);

        self::assertNull($this->rowByCode($data['rows'], '602'), 'Výnosový účet (5xx/6xx) nesmí být v inventarizaci rozvahových účtů.');
        self::assertNull($this->rowByCode($data['rows'], '518'), 'Nákladový účet (5xx/6xx) nesmí být v inventarizaci rozvahových účtů.');

        $r311 = $this->rowByCode($data['rows'], '311');
        self::assertNotNull($r311, 'Účet 311 (pohledávky) musí být v soupisu.');
        self::assertSame(self::cents(1210.00), self::cents($r311['ks_md']));
        self::assertSame(0, self::cents($r311['ks_d']));
        self::assertSame('asset', $r311['account_type']);
        self::assertStringContainsStringIgnoringCase('saldokonto', $r311['documentation_hint']);

        $r321 = $this->rowByCode($data['rows'], '321');
        self::assertNotNull($r321, 'Účet 321 (závazky) musí být v soupisu.');
        self::assertSame(self::cents(300.00), self::cents($r321['ks_d']), 'KZ 321 netto po částečné úhradě = 300 D.');
        self::assertSame(0, self::cents($r321['ks_md']));
        self::assertSame('liability', $r321['account_type']);

        self::assertSame((string) $this->periods->findById($this->supplierId, $this->periodId)['ends_on'], $data['as_of'], 'Rozvahový den = konec období.');
    }

    public function testDocumentationHintsByAccountPrefix(): void
    {
        $this->manual([
            self::l('211', 'debit', 5000.00),
            self::l('602', 'credit', 5000.00),
        ], self::YEAR . '-02-01');
        $this->manual([
            self::l('221', 'debit', 3000.00),
            self::l('602', 'credit', 3000.00),
        ], self::YEAR . '-02-02');

        $data = $this->inventory->build($this->supplierId, $this->periodId);

        $r211 = $this->rowByCode($data['rows'], '211');
        self::assertNotNull($r211);
        self::assertStringContainsStringIgnoringCase('pokladní', $r211['documentation_hint']);

        $r221 = $this->rowByCode($data['rows'], '221');
        self::assertNotNull($r221);
        self::assertStringContainsStringIgnoringCase('bankovní výpis', $r221['documentation_hint']);
    }

    public function testTenantIsolation(): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 2", "Brno", "60200", ?, "druha-inv@example.com", ?, ?)'
        );
        $stmt->execute(['Druhá firma inventarizace s.r.o.', $this->czId, $this->currencyId, $this->vatRateId]);
        $supplier2 = (int) $this->db->pdo()->lastInsertId();
        $this->seeder->seedForSupplier($supplier2);
        $period2 = $this->periods->create($supplier2, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->posting->postDocument($supplier2, 'manual', null, [
            self::l('211', 'debit', 777.00),
            self::l('602', 'credit', 777.00),
        ], ['entry_date' => self::YEAR . '-03-01', 'posted_by' => $this->userId]);

        $this->manual([
            self::l('211', 'debit', 100.00),
            self::l('602', 'credit', 100.00),
        ], self::YEAR . '-03-01');

        $data = $this->inventory->build($this->supplierId, $this->periodId);
        $r211 = $this->rowByCode($data['rows'], '211');
        self::assertNotNull($r211);
        self::assertSame(self::cents(100.00), self::cents($r211['ks_md']), 'Zápisy druhého supplieru se do soupisu prvního nepromítnou.');

        $this->expectException(ReportException::class);
        $this->inventory->build($this->supplierId, $period2);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

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

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function rowByCode(array $rows, string $code): ?array
    {
        foreach ($rows as $row) {
            if ((string) $row['account_code'] === $code) {
                return $row;
            }
        }
        return null;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
