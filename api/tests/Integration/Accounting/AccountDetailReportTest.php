<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\AccountDetailService;
use MyInvoice\Service\Accounting\Reports\AccountStatementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační test karty účtu (drill-through osnova → účet → analytiky):
 * syntetika sčítá vlastní pohyby i pohyby analytik, každá analytika nese svoje
 * zůstatky, `parent` míří zpátky na syntetiku a čísla sedí na haléř s opisem
 * účtu (SSOT — karta účtu nesmí mít vlastní výklad PS/obratů).
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class AccountDetailReportTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private AccountDetailService $detail;
    private AccountStatementService $statement;
    private ChartOfAccountsRepository $accounts;
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
            $this->db        = $container->get(Connection::class);
            $this->posting   = $container->get(PostingService::class);
            $this->detail    = $container->get(AccountDetailService::class);
            $this->statement = $container->get(AccountStatementService::class);
            $this->accounts  = $container->get(ChartOfAccountsRepository::class);
            $this->periods   = $container->get(AccountingPeriodRepository::class);
            $seeder          = $container->get(ChartOfAccountsSeeder::class);
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

        // Izolovaný supplier — kumulativní PS rozvahových účtů jde přes celou
        // historii deníku, sdílený dev supplier by asserty rozbil.
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        )->execute(['Izolovaný test s.r.o.', 'izolace@example.com', $this->supplierId]);
        $this->supplierId = (int) $pdo->lastInsertId();

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

    public function testSyntheticRollsUpAnalyticsAndMatchesAccountStatement(): void
    {
        $syntheticId = $this->accountId('221');
        $bankA = $this->analytic($syntheticId, '221.100', 'Běžný účet A');
        $bankB = $this->analytic($syntheticId, '221.200', 'Běžný účet B');

        $this->manual([self::l('221.100', 'debit', 1000.00), self::l('602', 'credit', 1000.00)], self::YEAR . '-02-10');
        $this->manual([self::l('518', 'debit', 250.00),  self::l('221.100', 'credit', 250.00)], self::YEAR . '-03-05');
        $this->manual([self::l('221.200', 'debit', 400.00), self::l('602', 'credit', 400.00)], self::YEAR . '-04-01');
        // Pohyb přímo na syntetice (legacy zápisy takové mají) — do součtu patří taky.
        $this->manual([self::l('221', 'debit', 70.00), self::l('602', 'credit', 70.00)], self::YEAR . '-05-01');

        $data = $this->detail->build($this->supplierId, $syntheticId, self::YEAR . '-01-01', self::YEAR . '-12-31');

        self::assertSame('221', $data['account']['code']);
        self::assertTrue($data['account']['is_synthetic']);
        self::assertNull($data['parent'], 'Syntetika nemá rodiče.');
        self::assertSame(self::YEAR, $data['period']['fiscal_year']);

        self::assertSame(self::cents(1470.00), self::cents($data['totals']['turnover_md']), 'Syntetika sčítá vlastní pohyb i analytiky.');
        self::assertSame(self::cents(250.00), self::cents($data['totals']['turnover_d']));
        self::assertSame(self::cents(1220.00), self::cents($data['totals']['closing_balance']));
        self::assertSame(4, $data['totals']['line_count']);

        $children = [];
        foreach ($data['children'] as $c) {
            $children[$c['code']] = $c;
        }
        self::assertArrayHasKey('221.100', $children);
        self::assertArrayHasKey('221.200', $children);
        self::assertSame($bankA, $children['221.100']['id']);
        self::assertSame($bankB, $children['221.200']['id']);
        self::assertSame(self::cents(1000.00), self::cents($children['221.100']['turnover_md']));
        self::assertSame(self::cents(250.00), self::cents($children['221.100']['turnover_d']));
        self::assertSame(self::cents(750.00), self::cents($children['221.100']['closing_balance']));
        self::assertSame(2, $children['221.100']['line_count']);
        self::assertSame(self::cents(400.00), self::cents($children['221.200']['closing_balance']));
        self::assertSame(1, $children['221.200']['line_count']);

        // SSOT: karta účtu nesmí mít vlastní výklad PS/obratů — musí sedět s opisem účtu.
        $stmt = $this->statement->build($this->supplierId, $syntheticId, self::YEAR . '-01-01', self::YEAR . '-12-31', 1, 1);
        self::assertSame(self::cents($stmt['opening_balance']), self::cents($data['totals']['opening_balance']));
        self::assertSame(self::cents($stmt['turnover_md']), self::cents($data['totals']['turnover_md']));
        self::assertSame(self::cents($stmt['turnover_d']), self::cents($data['totals']['turnover_d']));
        self::assertSame(self::cents($stmt['closing_balance']), self::cents($data['totals']['closing_balance']));
        self::assertSame($stmt['total'], $data['totals']['line_count']);
    }

    public function testAnalyticPointsBackToItsSyntheticAndHasNoChildren(): void
    {
        $syntheticId = $this->accountId('221');
        $bankA = $this->analytic($syntheticId, '221.100', 'Běžný účet A');
        $this->manual([self::l('221.100', 'debit', 1000.00), self::l('602', 'credit', 1000.00)], self::YEAR . '-02-10');

        $data = $this->detail->build($this->supplierId, $bankA, self::YEAR . '-01-01', self::YEAR . '-12-31');

        self::assertSame('221.100', $data['account']['code']);
        self::assertFalse($data['account']['is_synthetic']);
        self::assertNotNull($data['parent']);
        self::assertSame($syntheticId, $data['parent']['id']);
        self::assertSame('221', $data['parent']['code']);
        self::assertSame([], $data['children']);
        self::assertSame(self::cents(1000.00), self::cents($data['totals']['closing_balance']));
    }

    public function testOpeningWindowFromMidPeriod(): void
    {
        $syntheticId = $this->accountId('221');
        $this->analytic($syntheticId, '221.100', 'Běžný účet A');
        $this->manual([self::l('221.100', 'debit', 1000.00), self::l('602', 'credit', 1000.00)], self::YEAR . '-01-10');

        $data = $this->detail->build($this->supplierId, $syntheticId, self::YEAR . '-02-01', self::YEAR . '-12-31');

        self::assertSame(self::cents(1000.00), self::cents($data['totals']['opening_balance']), 'PS od února = lednový pohyb.');
        self::assertSame(0, self::cents($data['totals']['turnover_md']));
        self::assertSame(self::cents(1000.00), self::cents($data['totals']['closing_balance']));
        self::assertSame(0, $data['totals']['line_count']);
    }

    public function testUnknownAccountThrowsNotFound(): void
    {
        $this->expectException(ReportException::class);
        $this->detail->build($this->supplierId, 999999999, self::YEAR . '-01-01', self::YEAR . '-12-31');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function analytic(int $parentId, string $code, string $name): int
    {
        $parent = $this->accounts->findById($this->supplierId, $parentId);
        self::assertNotNull($parent);
        return $this->accounts->insert($this->supplierId, [
            'account_code' => $code,
            'name'         => $name,
            'account_type' => (string) $parent['account_type'],
            'normal_side'  => $parent['normal_side'] !== null ? (string) $parent['normal_side'] : null,
            'is_synthetic' => false,
            'parent_id'    => $parentId,
            'is_active'    => true,
        ]);
    }

    private function accountId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$this->supplierId, $code]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, "Účet {$code} nenalezen v osnově testovacího suppliera.");
        return $id;
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     */
    private function manual(array $lines, string $date): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            $lines,
            ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );
    }

    /**
     * @return array{account_code:string, side:string, amount:float}
     */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
