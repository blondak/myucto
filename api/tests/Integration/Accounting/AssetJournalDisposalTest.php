<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DisposalResiduals;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Migration\Shared\MigratedDisposal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vyřazení majetku, které už zaúčtoval deník (ruční zápis 54x / oprávky, převzatý deník):
 * karta se vyřadí bez zaúčtování, naváže se na zápis a přiznání z něj vezme účetní ZC.
 * Vyřazení v modulu by tutéž zůstatkovou cenu zaúčtovalo podruhé.
 */
#[Group('integration')]
final class AssetJournalDisposalTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private AssetService $service;
    private PostingService $posting;
    private DepreciationEntryRepository $entries;
    private MigratedDisposal $disposals;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(AssetService::class);
            $this->posting = $container->get(PostingService::class);
            $this->entries = $container->get(DepreciationEntryRepository::class);
            $this->disposals = $container->get(MigratedDisposal::class);
            $periods = $container->get(AccountingPeriodRepository::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('assets', 'disposal_entry_id')) {
            $this->markTestSkipped('Chybí migrace assets.disposal_entry_id.');
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    public function testDisposalAlreadyInJournalIsRecordedWithoutSecondPosting(): void
    {
        $assetId = $this->assetInUse('M-JRN-001');
        $entryId = $this->manualDisposalEntry(self::YEAR . '-09-15', 40000.00, 100000.00);
        $journalBefore = $this->journalCount();

        $result = $this->service->disposeFromJournal($this->supplierId, $assetId,
            ['date' => self::YEAR . '-09-15', 'type' => 'liquidated'], ['user_id' => $this->userId]);

        self::assertSame(['disposed', $entryId], [$result['asset']['status'], $result['asset']['disposal_entry_id']]);
        self::assertSame($journalBefore, $this->journalCount(), 'Vyřazení bez zaúčtování nepřidá do deníku nic.');
        self::assertSame(40000.0, $this->sum541(), 'Zůstatková cena je v deníku jen jednou.');
        $tax = $this->entries->findYear($assetId, 'tax', self::YEAR);
        self::assertNotNull($tax, 'Daňový odpis roku vyřazení je potvrzený jako u vyřazení v modulu.');

        $rows = (new DisposalResiduals($this->db))->forPeriod($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31')['rows'];
        self::assertSame([40000.0, DisposalResiduals::BOOK_SOURCE_LINKED_ENTRY],
            [$rows[0]['book_residual_value'], $rows[0]['book_residual_source']], 'Přiznání bere ZC ze zápisu, ne z karty.');

        // Vrácení nic nestornuje: zápis vyřazení patří deníku, ne modulu majetku.
        $reverted = $this->service->revertDisposal($this->supplierId, $assetId, ['user_id' => $this->userId]);
        self::assertSame(['in_use', null], [$reverted['asset']['status'], $reverted['asset']['disposal_entry_id']]);
        self::assertSame($journalBefore, $this->journalCount());
        self::assertNull($this->entries->findYear($assetId, 'tax', self::YEAR), 'Dopočtený daňový odpis se vrátil.');
    }

    public function testEntryWithoutResidualAgainstCardAccountIsRejected(): void
    {
        $assetId = $this->assetInUse('M-JRN-002');
        $other = $this->postManual(self::YEAR . '-09-15', [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 500.00],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 500.00],
        ]);
        try {
            $this->service->disposeFromJournal($this->supplierId, $assetId,
                ['date' => self::YEAR . '-09-15', 'type' => 'liquidated', 'entry_id' => $other], ['user_id' => $this->userId]);
            self::fail('Zápis bez ZC proti oprávkám karty nesmí jít navázat.');
        } catch (AssetException $e) {
            self::assertSame('disposal_entry_mismatch', $e->errorCode);
        }
    }

    public function testMissingJournalEntryDisposesWithWarningAndCardResidual(): void
    {
        $assetId = $this->assetInUse('M-JRN-003');
        $result = $this->service->disposeFromJournal($this->supplierId, $assetId,
            ['date' => self::YEAR . '-09-15', 'type' => 'liquidated'], ['user_id' => $this->userId]);

        self::assertNull($result['asset']['disposal_entry_id']);
        self::assertContains('disposal_entry_not_found', array_column($result['warnings'], 'code'));
    }

    /** Převod bez důvodu vyřazení ve zdroji: tržba 641 ke dni vyřazení = prodej. */
    public function testMigratedDisposalTypeFollowsSaleRevenueInJournal(): void
    {
        $disposals = $this->disposals;
        $date = self::YEAR . '-09-15';
        self::assertSame('liquidated', $disposals->type($this->supplierId, $date, null));

        $this->postManual($date, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 60000.00],
            ['account_code' => '641', 'side' => 'credit', 'amount' => 60000.00],
        ]);
        self::assertSame('sold', $disposals->type($this->supplierId, $date, null));
        self::assertSame(['sold' => true, 'invoice_id' => null], $disposals->saleEvidence($this->supplierId, $date), 'Ruční zápis není faktura.');
        self::assertSame('donated', $disposals->type($this->supplierId, $date, 'donated'), 'Důvod ze zdroje má přednost.');
        self::assertSame('liquidated', $disposals->type($this->supplierId, self::YEAR . '-09-16', null), 'Tržba jiného dne se nepočítá.');
    }

    private function assetInUse(string $number): int
    {
        $created = $this->service->create($this->supplierId, [
            'inventory_number' => $number,
            'name' => 'Stroj ' . $number,
            'input_price' => 100000.00,
            'acquisition_date' => self::YEAR . '-01-10',
            'put_into_use_date' => self::YEAR . '-01-10',
            'status' => 'in_use',
            'tax_method' => 'straight',
            'tax_group' => 2,
            'acc_useful_life_months' => 60,
        ], ['user_id' => $this->userId]);
        return (int) $created['asset']['id'];
    }

    /** Ruční zápis vyřazení: ZC 541/082 a vyřazení z evidence 082/022. */
    private function manualDisposalEntry(string $date, float $residual, float $price): int
    {
        return $this->postManual($date, [
            ['account_code' => '541', 'side' => 'debit', 'amount' => $residual],
            ['account_code' => '082', 'side' => 'credit', 'amount' => $residual],
            ['account_code' => '082', 'side' => 'debit', 'amount' => $price],
            ['account_code' => '022', 'side' => 'credit', 'amount' => $price],
        ]);
    }

    /** @param list<array{account_code:string,side:string,amount:float}> $lines */
    private function postManual(string $date, array $lines): int
    {
        return $this->posting->postDocument($this->supplierId, 'manual', null, $lines, [
            'entry_date' => $date,
            'document_no' => 'ID-' . $date,
            'description' => 'Ruční zápis',
            'posted' => true,
            'posted_by' => $this->userId,
            'user_id' => $this->userId,
        ]);
    }

    private function journalCount(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function sum541(): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(l.amount), 0) FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts c ON c.id = l.account_id
              WHERE l.supplier_id = ? AND c.account_code = '541' AND l.side = 'debit' AND e.entry_date BETWEEN ? AND ?"
        );
        $stmt->execute([$this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31']);
        return round((float) $stmt->fetchColumn(), 2);
    }
}
