<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\DimensionProfitService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dimenze dokladu → řádky deníku → sestavy (Firma → Dimenze).
 *
 * Ověřuje razítko hlavičky, rozpad nákladu podle dimenzí položek, přerazítkování
 * zaúčtovaného dokladu, přenos do storna, filtr výsledovky a předvahy včetně
 * podřízených hodnot, výsledovku po dimenzi a textové středisko ze mzdových řádků.
 * Vše v jedné transakci, tearDown rollbackne.
 */
#[Group('integration')]
final class DimensionPostingTest extends TestCase
{
    private const YEAR = 2097;

    private Connection $db;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private DimensionService $dimensions;
    private DimensionAssignmentRepository $assignments;
    private FinancialStatementService $statements;
    private TrialBalanceService $trialBalance;
    private DimensionProfitService $profit;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private int $projectType = 0;
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
            $this->journal = $container->get(JournalEntryRepository::class);
            $this->dimensions = $container->get(DimensionService::class);
            $this->assignments = $container->get(DimensionAssignmentRepository::class);
            $this->statements = $container->get(FinancialStatementService::class);
            $this->trialBalance = $container->get(TrialBalanceService::class);
            $this->profit = $container->get(DimensionProfitService::class);
            $periods = $container->get(AccountingPeriodRepository::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->periodId = (int) $periods->findForDate($this->supplierId, self::YEAR . '-06-15')['id'];
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', supplier_group_id = NULL WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->dimensions->setEnabled($this->supplierId, true);
        $types = $this->dimensions->ensureDefaultTypes($this->supplierId, ['projekt', 'stredisko']);
        $this->projectType = $types['project'];
        $this->centerType = $types['cost_center'];
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

    public function testHeaderDimensionsStampEveryLine(): void
    {
        $project = $this->value($this->projectType, 'P-HDR');
        $purchase = $this->purchase('DIM-HDR', [[1_000.00, 210.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $project], []);

        $entryId = $this->postPurchase($purchase);
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $lines = $this->journal->linesForEntry($entryId, $this->supplierId);
        self::assertNotSame([], $lines);
        foreach ($lines as $line) {
            self::assertSame([$this->projectType => $project], $dims[$line['id']] ?? [], 'Každý řádek nese projekt z hlavičky.');
        }
    }

    /** Přeúčtování maže řádky zápisu — jejich dimenze musí odejít s nimi (i u verzované tabulky). */
    public function testRepostReplacesLineDimensionsWithoutOrphans(): void
    {
        $project = $this->value($this->projectType, 'P-REPOST');
        $purchase = $this->purchase('DIM-REPOST', [[2_000.00, 420.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $project], []);
        $entryId = $this->postPurchase($purchase);
        $before = count($this->assignments->entryLineDimensions($this->supplierId, $entryId));

        self::assertSame($entryId, $this->postPurchase($purchase), 'Přeúčtování přepisuje tentýž zápis.');
        self::assertSame($before, count($this->assignments->entryLineDimensions($this->supplierId, $entryId)));
        $orphans = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM journal_entry_line_dimensions d
              WHERE d.supplier_id = ? AND NOT EXISTS (SELECT 1 FROM journal_entry_lines l WHERE l.id = d.line_id)'
        );
        $orphans->execute([$this->supplierId]);
        self::assertSame(0, (int) $orphans->fetchColumn(), 'Po smazání řádku nezůstane jeho dimenze.');
    }

    public function testItemDimensionsSplitExpenseLineExactly(): void
    {
        $a = $this->value($this->projectType, 'P-A');
        $b = $this->value($this->projectType, 'P-B');
        $purchase = $this->purchase('DIM-SPLIT', [[600.00, 126.00], [400.01, 84.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->projectType => $a],
            2 => [$this->projectType => $b],
        ]);

        $entryId = $this->postPurchase($purchase);
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $byProject = [];
        $debit = 0;
        $credit = 0;
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $cents = (int) round($line['amount'] * 100);
            $line['side'] === 'debit' ? $debit += $cents : $credit += $cents;
            $valueId = $dims[$line['id']][$this->projectType] ?? 0;
            if (str_starts_with($this->accountCode((int) $line['account_id']), '5')) {
                $byProject[$valueId] = ($byProject[$valueId] ?? 0) + $cents;
            }
        }
        self::assertSame($debit, $credit, 'Rozpad nesmí rozvážit zápis.');
        self::assertSame([$a => 60_000, $b => 40_001], $byProject, 'Náklad se dělí podle základu položek.');

        $restamp = $this->posting->restampDimensions($this->supplierId, 'purchase_invoice', $purchase);
        self::assertFalse($restamp['needs_repost'], 'Rozdělené řádky už dělení nepotřebují.');
    }

    public function testRestampMovesPostedDocumentAndReversalCarriesDimension(): void
    {
        $from = $this->value($this->projectType, 'P-FROM');
        $to = $this->value($this->projectType, 'P-TO');
        $purchase = $this->purchase('DIM-MOVE', [[7_000.00, 1_470.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $from], []);
        $entryId = $this->postPurchase($purchase);
        self::assertEqualsWithDelta(7_000.00, $this->costOf($from), 0.01);

        $result = $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $to], []);
        self::assertGreaterThan(0, $result['restamp']['lines'], 'Zaúčtované řádky se přerazítkovaly.');
        self::assertEqualsWithDelta(0.0, $this->costOf($from), 0.01);
        self::assertEqualsWithDelta(7_000.00, $this->costOf($to), 0.01);

        // Položky s různými projekty na už zaúčtovaném jednořádkovém nákladu → nutné přeúčtování.
        $mixed = $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->projectType => $from],
        ]);
        self::assertFalse($mixed['restamp']['needs_repost'], 'Jedna položka = jednoznačná hodnota.');

        $this->posting->reverse($this->supplierId, $entryId, ['user_id' => $this->userId]);
        self::assertEqualsWithDelta(0.0, $this->costOf($from), 0.01, 'Storno nese stejnou dimenzi a náklad vyruší.');
    }

    public function testIncomeStatementAndTrialBalanceFilterIncludeDescendants(): void
    {
        $parent = $this->value($this->projectType, 'P-ROOT');
        $child = $this->value($this->projectType, 'P-CHILD', $parent);
        $other = $this->value($this->projectType, 'P-OTHER');
        foreach ([['DIM-R', $parent, 1_000.00], ['DIM-C', $child, 2_000.00], ['DIM-O', $other, 4_000.00]] as [$no, $value, $base]) {
            $purchase = $this->purchase($no, [[$base, round($base * 0.21, 2)]]);
            $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $value], []);
            $this->postPurchase($purchase);
        }

        $filter = $this->dimensions->filter($this->supplierId, $parent);
        self::assertEqualsCanonicalizing([$parent, $child], $filter->valueIds);

        $tb = $this->trialBalance->build($this->supplierId, $this->periodId, null, null, false, false, $filter);
        $expense = 0.0;
        foreach ($tb['rows'] as $row) {
            if ($row['account_type'] === 'expense') {
                $expense += $row['turnover_md'] - $row['turnover_d'];
            }
        }
        self::assertEqualsWithDelta(3_000.00, $expense, 0.01, 'Předvaha po projektu sečte větev, ne cizí projekt.');
        self::assertSame($parent, $tb['dimension']['value_id']);

        $is = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::YEAR . '-12-31', 'full', $filter);
        self::assertEqualsWithDelta(-3_000.00, $is['checks']['profit_current'], 0.01, 'Výsledovka po projektu = −náklady větve.');

        $only = $this->dimensions->filter($this->supplierId, $parent, false);
        $isOnly = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::YEAR . '-12-31', 'full', $only);
        self::assertEqualsWithDelta(-1_000.00, $isOnly['checks']['profit_current'], 0.01);
    }

    public function testDimensionProfitBreakdownRollsUpTree(): void
    {
        $parent = $this->value($this->projectType, 'B-ROOT');
        $child = $this->value($this->projectType, 'B-CHILD', $parent);
        foreach ([['BR-1', $parent, 500.00], ['BR-2', $child, 1_500.00], ['BR-3', null, 100.00]] as [$no, $value, $base]) {
            $purchase = $this->purchase($no, [[$base, round($base * 0.21, 2)]]);
            if ($value !== null) {
                $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $value], []);
            }
            $this->postPurchase($purchase);
        }

        $report = $this->profit->build($this->supplierId, $this->projectType, self::YEAR . '-01-01', self::YEAR . '-12-31', [$this->supplierId]);
        $rows = array_column($report['rows'], null, 'code');
        self::assertEqualsWithDelta(500.00, $rows['B-ROOT']['own']['cost'], 0.01);
        self::assertEqualsWithDelta(2_000.00, $rows['B-ROOT']['total']['cost'], 0.01, 'Nadřízená hodnota sčítá celou větev.');
        self::assertSame(1, $rows['B-CHILD']['depth']);
        self::assertEqualsWithDelta(-2_000.00, $rows['B-ROOT']['total']['result'], 0.01);
        self::assertGreaterThanOrEqual(100.00, $report['unassigned']['cost'], 'Náklad bez projektu je v řádku bez hodnoty.');
    }

    public function testCostCentreTextOnPayrollLinesCountsForLinkedValue(): void
    {
        $center = $this->dimensions->createValue($this->supplierId, $this->centerType, ['code' => 'DIM-REZ', 'name' => 'Režie']);
        self::assertNotNull($center['cost_center_id'], 'Středisko si založí záznam v číselníku středisek.');

        // Mzdový zápis nese jen textový kód střediska, dimenzi ne.
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '521', 'side' => 'debit', 'amount' => 30_000.00, 'cost_center' => 'DIM-REZ'],
            ['account_code' => '331', 'side' => 'credit', 'amount' => 30_000.00, 'cost_center' => 'DIM-REZ'],
        ], ['entry_date' => self::YEAR . '-03-31', 'posted_by' => $this->userId]);

        $filter = $this->dimensions->filter($this->supplierId, (int) $center['id']);
        $is = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::YEAR . '-12-31', 'full', $filter);
        self::assertEqualsWithDelta(-30_000.00, $is['checks']['profit_current'], 0.01);

        $report = $this->profit->build($this->supplierId, $this->centerType, self::YEAR . '-01-01', self::YEAR . '-12-31', [$this->supplierId]);
        $rows = array_column($report['rows'], null, 'code');
        self::assertEqualsWithDelta(30_000.00, $rows['DIM-REZ']['total']['cost'], 0.01);
    }

    public function testManualEntryCarriesExplicitDimensionAndSyncsCostCentre(): void
    {
        $center = $this->dimensions->createValue($this->supplierId, $this->centerType, ['code' => 'DIM-MAN', 'name' => 'Ruční']);
        $dims = $this->dimensions->normalize($this->supplierId, [$this->centerType => $center['id']]);
        $entryId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 100.00, 'dimensions' => $dims],
            ['account_code' => '211', 'side' => 'credit', 'amount' => 100.00],
        ], ['entry_date' => self::YEAR . '-04-01', 'posted_by' => $this->userId]);

        $lines = $this->journal->linesForEntry($entryId, $this->supplierId);
        $lineDims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        self::assertSame('DIM-MAN', $lines[0]['cost_center'], 'Středisko doplní textový kód na řádek.');
        self::assertSame([$this->centerType => $center['id']], $lineDims[$lines[0]['id']]);
        self::assertArrayNotHasKey($lines[1]['id'], $lineDims, 'Řádek bez dimenze nic nedostane.');
    }

    public function testDisabledCompanyStampsNothing(): void
    {
        $project = $this->value($this->projectType, 'P-OFF');
        $purchase = $this->purchase('DIM-OFF', [[1_000.00, 210.00]]);
        // Dimenze na dokladu zůstaly z doby, kdy byla sekce zapnutá.
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $project], []);
        $this->dimensions->setEnabled($this->supplierId, false);

        $entryId = $this->postPurchase($purchase);
        self::assertSame([], $this->assignments->entryLineDimensions($this->supplierId, $entryId), 'Vypnuté dimenze deník nemění.');
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    private function value(int $typeId, string $code, ?int $parentId = null): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $typeId, [
            'code' => $code, 'name' => 'Hodnota ' . $code, 'parent_id' => $parentId,
        ])['id'];
    }

    private function costOf(int $valueId): float
    {
        $filter = $this->dimensions->filter($this->supplierId, $valueId);
        return -$this->statements->incomeStatement($this->supplierId, $this->periodId, self::YEAR . '-12-31', 'full', $filter)['checks']['profit_current'];
    }

    private function accountCode(int $accountId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code FROM chart_of_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        return (string) $stmt->fetchColumn();
    }

    private function postPurchase(int $purchaseId): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchaseId,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId),
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId],
        );
    }

    /** @param list<array{0:float,1:float}> $items základ a DPH položek */
    private function purchase(string $number, array $items): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "dodavatel@example.invalid", "cs", ?, 0, 1)'
        )->execute([$this->supplierId, 'Dodavatel ' . $number, $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $base = array_sum(array_column($items, 0));
        $vat = array_sum(array_column($items, 1));
        $issue = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                 received_at, currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat,
                 total_with_vat, status, vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", "40", "full", ?)'
        )->execute([
            $this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue, $this->currencyId,
            $base, $vat, $base + $vat, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        foreach ($items as $i => [$itemBase, $itemVat]) {
            $pdo->prepare(
                "INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, 'Položka', 1, 'ks', ?, ?, 21.00, ?, ?, ?, ?)"
            )->execute([$id, $itemBase, $this->vatRateId, $itemBase, $itemVat, $itemBase + $itemVat, $i]);
        }
        return $id;
    }
}
