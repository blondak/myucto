<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Closing\TaxBaseReportAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Integrační test evidenčního podkladu DPPO (Epic F4, §6.2 I25, R19):
 * rozdíl daňových a účetních odpisů roku, klasifikace daňové uznatelnosti
 * zůstatkové ceny při vyřazení (§24/2/b, §25/1/t, §24/2/l ZDP) — ŽÁDNÉ
 * účtování, jen report.
 *
 * Izolovaný supplier, transakce s rollbackem v tearDown, soft-skip bez cfg.php.
 */
#[Group('integration')]
final class TaxBaseReportTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private TaxBaseReportAction $action;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
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
            $this->action  = $container->get(TaxBaseReportAction::class);
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
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "f4-dppo@example.com", ?, ?, "double_entry")'
        );
        $stmt->execute(['F4 DPPO test s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    // ── I25 ──────────────────────────────────────────────────────────────────

    public function testI25DepreciationDiffAndDisposalClassification(): void
    {
        // Prodaný majetek: daňový odpis 100 000, účetní 80 000, daňová ZC 30 000
        $soldId = $this->asset('INV-001', 'Stroj CNC', 400000.00, 'sold', self::YEAR . '-06-30');
        $this->depreciation($soldId, 'tax', 100000.00, 30000.00);
        $this->depreciation($soldId, 'accounting', 80000.00, 35000.00);

        // Darovaný majetek bez odpisových řádků → tax ZC fallback = vstupní cena
        $this->asset('INV-002', 'Notebook (dar)', 50000.00, 'donated', self::YEAR . '-08-01');

        $res = $this->report(self::YEAR);
        self::assertSame(200, $res['status']);
        $body = $res['body'];

        // (a) odpisy: tax 100 000 vs. accounting 80 000 → diff 20 000
        self::assertSame(self::cents(100000.00), self::cents($body['depreciation']['tax_total']));
        self::assertSame(self::cents(80000.00), self::cents($body['depreciation']['accounting_total']));
        self::assertSame(self::cents(20000.00), self::cents($body['depreciation']['difference']), 'Rozdíl odpisů = úprava základu daně.');

        // (b) vyřazení: sold → full (§24/2/b), donated → none (§25/1/t)
        self::assertCount(2, $body['disposals']);
        $byInv = [];
        foreach ($body['disposals'] as $disposal) {
            $byInv[(string) $disposal['inventory_number']] = $disposal;
        }
        $sold = $byInv['INV-001'];
        self::assertSame('sold', $sold['disposal_type']);
        self::assertSame('full', $sold['deductibility'], 'Prodej — daňová ZC plně uznatelná (§24/2/b ZDP).');
        self::assertSame(self::cents(30000.00), self::cents((float) $sold['tax_residual_value']), 'Daňová ZC z posledního tax řádku.');

        $donated = $byInv['INV-002'];
        self::assertSame('donated', $donated['disposal_type']);
        self::assertSame('none', $donated['deductibility'], 'Dar — daňová ZC neuznatelná (§25/1/t ZDP).');
        self::assertSame(self::cents(50000.00), self::cents((float) $donated['tax_residual_value']), 'Fallback ZC = vstupní cena bez odpisů.');

        // (c) informativní sekce existuje; report nic neúčtuje
        self::assertArrayHasKey('estimates_388_balance', $body['info']);
        self::assertArrayHasKey('fx_revaluation_loss_563', $body['info']);
        $entries = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}"
        )->fetchColumn();
        self::assertSame(0, $entries, 'Report je čistě evidenční — žádný zápis do deníku (R19).');
    }

    public function testI25DamagedDisposalIsLimited(): void
    {
        $this->asset('INV-003', 'Vozidlo (škoda)', 200000.00, 'damaged', self::YEAR . '-10-15');

        $res = $this->report(self::YEAR);
        self::assertSame(200, $res['status']);
        $disposal = $res['body']['disposals'][0];
        self::assertSame('limited', $disposal['deductibility'], 'Škoda — uznatelnost jen do výše náhrad (§24/2/l ZDP).');
        self::assertStringContainsString('náhrad', (string) $disposal['note']);
    }

    public function testI25ValidationAndMissingPeriod(): void
    {
        $res = $this->report(0);
        self::assertSame(422, $res['status'], 'fiscal_year je povinný.');

        $res = $this->report(self::YEAR + 5);
        self::assertSame(404, $res['status'], 'Neexistující období → 404.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function asset(string $invNo, string $name, float $inputPrice, string $disposalType, string $disposalDate): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO assets
                (supplier_id, inventory_number, name, kind, asset_account_code, accumulated_account_code,
                 input_price, acquisition_date, put_into_use_date, disposal_date, disposal_type,
                 status, tax_method, tax_group, created_by)
             VALUES (?, ?, ?, "tangible", "022", "082", ?, ?, ?, ?, ?, "disposed", "straight", 2, ?)'
        );
        $acq = (self::YEAR - 2) . '-05-01';
        $stmt->execute([$this->supplierId, $invNo, $name, $inputPrice, $acq, $acq, $disposalDate, $disposalType, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function depreciation(int $assetId, string $kind, float $amount, float $residualEnd): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO depreciation_entries
                (supplier_id, asset_id, kind, fiscal_year, amount, full_amount, residual_value_end, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, "confirmed")'
        )->execute([$this->supplierId, $assetId, $kind, self::YEAR, $amount, $amount, $residualEnd]);
    }

    /**
     * @return array{status:int, body:array<string,mixed>}
     */
    private function report(int $fiscalYear): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/reports/tax-base-adjustments')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withQueryParams(['fiscal_year' => (string) $fiscalYear]);
        $resp = $this->action->get($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}
