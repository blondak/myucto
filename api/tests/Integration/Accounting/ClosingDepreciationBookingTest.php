<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Zaúčtování odpisů v uzávěrkovém průvodci ve stavu 'closing' (audit 2026-07 B10).
 *
 *   - ClosingService::bookDepreciation v období 'closing' PROJDE (R7 flag nastaví jen
 *     ClosingService pro skutečně uzavírané období).
 *   - DepreciationPostingService::bookYear volaný PŘÍMO (mimo uzávěrku) v 'closing'
 *     stále vrací period_not_open (regrese R7 — flag se nesmí obejít).
 */
#[Group('integration')]
final class ClosingDepreciationBookingTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private ClosingService $closing;
    private DepreciationPostingService $depPosting;
    private AssetService $service;
    private DepreciationEntryRepository $entries;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
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
            $this->db         = $container->get(Connection::class);
            $this->closing    = $container->get(ClosingService::class);
            $this->depPosting = $container->get(DepreciationPostingService::class);
            $this->service    = $container->get(AssetService::class);
            $this->entries    = $container->get(DepreciationEntryRepository::class);
            $this->journal    = $container->get(JournalEntryRepository::class);
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

    public function testBookDepreciationThroughClosingServiceSucceedsInClosing(): void
    {
        $assetId = $this->createAssetInUse('M-B10-A', self::YEAR . '-03-10', 90000.00, 1, 36);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closing');

        $result = $this->closing->bookDepreciation($this->supplierId, $this->periodId, ['posted_by' => $this->userId]);

        self::assertSame([], $result['errors'], 'Přes ClosingService se odpis v closing zaúčtuje bez chyb.');
        self::assertGreaterThanOrEqual(1, $result['booked'], 'Alespoň jeden majetek zaúčtován.');

        $acc = $this->entries->findYear($assetId, 'accounting', self::YEAR);
        self::assertNotNull($acc, 'Účetní řádek roku vznikl.');
        self::assertNotNull(
            $this->journal->findBySource($this->supplierId, 'depreciation', (int) $acc['id']),
            'V deníku existuje zápis odpisu (v období closing).',
        );
    }

    public function testDirectBookYearStillBlockedInClosing(): void
    {
        $assetId = $this->createAssetInUse('M-B10-B', self::YEAR . '-03-10', 90000.00, 1, 36);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closing');

        // Přímé volání (jako DepreciationAction, mimo uzávěrku) flag nenastaví → R7 drží.
        $result = $this->depPosting->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);

        $codes = array_column($result['errors'], 'code', 'asset_id');
        self::assertArrayHasKey($assetId, $codes, 'Majetek skončil v errors[].');
        self::assertSame('period_not_open', $codes[$assetId], 'Přímé účtování do closing je period_not_open.');
        // R7 boundary = DENÍK: žádný účetní zápis odpisu do deníku nevznikl (evidence řádek
        // depreciation_entries se v samostatném běhu rollbackne — v nested test-tx zůstane,
        // to je artefakt harness, ne produkce; podstatná je nulová stopa v deníku).
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM journal_entries je
               JOIN depreciation_entries de ON de.id = je.source_id
              WHERE je.supplier_id = ? AND je.source_type = 'depreciation'
                AND de.asset_id = ? AND de.kind = 'accounting' AND de.fiscal_year = ?
                AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL"
        );
        $stmt->execute([$this->supplierId, $assetId, self::YEAR]);
        $depJournalCount = (int) $stmt->fetchColumn();
        self::assertSame(0, $depJournalCount, 'Do deníku se odpis nezaúčtoval (R7 drží).');
    }

    /**
     * Testovací firma je sdílená s ostatními daty v DB, takže „bez majetku" se musí
     * v rámci transakce vyrobit: existující majetek se vyřadí před začátkem období.
     * Zároveň tím projde větev disposal_date — vyřazený majetek se v dalších letech
     * neodepisuje.
     */
    private function disposeExistingAssetsBeforePeriod(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE assets SET disposal_date = ?, status = ? WHERE supplier_id = ? AND disposal_date IS NULL'
        )->execute([(self::YEAR - 1) . '-12-31', 'disposed', $this->supplierId]);
    }

    /**
     * Firma bez odpisovaného majetku v období nesmí být nucena krok „Odpisy" ručně
     * přeskočit. Skip se v auditní stopě čte jako „rozhodli jsme se odpisy nezaúčtovat",
     * kdežto tady prostě nebylo co odepisovat — a nerelevantní krok navíc blokoval
     * uzavření knih (reálně: rok 2024, majetek pořízený až v 2025).
     */
    public function testDepreciationStepIsNotRequiredWithoutAssetsInPeriod(): void
    {
        $this->disposeExistingAssetsBeforePeriod();

        $state = $this->closing->state($this->supplierId, $this->periodId);

        self::assertFalse(
            $state['depreciation_step_required'],
            'Bez majetku v období se odpisový krok nevyžaduje.'
        );
    }

    /**
     * Rozhoduje stav majetku V OBDOBÍ, ne dnes: majetek zařazený až po konci období
     * nesmí odpisový krok vyžadovat (jinak by rok před pořízením šel uzavřít jen přes skip).
     */
    public function testDepreciationStepIgnoresAssetPutIntoUseAfterPeriodEnd(): void
    {
        $this->disposeExistingAssetsBeforePeriod();
        $this->createAssetInUse('M-B10-LATE', (self::YEAR + 1) . '-03-10', 90000.00, 1, 36);

        $state = $this->closing->state($this->supplierId, $this->periodId);

        self::assertFalse(
            $state['depreciation_step_required'],
            'Majetek zařazený až po konci období tenhle rok neodepisuje.'
        );
    }

    /** A naopak — jakmile v období odpisovaný majetek je, krok se vyžaduje. */
    public function testDepreciationStepIsRequiredWithAssetInPeriod(): void
    {
        $this->createAssetInUse('M-B10-REQ', self::YEAR . '-03-10', 90000.00, 1, 36);

        $state = $this->closing->state($this->supplierId, $this->periodId);

        self::assertTrue(
            $state['depreciation_step_required'],
            'S majetkem v období se odpisový krok vyžaduje.'
        );
    }

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
