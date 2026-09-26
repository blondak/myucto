<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\JournalHistoryService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[Group('integration')]
final class JournalRedStornoTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private Connection $db;
    private JournalEntryRepository $journal;
    private LedgerReportRepository $ledger;
    private PostingService $posting;
    private JournalHistoryService $history;
    private ContainerInterface $container;
    private int $supplierId;
    private int $periodId;
    private int $userId;
    /** @var array<string,int> */
    private array $accounts;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->container = $container;
        $this->db = $container->get(Connection::class);
        $this->journal = $container->get(JournalEntryRepository::class);
        $this->ledger = $container->get(LedgerReportRepository::class);
        $this->posting = $container->get(PostingService::class);
        $this->history = $container->get(JournalHistoryService::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->periodId = $container->get(AccountingPeriodRepository::class)->create(
            $this->supplierId,
            self::YEAR,
            self::YEAR . '-01-01',
            self::YEAR . '-12-31',
        );
        $this->accounts = [];
        foreach ($container->get(ChartOfAccountsRepository::class)->codeToIdMap($this->supplierId) as $code => $row) {
            $this->accounts[$code] = (int) $row['id'];
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testRedStornoSubtractsTurnoverAndSurvivesReversal(): void
    {
        $this->entry(100.00, false, 'Běžná kontace');
        $redId = $this->entry(10.00, true, 'Červené storno');

        $rows = $this->ledger->trialBalanceRows(
            $this->supplierId,
            self::YEAR . '-01-01',
            self::YEAR . '-12-31',
            self::YEAR . '-01-01',
            true,
        );
        $byCode = array_column($rows, null, 'account_code');
        self::assertSame(90.0, $byCode['518']['to_md']);
        self::assertSame(90.0, $byCode['321']['to_d']);

        $red = $this->journal->findForUpdate($redId, $this->supplierId);
        self::assertTrue($red['lines'][0]['is_red_storno']);
        self::assertSame(-10.0, JournalLineAmount::signed($red['lines'][0]));

        $reversalId = $this->posting->reverse($this->supplierId, $redId, [
            'entry_date' => self::YEAR . '-02-01',
            'posted_by' => $this->userId,
        ]);
        $reversal = $this->journal->findForUpdate($reversalId, $this->supplierId);
        self::assertFalse($reversal['lines'][0]['is_red_storno']);
        self::assertSame('debit', $reversal['lines'][0]['side']);
        self::assertSame(10.0, JournalLineAmount::signed($reversal['lines'][0]));
        self::assertFalse($reversal['lines'][1]['is_red_storno']);
        self::assertSame('credit', $reversal['lines'][1]['side']);

        $rows = $this->ledger->trialBalanceRows(
            $this->supplierId,
            self::YEAR . '-01-01',
            self::YEAR . '-12-31',
            self::YEAR . '-01-01',
            true,
        );
        $byCode = array_column($rows, null, 'account_code');
        self::assertSame(100.0, $byCode['518']['to_md']);
        self::assertSame(0.0, $byCode['518']['to_d']);
        self::assertSame(0.0, $byCode['321']['to_md']);
        self::assertSame(100.0, $byCode['321']['to_d']);
    }

    public function testDimensionFilteredLedgerKeepsRedStornoSign(): void
    {
        $this->entry(100.00, false, 'Syntetický pohyb');
        $this->entry(10.00, true, 'Syntetická oprava');
        $this->db->pdo()->prepare("UPDATE journal_entry_lines SET cost_center='SYN' WHERE supplier_id=?")
            ->execute([$this->supplierId]);
        $dimension = new \MyInvoice\Service\Accounting\Dimension\DimensionFilter(0, 0, [0], ['SYN']);
        $rows = $this->ledger->trialBalanceRows($this->supplierId, self::YEAR . '-01-01',
            self::YEAR . '-12-31', self::YEAR . '-01-01', true, ['dimension' => $dimension]);
        $byCode = array_column($rows, null, 'account_code');
        self::assertSame(90.0, $byCode['518']['to_md']);
        self::assertSame(90.0, $byCode['321']['to_d']);
    }

    public function testBalanceValidationUsesRedStornoSign(): void
    {
        $this->expectException(UnbalancedEntryException::class);
        PostingService::assertBalanced([
            ['side' => 'debit', 'amount' => 10.00, 'is_red_storno' => true],
            ['side' => 'credit', 'amount' => 10.00],
        ]);
    }

    public function testRepostPlanKeepsRedStornoFlagWhenCheckingUnchangedPosting(): void
    {
        $entryId = $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id' => $this->periodId,
            'entry_date' => self::YEAR . '-01-15',
            'source_type' => 'purchase_invoice',
            'source_id' => 900001,
            'posted_at' => self::YEAR . '-01-15 12:00:00',
            'posted_by' => $this->userId,
        ], [
            ['account_id' => $this->accounts['518'], 'side' => 'debit', 'amount' => 100.0, 'is_red_storno' => true],
            ['account_id' => $this->accounts['321'], 'side' => 'credit', 'amount' => 100.0, 'is_red_storno' => true],
        ]);
        $plan = $this->container->get(\MyInvoice\Service\Accounting\DocumentRepostService::class)->plan(
            $this->supplierId, 'purchase_invoice', 900001, [
                ['account_code' => '518', 'side' => 'debit', 'amount' => 100.0, 'is_red_storno' => true],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 100.0, 'is_red_storno' => true],
            ],
        );

        self::assertSame($entryId, $plan['entry_id']);
        self::assertNull($plan['tax_neutral_violation']);
        self::assertSame([true, true], array_column($plan['lines'], 'is_red_storno'));
    }

    public function testAmountUpperBoundFindsNegativeRedStornoEntry(): void
    {
        $id = $this->entry(10.00, true, 'Záporný filtr');
        $page = $this->journal->paginate($this->supplierId, ['amount_to' => -1.0], 20, 0);

        self::assertSame(1, $page['total']);
        self::assertSame($id, $page['items'][0]['id']);
        self::assertSame(-10.0, $page['items'][0]['amount']);
    }

    public function testHistoryKeepsRedStornoFlag(): void
    {
        $id = $this->entry(12.50, true, 'Historie červeného storna');

        $history = $this->history->build($id, $this->supplierId);
        self::assertNotNull($history);
        self::assertTrue($history['versions'][0]['lines'][0]['is_red_storno']);
        self::assertTrue($history['versions'][0]['lines'][1]['is_red_storno']);
    }

    private function entry(float $amount, bool $red, string $description): int
    {
        $lines = [
            ['account_id' => $this->accounts['518'], 'side' => 'debit', 'amount' => $amount, 'is_red_storno' => $red, 'line_no' => 1],
            ['account_id' => $this->accounts['321'], 'side' => 'credit', 'amount' => $amount, 'is_red_storno' => $red, 'line_no' => 2],
        ];
        PostingService::assertBalanced($lines);
        return $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id' => $this->periodId,
            'entry_date' => self::YEAR . '-01-15',
            'description' => $description,
            'source_type' => 'manual',
            'posted_at' => self::YEAR . '-01-15 12:00:00',
            'posted_by' => $this->userId,
        ], $lines);
    }
}
