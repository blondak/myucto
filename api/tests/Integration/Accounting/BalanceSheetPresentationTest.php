<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\StatementOverrideService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Prezentace rozvahy: řádky aktiv se záporným netto a zařazení opravných položek.
 *
 * Scénář: obchodní pohledávka 100 000 Kč (311) a opravná položka 150 000 Kč na 391,
 * která ve skutečnosti patří k jiné pohledávce. Globální mapa dá celou 391 do korekce
 * C.II.2.1., takže netto řádku vyjde záporné.
 */
#[Group('integration')]
final class BalanceSheetPresentationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PREV_YEAR = 2091;
    private const YEAR = 2092;
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private FinancialStatementService $statements;
    private StatementOverrideService $overrides;
    private StatementDefinitionRepository $definitions;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;

    private int $supplierId = 0;
    private int $periodId = 0;
    private int $prevPeriodId = 0;
    private int $userId = 0;
    private int $versionId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db          = $c->get(Connection::class);
            $this->statements  = $c->get(FinancialStatementService::class);
            $this->overrides   = $c->get(StatementOverrideService::class);
            $this->definitions = $c->get(StatementDefinitionRepository::class);
            $this->posting     = $c->get(PostingService::class);
            $this->periods     = $c->get(AccountingPeriodRepository::class);
            $this->seeder      = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier / user.');
        }
        $version = $this->definitions->findVersion('balance_sheet', self::ENDS_ON);
        if ($version === null) {
            $this->markTestSkipped('Chybí verze rozvahy.');
        }
        $this->versionId = (int) $version['id'];

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->seeder->seedForSupplier($this->supplierId);
        $this->prevPeriodId = $this->periods->create($this->supplierId, self::PREV_YEAR, self::PREV_YEAR . '-01-01', self::PREV_YEAR . '-12-31');
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::ENDS_ON);
        $pdo->prepare(
            "INSERT INTO accounting_supplier_settings (supplier_id, statement_scope_override) VALUES (?, 'full')
             ON DUPLICATE KEY UPDATE statement_scope_override = 'full'"
        )->execute([$this->supplierId]);

        $this->analytic('351', '351.100', 'Dlouhodobá pohledávka za ovládanou osobou', 'asset', 'debit');
        $this->analytic('391', '391.100', 'OP k dlouhodobé pohledávce', 'asset', 'credit');
        $this->analytic('391', '391.200', 'OP k obchodním pohledávkám', 'asset', 'credit');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testNegativeNetAssetRowIsReportedOnceWhereItArises(): void
    {
        $this->post(self::YEAR, '311', '602', 100_000.00);
        $this->post(self::YEAR, '558', '391.100', 150_000.00);

        $sheet = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
        $assets = array_column($sheet['assets'], null, 'row_code');
        self::assertEqualsWithDelta(-50_000.0, $assets['C.II.2.1.']['net'], 0.01, 'Fixture: celá 391 v korekci obchodních pohledávek.');

        $negative = $sheet['checks']['negative_net_rows'];
        self::assertCount(1, $negative, 'Mezisoučty nad záporným řádkem se nehlásí znovu.');
        self::assertSame('C.II.2.1.', $negative[0]['row_code']);
        self::assertSame('current', $negative[0]['column']);
        self::assertEqualsWithDelta(100_000.0, $negative[0]['gross'], 0.01);
        self::assertEqualsWithDelta(150_000.0, $negative[0]['correction'], 0.01);
        self::assertEqualsWithDelta(-50_000.0, $negative[0]['net'], 0.01);
        self::assertTrue($sheet['checks']['balanced'], 'Rozvaha se kvůli záporné položce nesmí rozvážit.');
    }

    public function testBalanceSheetWithoutNegativeRowsReportsNothing(): void
    {
        $this->post(self::YEAR, '311', '602', 100_000.00);
        $this->post(self::YEAR, '558', '391.200', 40_000.00);

        $sheet = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::ENDS_ON, 'full');
        self::assertSame([], $sheet['checks']['negative_net_rows']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function analytic(string $parentCode, string $code, string $name, string $type, string $side): void
    {
        $pdo = $this->db->pdo();
        $parent = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $parent->execute([$this->supplierId, $parentCode]);
        $parentId = (int) $parent->fetchColumn();
        self::assertGreaterThan(0, $parentId, 'Osnova musí mít účet ' . $parentCode . '.');
        $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             VALUES (?, ?, ?, ?, ?, 0, ?, 1)'
        )->execute([$this->supplierId, $code, $name, $type, $side, $parentId]);
    }

    private function post(int $year, string $debit, string $credit, float $amount): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], [
            'entry_date'  => $year . '-06-30',
            'description' => 'Test prezentace rozvahy ' . $debit . '/' . $credit,
            'posted'      => true,
            'user_id'     => $this->userId,
        ]);
    }
}
