<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\DimensionProfitService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\GeneralLedgerService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výkazy po dimenzi: výkazy filtrované na hodnotu promítají rozpad řádku poměrem
 * (stejné haléře jako výsledovka po dimenzi), součet po hodnotách + „bez hodnoty"
 * = výsledek firmy. Vše v jedné transakci, tearDown rollbackne.
 */
#[Group('integration')]
final class DimensionReportsTest extends TestCase
{
    private const YEAR = 2094;
    private const DATE = self::YEAR . '-05-10';
    private const FROM = self::YEAR . '-01-01';
    private const TO = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private DimensionService $dimensions;
    private DimensionProfitService $profit;
    private FinancialStatementService $statements;
    private TrialBalanceService $trialBalance;
    private GeneralLedgerService $generalLedger;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $centerType = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->dimensions = $container->get(DimensionService::class);
            $this->profit = $container->get(DimensionProfitService::class);
            $this->statements = $container->get(FinancialStatementService::class);
            $this->trialBalance = $container->get(TrialBalanceService::class);
            $this->generalLedger = $container->get(GeneralLedgerService::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->periodId = $this->prepareCompany($this->supplierId);
        $pdo->prepare('UPDATE supplier SET supplier_group_id = NULL WHERE id = ?')->execute([$this->supplierId]);
        $this->dimensions->setEnabled($this->supplierId, true);
        $this->centerType = $this->dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
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

    public function testFilteredStatementsSplitLineProportionallyAndSumToCompanyResult(): void
    {
        $a = $this->value($this->centerType, 'R-A');
        $b = $this->value($this->centerType, 'R-B');
        $split = [$this->centerType => [$a => 1 / 3, $b => 2 / 3]];
        $this->post([
            ['518', 'debit', 100.01, ['dimension_splits' => $split]],
            ['321', 'credit', 100.01, ['dimension_splits' => $split]],
        ]);
        $this->post([
            ['311', 'debit', 500.00, ['dimensions' => [$this->centerType => $a]]],
            ['602', 'credit', 500.00, ['dimensions' => [$this->centerType => $a]]],
        ]);
        $this->post([['518', 'debit', 200.00], ['321', 'credit', 200.00]]);

        $filterA = $this->dimensions->filter($this->supplierId, $a);
        $filterB = $this->dimensions->filter($this->supplierId, $b);

        $profitA = $this->incomeProfit($filterA);
        $profitB = $this->incomeProfit($filterB);
        self::assertEqualsWithDelta(466.66, $profitA, 0.001, 'A = výnos 500 − třetina nákladu 33,34.');
        self::assertEqualsWithDelta(-66.67, $profitB, 0.001, 'B = dvě třetiny nákladu.');

        $report = $this->profit->build($this->supplierId, $this->centerType, self::FROM, self::TO, [$this->supplierId]);
        $company = $this->incomeProfit(null);
        self::assertEqualsWithDelta(199.99, $company, 0.001);
        self::assertSame(
            (int) round($company * 100),
            (int) round(($profitA + $profitB + $report['unassigned']['result']) * 100),
            'Výkazy po hodnotách + bez hodnoty = výsledek firmy na haléř.',
        );
        self::assertSame((int) round($company * 100), (int) round($report['totals']['result'] * 100));
        $rows = array_column($report['rows'], null, 'value_id');
        self::assertSame((int) round($profitA * 100), (int) round($rows[$a]['total']['result'] * 100), 'Výsledovka po dimenzi = výkaz filtrovaný na hodnotu.');

        $tb = $this->trialBalance->build($this->supplierId, $this->periodId, null, null, false, false, $filterA);
        $tbRows = array_column($tb['rows'], null, 'account_code');
        self::assertEqualsWithDelta(33.34, $tbRows['518']['turnover_md'], 0.001, 'Předvaha bere z rozpadu jen díl hodnoty.');
        self::assertEqualsWithDelta(33.34, $tbRows['321']['turnover_d'], 0.001);
        self::assertEqualsWithDelta(533.34, $tb['checks']['journal_turnover_md'], 0.001, 'Kontrolní obrat deníku počítá stejné díly.');

        $gl = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, ['dimension' => $filterB]);
        $glRows = array_column($gl['accounts'], null, 'account_code');
        self::assertEqualsWithDelta(66.67, $glRows['518']['turnover_md'], 0.001, 'Hlavní kniha bere z rozpadu jen díl hodnoty.');

    }

    public function testSplitRemainderGoesToLargestShareIdenticallyEverywhere(): void
    {
        $a = $this->value($this->centerType, 'R-X');
        $b = $this->value($this->centerType, 'R-Y');
        $c = $this->value($this->centerType, 'R-Z');
        $third = [$this->centerType => [$a => 1 / 3, $b => 1 / 3, $c => 1 / 3]];
        $this->post([['518', 'debit', 0.05, ['dimension_splits' => $third]], ['321', 'credit', 0.05]]);

        $rows = array_column($this->profit->build($this->supplierId, $this->centerType, self::FROM, self::TO, [$this->supplierId])['rows'], null, 'value_id');
        $cents = [];
        foreach ([$a, $b, $c] as $v) {
            $cents[$v] = (int) round($rows[$v]['own']['cost'] * 100);
            $filtered = (int) round(-$this->incomeProfit($this->dimensions->filter($this->supplierId, $v)) * 100);
            self::assertSame($cents[$v], $filtered, 'Výsledovka po dimenzi a filtr výkazu dělí haléře stejně.');
        }
        self::assertSame([$a => 1, $b => 2, $c => 2], $cents, 'Zbytek po zaokrouhlení nese hodnota s nižším id při shodě podílů.');
    }

    public function testJournalFilterFindsEntryWithSplitLine(): void
    {
        $a = $this->value($this->centerType, 'R-J1');
        $b = $this->value($this->centerType, 'R-J2');
        $entry = $this->post([
            ['518', 'debit', 10.00, ['dimension_splits' => [$this->centerType => [$a => 0.5, $b => 0.5]]]],
            ['321', 'credit', 10.00],
        ]);
        $found = $this->journal->paginate($this->supplierId, ['dimension' => $this->dimensions->filter($this->supplierId, $b)], 50, 0);
        self::assertContains($entry, array_map(static fn (array $r): int => (int) $r['id'], $found['items']));
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    private function prepareCompany(int $supplierId): int
    {
        $this->seeder->seedForSupplier($supplierId);
        $periodId = $this->periods->create($supplierId, self::YEAR, self::FROM, self::TO);
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$supplierId]);
        return (int) $periodId;
    }


    private function value(int $typeId, string $code, ?int $parentId = null): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $typeId, ['code' => $code, 'name' => 'Hodnota ' . $code]
            + ($parentId !== null ? ['parent_id' => $parentId] : []))['id'];
    }

    /** @param list<array{0:string,1:string,2:float,3?:array<string,mixed>}> $lines */
    private function post(array $lines, ?int $supplierId = null): int
    {
        return $this->posting->postDocument($supplierId ?? $this->supplierId, 'manual', null, array_map(
            static fn (array $l): array => ['account_code' => $l[0], 'side' => $l[1], 'amount' => $l[2]] + ($l[3] ?? []),
            $lines,
        ), ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
    }

    private function incomeProfit(?\MyInvoice\Service\Accounting\Dimension\DimensionFilter $filter): float
    {
        return (float) $this->statements->incomeStatement($this->supplierId, $this->periodId, self::TO, 'full', $filter)['checks']['profit_current'];
    }
}
