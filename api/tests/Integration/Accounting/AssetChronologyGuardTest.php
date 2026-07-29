<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Chronologický zámek potvrzených daňových let (audit 2026-07 B9).
 *
 *   - pauseYear odmítne přerušit dřívější rok, když je už potvrzen POZDĚJŠÍ daňový rok
 *     ('later_year_confirmed') — symetrie s unpauseYear.
 *   - bookYear odmítne zaúčtovat rok N, pokud rok N-1 (in-system, s nenulovou ZC) není
 *     potvrzen ani přerušen ('prior_year_not_confirmed').
 *
 * Vzor AssetLifecycleTest: jedna transakce s rollbackem v tearDown, soft-skip bez cfg.php.
 */
#[Group('integration')]
final class AssetChronologyGuardTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private AssetService $service;
    private DepreciationPostingService $depPosting;
    private DepreciationEntryRepository $entries;
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
            $this->db         = $container->get(Connection::class);
            $this->service    = $container->get(AssetService::class);
            $this->depPosting = $container->get(DepreciationPostingService::class);
            $this->entries    = $container->get(DepreciationEntryRepository::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
            $seeder           = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
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

    // ── B9: pauseYear — přerušení dřívějšího roku s potvrzeným pozdějším rokem ──

    public function testPauseEarlierYearRefusedWhenLaterYearConfirmed(): void
    {
        $assetId = $this->createAssetInUse('M-B9-P1', self::YEAR . '-03-10', 90000.00, 1, 36);

        // Přímo vlož potvrzený daňový řádek POZDĚJŠÍHO roku (YEAR+1), rok YEAR necháme prázdný.
        $this->entries->upsert([
            'supplier_id' => $this->supplierId,
            'asset_id' => $assetId,
            'kind' => 'tax',
            'fiscal_year' => self::YEAR + 1,
            'amount' => 30000.00,
            'full_amount' => 30000.00,
            'residual_value_end' => 30000.00,
            'is_paused' => false,
            'is_half' => false,
            'months_count' => null,
            'detail' => null,
            'status' => 'confirmed',
        ]);

        try {
            $this->service->pauseYear($this->supplierId, $assetId, self::YEAR);
            self::fail('Přerušení roku ' . self::YEAR . ' s potvrzeným pozdějším rokem musí selhat.');
        } catch (AssetException $e) {
            self::assertSame('later_year_confirmed', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }

        // Rok YEAR nesmí získat pauza řádek.
        self::assertNull($this->entries->findYear($assetId, 'tax', self::YEAR), 'Pauza se nezaložila.');
    }

    // ── B9: bookYear — účtování N+1 bez potvrzeného/pauznutého N s nenulovou ZC ──

    public function testBookYearRefusedWhenPriorYearMissing(): void
    {
        $assetId = $this->createAssetInUse('M-B9-B1', self::YEAR . '-03-10', 90000.00, 1, 36);

        // Rok YEAR (první in-system rok) NEzaúčtujeme; rovnou skočíme na YEAR+1.
        $result = $this->depPosting->bookYear($this->supplierId, self::YEAR + 1, ['posted_by' => $this->userId]);

        $codes = array_column($result['errors'], 'code', 'asset_id');
        self::assertArrayHasKey($assetId, $codes, 'Majetek s chybějícím předchozím rokem je v errors[].');
        self::assertSame('prior_year_not_confirmed', $codes[$assetId]);

        // Nic se nezaúčtovalo (ani daňový ani účetní řádek YEAR+1).
        self::assertNull($this->entries->findYear($assetId, 'tax', self::YEAR + 1), 'Daňový řádek N+1 nevznikl.');
        self::assertNull($this->entries->findYear($assetId, 'accounting', self::YEAR + 1), 'Účetní řádek N+1 nevznikl.');
    }

    public function testBookYearPassesWhenPriorYearBookedSequentially(): void
    {
        $assetId = $this->createAssetInUse('M-B9-B2', self::YEAR . '-03-10', 90000.00, 1, 36);

        // Sekvenční: nejdřív YEAR, pak YEAR+1 — guard nesmí bránit legitimnímu postupu.
        $first = $this->depPosting->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        self::assertSame([], $first['errors'], 'První rok bez chyb.');
        self::assertNotNull($this->entries->findYear($assetId, 'tax', self::YEAR));

        $second = $this->depPosting->bookYear($this->supplierId, self::YEAR + 1, ['posted_by' => $this->userId]);
        self::assertSame([], $second['errors'], 'Druhý rok po sekvenčním prvním projde.');
        self::assertNotNull($this->entries->findYear($assetId, 'tax', self::YEAR + 1), 'Daňový řádek N+1 vznikl.');
    }

    public function testDisposalRefusedWhenLaterYearIsConfirmed(): void
    {
        $assetId = $this->createAssetInUse('M-EP4-LATER', self::YEAR . '-03-10', 90000.00, 1, 36);
        $this->entries->upsert([
            'supplier_id' => $this->supplierId,
            'asset_id' => $assetId,
            'kind' => 'tax',
            'fiscal_year' => self::YEAR + 1,
            'amount' => 20000.00,
            'full_amount' => 20000.00,
            'residual_value_end' => 50000.00,
            'status' => 'confirmed',
        ]);

        try {
            $this->service->dispose($this->supplierId, $assetId,
                ['date' => self::YEAR . '-09-15', 'type' => 'liquidated'],
                ['user_id' => $this->userId, 'posted_by' => $this->userId]);
            self::fail('Vyřazení před potvrzeným pozdějším rokem musí selhat.');
        } catch (AssetException $e) {
            self::assertSame('later_year_confirmed', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }

        self::assertSame('in_use', $this->service->get($this->supplierId, $assetId)['status']);
        self::assertNull($this->entries->findYear($assetId, 'tax', self::YEAR));
    }

    public function testDisposalRefusedWhenLaterAccountingYearIsPosted(): void
    {
        $assetId = $this->createAssetInUse('M-EP4-LATER-ACC', self::YEAR . '-03-10', 90000.00, 1, 36);
        $this->entries->upsert([
            'supplier_id' => $this->supplierId,
            'asset_id' => $assetId,
            'kind' => 'accounting',
            'fiscal_year' => self::YEAR + 1,
            'amount' => 30000.00,
            'full_amount' => 30000.00,
            'residual_value_end' => 30000.00,
            'status' => 'posted',
        ]);

        try {
            $this->service->dispose($this->supplierId, $assetId,
                ['date' => self::YEAR . '-09-15', 'type' => 'liquidated'],
                ['user_id' => $this->userId, 'posted_by' => $this->userId]);
            self::fail('Vyřazení před zaúčtovaným účetním odpisem pozdějšího roku musí selhat.');
        } catch (AssetException $e) {
            self::assertSame('later_year_confirmed', $e->errorCode);
        }
    }

    public function testDisposalRefusedWhenPriorTaxYearIsMissing(): void
    {
        $assetId = $this->createAssetInUse('M-EP4-PRIOR', self::YEAR . '-03-10', 90000.00, 1, 36);

        try {
            $this->service->dispose($this->supplierId, $assetId,
                ['date' => (self::YEAR + 1) . '-09-15', 'type' => 'liquidated'],
                ['user_id' => $this->userId, 'posted_by' => $this->userId]);
            self::fail('Vyřazení s chybějícím předchozím daňovým rokem musí selhat.');
        } catch (AssetException $e) {
            self::assertSame('prior_year_not_confirmed', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }

        self::assertSame('in_use', $this->service->get($this->supplierId, $assetId)['status']);
        self::assertNull($this->entries->findYear($assetId, 'accounting', self::YEAR + 1));
        self::assertNull($this->entries->findYear($assetId, 'tax', self::YEAR + 1));
    }

    public function testDisposalRefusedWhenMissingPriorYearWasLastDepreciationYear(): void
    {
        $this->periods->create($this->supplierId, self::YEAR + 2, (self::YEAR + 2) . '-01-01', (self::YEAR + 2) . '-12-31');
        $this->periods->create($this->supplierId, self::YEAR + 3, (self::YEAR + 3) . '-01-01', (self::YEAR + 3) . '-12-31');
        $assetId = $this->createAssetInUse('M-EP4-LAST', self::YEAR . '-03-10', 90000.00, 1, 36);
        $this->entries->upsert([
            'supplier_id' => $this->supplierId, 'asset_id' => $assetId, 'kind' => 'tax',
            'fiscal_year' => self::YEAR, 'amount' => 18000.00, 'full_amount' => 18000.00,
            'residual_value_end' => 72000.00, 'status' => 'confirmed',
        ]);
        $this->entries->upsert([
            'supplier_id' => $this->supplierId, 'asset_id' => $assetId, 'kind' => 'tax',
            'fiscal_year' => self::YEAR + 1, 'amount' => 36000.00, 'full_amount' => 36000.00,
            'residual_value_end' => 36000.00, 'status' => 'confirmed',
        ]);

        try {
            $this->service->dispose($this->supplierId, $assetId,
                ['date' => (self::YEAR + 3) . '-09-15', 'type' => 'liquidated'],
                ['user_id' => $this->userId, 'posted_by' => $this->userId]);
            self::fail('Poslední chybějící rok odpisování se nesmí vyřazením přeskočit.');
        } catch (AssetException $e) {
            self::assertSame('prior_year_not_confirmed', $e->errorCode);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function createAssetInUse(
        string $inventoryNumber,
        string $useDate,
        float $price = 500000.00,
        int $taxGroup = 2,
        int $lifeMonths = 60,
    ): int {
        $created = $this->service->create($this->supplierId, [
            'inventory_number' => $inventoryNumber,
            'name' => 'Majetek ' . $inventoryNumber,
            'input_price' => $price,
            'acquisition_date' => $useDate,
            'tax_method' => 'straight',
            'tax_group' => $taxGroup,
            'acc_useful_life_months' => $lifeMonths,
        ], ['user_id' => $this->userId]);
        $assetId = (int) $created['asset']['id'];
        $this->service->putIntoUse($this->supplierId, $assetId, $useDate, true,
            ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        return $assetId;
    }
}
