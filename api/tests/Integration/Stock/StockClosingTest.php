<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Epic SKLAD, plán §8.2 scénář 13 (uzávěrkový krok B — 112/501 + 132/504,
 * product karty jen warning) a scénář 14 (tax_evidence firma — žádný
 * deníkový zápis, doc-lock booked_at).
 */
#[Group('integration')]
final class StockClosingTest extends StockTestCase
{
    private const YEAR = 2098;

    public function testStockClosingValuationPostsBalancedMaterialAndGoodsEntries(): void
    {
        [$closing, $periods, $journal, $periodId, $supplierId, $whId] = $this->setUpDoubleEntryPeriod();

        $materialItem = $this->item($supplierId, 'MAT-1', 'material');
        $goodsItem    = $this->item($supplierId, 'GDS-1', 'goods');
        $this->receiveStock($supplierId, $whId, $materialItem, '10.000', 10.0, self::YEAR . '-03-01');
        $this->receiveStock($supplierId, $whId, $goodsItem, '5.000', 20.0, self::YEAR . '-03-02');

        $closing->start($supplierId, $periodId, $this->rv($periods, $supplierId, $periodId), $this->meta());
        $result = $closing->runStockValuation($supplierId, $periodId, $this->rv($periods, $supplierId, $periodId), $this->meta());

        self::assertSame(100.0, $result['totals']['closing']['material']);
        self::assertSame(100.0, $result['totals']['closing']['goods']);
        self::assertArrayHasKey('closing', $result['entry_ids']);

        $entry = $journal->findBySource($supplierId, 'closing', ClosingSourceId::stockClosing($periodId));
        self::assertNotNull($entry);
        self::assertNotNull($entry['posted_at']);

        $byCode = $this->linesByAccountCode($supplierId, (int) $entry['id']);
        self::assertSame(10000, self::cents($byCode['112']['debit'] ?? 0.0), 'MD 112 = konečný stav materiálu.');
        self::assertSame(10000, self::cents($byCode['501']['credit'] ?? 0.0), 'D 501 = konečný stav materiálu.');
        self::assertSame(10000, self::cents($byCode['132']['debit'] ?? 0.0), 'MD 132 = konečný stav zboží.');
        self::assertSame(10000, self::cents($byCode['504']['credit'] ?? 0.0), 'D 504 = konečný stav zboží.');

        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($byCode as $c) {
            $totalDebit += self::cents($c['debit']);
            $totalCredit += self::cents($c['credit']);
        }
        self::assertSame($totalDebit, $totalCredit, 'Σ MD == Σ D uzávěrkového zápisu zásob.');
    }

    public function testProductCardOnHandPostsClosingEntry(): void
    {
        [$closing, $periods, $journal, $periodId, $supplierId, $whId] = $this->setUpDoubleEntryPeriod();

        $productItem = $this->item($supplierId, 'PRD-1', 'product');
        $this->receiveStock($supplierId, $whId, $productItem, '3.000', 50.0, self::YEAR . '-03-01');

        $closing->start($supplierId, $periodId, $this->rv($periods, $supplierId, $periodId), $this->meta());
        $result = $closing->runStockValuation($supplierId, $periodId, $this->rv($periods, $supplierId, $periodId), $this->meta());

        self::assertSame(150.0, $result['totals']['closing']['product']);
        $entry = $journal->findBySource($supplierId, 'closing', ClosingSourceId::stockClosing($periodId));
        self::assertNotNull($entry);
        $byCode = $this->linesByAccountCode($supplierId, (int) $entry['id']);
        self::assertSame(15000, self::cents($byCode['123']['debit'] ?? 0.0));
        self::assertSame(15000, self::cents($byCode['583']['credit'] ?? 0.0));
        self::assertSame('stock_unbilled_receipts', $result['warnings'][0]['key']);
    }

    public function testTaxEvidenceStockDocumentsHaveNoJournalEntryAndAreBookLocked(): void
    {
        $supplierId = $this->createSupplier('tax_evidence');
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'TE-1');

        $posted = $this->receiveStock($supplierId, $whId, $itemId, '4.000', 25.0);

        self::assertNull($posted['journal_entry_id'], 'tax_evidence: skladové doklady nikdy negenerují deníkový zápis (v1 způsob B).');
        self::assertNotNull($posted['booked_at'], 'post() musí nastavit doc-lock booked_at i bez podvojného účetnictví.');
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    /** @return array{0:ClosingService,1:AccountingPeriodRepository,2:JournalEntryRepository,3:int,4:int,5:int} */
    private function setUpDoubleEntryPeriod(): array
    {
        /** @var ClosingService $closing */
        $closing = $this->container->get(ClosingService::class);
        /** @var AccountingPeriodRepository $periods */
        $periods = $this->container->get(AccountingPeriodRepository::class);
        /** @var JournalEntryRepository $journal */
        $journal = $this->container->get(JournalEntryRepository::class);
        /** @var ChartOfAccountsSeeder $seeder */
        $seeder = $this->container->get(ChartOfAccountsSeeder::class);

        $supplierId = $this->createSupplier('double_entry');
        $seeder->seedForSupplier($supplierId);
        $whId = $this->warehouse($supplierId);
        $periodId = $periods->create($supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');

        return [$closing, $periods, $journal, $periodId, $supplierId, $whId];
    }

    private function rv(AccountingPeriodRepository $periods, int $supplierId, int $periodId): int
    {
        return (int) $periods->findById($supplierId, $periodId)['row_version'];
    }

    /** @return array{user_id:int, posted_by:int} */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }

    /** @return array<string,array{debit:float,credit:float}> */
    private function linesByAccountCode(int $supplierId, int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ?
              ORDER BY l.line_no, l.id'
        );
        $stmt->execute([$entryId, $supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $code = (string) $r['account_code'];
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][(string) $r['side']] += (float) $r['amount'];
        }
        return $out;
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}
