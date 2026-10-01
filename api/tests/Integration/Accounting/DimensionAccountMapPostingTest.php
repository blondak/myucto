<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionAccountMapService;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Účtotvorná dimenze (PLAN-DIMENZE-UCTOVANI F2): hodnota střediska určí analytiku
 * nákladu. FVE → 518.100, Kancelář → 518.200, rozpad 60/40 → dva řádky na haléř,
 * validace mapy (daňová uznatelnost, syntetika), jediný účtotvorný typ, změna dimenze
 * zaúčtovaného dokladu → needs_repost místo tichého přerazítkování.
 */
#[Group('integration')]
final class DimensionAccountMapPostingTest extends TestCase
{
    private const YEAR = 2091;

    private Connection $db;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private DimensionService $dimensions;
    private DimensionAccountMapService $map;
    private DimensionAssignmentRepository $assignments;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $centerType = 0;
    private int $projectType = 0;
    private int $fve = 0;
    private int $office = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->posting = $container->get(PostingService::class);
        $this->journal = $container->get(JournalEntryRepository::class);
        $this->dimensions = $container->get(DimensionService::class);
        $this->map = $container->get(DimensionAccountMapService::class);
        $this->assignments = $container->get(DimensionAssignmentRepository::class);

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
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $container->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', supplier_group_id = NULL WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->dimensions->setEnabled($this->supplierId, true);
        $types = $this->dimensions->ensureDefaultTypes($this->supplierId, ['projekt', 'stredisko']);
        $this->centerType = $types['cost_center'];
        $this->projectType = $types['project'];
        $this->fve = $this->value($this->centerType, 'F2-FVE');
        $this->office = $this->value($this->centerType, 'F2-KAN');
        $this->analytic('518.100', '518');
        $this->analytic('518.200', '518');
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

    public function testWithoutDrivingTypeExpenseStaysOnSynthetic(): void
    {
        $purchase = $this->purchase('F2-NONE', [[1_000.00, 210.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);

        self::assertSame(['518' => 100_000], $this->expenseCents($this->postPurchase($purchase)));
    }

    public function testDimensionValueRoutesExpenseToMappedAnalytic(): void
    {
        $this->enableDriving();
        $purchase = $this->purchase('F2-FVE', [[1_000.00, 210.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);

        $entryId = $this->postPurchase($purchase);

        self::assertSame(['518.100' => 100_000], $this->expenseCents($entryId), 'FVE → 518.100.');
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            if (str_starts_with($this->code((int) $line['account_id']), '3')) {
                self::assertNotSame('518.100', $this->code((int) $line['account_id']));
            }
        }
    }

    public function testValueWithoutMappingStaysOnSynthetic(): void
    {
        $this->enableDriving();
        $other = $this->value($this->centerType, 'F2-OTHER');
        $purchase = $this->purchase('F2-OTHER', [[500.00, 105.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $other], []);

        self::assertSame(['518' => 50_000], $this->expenseCents($this->postPurchase($purchase)));
    }

    public function testSplitSixtyFortyBecomesTwoAnalyticLinesToTheHaler(): void
    {
        $this->enableDriving();
        $project = $this->value($this->projectType, 'F2-PRJ');
        $purchase = $this->purchase('F2-SPLIT', [[1_000.01, 210.00]]);
        $this->dimensions->saveDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchase,
            [$this->projectType => $project],
            [],
            false,
            [0 => [$this->centerType => [
                ['value_id' => $this->fve, 'share' => 0.6],
                ['value_id' => $this->office, 'share' => 0.4],
            ]]],
        );

        $entryId = $this->postPurchase($purchase);

        self::assertSame(['518.100' => 60_001, '518.200' => 40_000], $this->expenseCents($entryId), 'Rozpad 60/40 na haléř.');
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $splits = $this->assignments->entryLineSplits($this->supplierId, $entryId);
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $code = $this->code((int) $line['account_id']);
            if (!str_starts_with($code, '518')) {
                continue;
            }
            $expected = $code === '518.100' ? $this->fve : $this->office;
            self::assertSame(
                self::sorted([$this->centerType => $expected, $this->projectType => $project]),
                self::sorted($dims[(int) $line['id']] ?? []),
                'Díl nese jen svou hodnotu střediska a ostatní dimenze zůstávají.',
            );
            self::assertArrayNotHasKey($this->centerType, $splits[(int) $line['id']] ?? [], 'Díl už nenese rozpad střediska.');
        }

        // Přerazítkování beze změny dimenzí nic nehlásí — díly odpovídají rozpadu dokladu.
        $restamp = $this->posting->restampDimensions($this->supplierId, 'purchase_invoice', $purchase);
        self::assertFalse($restamp['needs_repost']);
        self::assertSame(0, $restamp['lines']);
    }

    /** Položky s různým střediskem: řádek se rozdělí po položkách a díly jdou na své analytiky. */
    public function testItemDimensionsRouteEachItemAndRestampKeepsThem(): void
    {
        $this->enableDriving();
        $purchase = $this->purchase('F2-ITEMS', [[700.00, 147.00], [300.00, 63.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->centerType => $this->fve],
            2 => [$this->centerType => $this->office],
        ]);

        self::assertSame(['518.100' => 70_000, '518.200' => 30_000], $this->expenseCents($this->postPurchase($purchase)));

        $restamp = $this->posting->restampDimensions($this->supplierId, 'purchase_invoice', $purchase);
        self::assertFalse($restamp['needs_repost'], 'Díly rozdělené po položkách na různé analytiky nejsou „nerozdělený řádek".');
        self::assertSame(0, $restamp['lines']);
    }

    public function testChangingDimensionOfPostedDocumentNeedsRepost(): void
    {
        $this->enableDriving();
        $purchase = $this->purchase('F2-CHANGE', [[800.00, 168.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);
        $entryId = $this->postPurchase($purchase);

        $result = $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->office], []);

        self::assertTrue($result['restamp']['needs_repost'], 'Změna účtotvorné dimenze vyžaduje přeúčtování.');
        self::assertTrue($result['restamp']['account_change']);
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            if ($this->code((int) $line['account_id']) === '518.100') {
                self::assertSame(
                    $this->fve,
                    $this->assignments->entryLineDimensions($this->supplierId, $entryId)[(int) $line['id']][$this->centerType] ?? null,
                    'Řádek na 518.100 si ponechá FVE — tiché přerazítkování by nesouhlasilo s účtem.',
                );
            }
        }

        // Přeúčtování pak řádek přesune na analytiku nové hodnoty.
        self::assertSame(['518.200' => 80_000], $this->expenseCents($this->postPurchase($purchase)));
    }

    public function testChangingJournalLineDimensionThatMovesAccountIsRefused(): void
    {
        $this->enableDriving();
        $purchase = $this->purchase('F2-LINE', [[300.00, 63.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);
        $entryId = $this->postPurchase($purchase);
        $lineId = 0;
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            if ($this->code((int) $line['account_id']) === '518.100') {
                $lineId = (int) $line['id'];
            }
        }
        self::assertGreaterThan(0, $lineId);

        try {
            $this->dimensions->saveEntryLines($this->supplierId, $entryId, [$lineId => [$this->centerType => $this->office]]);
            self::fail('Změna střediska na řádku 518.100 musí vyžadovat přeúčtování.');
        } catch (DimensionException $e) {
            self::assertSame('account_change_needs_repost', $e->errorCode);
        }

        // Jiná dimenze téhož řádku účet nemění a projde.
        $project = $this->value($this->projectType, 'F2-LINE-P');
        self::assertSame(1, $this->dimensions->saveEntryLines($this->supplierId, $entryId, [
            $lineId => [$this->centerType => $this->fve, $this->projectType => $project],
        ]));
    }

    public function testNonDeductibleAnalyticUnderDeductibleSyntheticIsRejected(): void
    {
        $this->enableDriving(false);
        try {
            $this->map->saveForValue($this->supplierId, $this->fve, [['synthetic_code' => '518', 'analytic_code' => '518.990']], $this->userId);
            self::fail('Nedaňová analytika pod daňovou syntetikou nesmí projít.');
        } catch (DimensionException $e) {
            self::assertSame('tax_deductibility_mismatch', $e->errorCode);
        }
    }

    public function testAnalyticFromAnotherSyntheticIsRejected(): void
    {
        $this->enableDriving(false);
        $this->analytic('501.100', '501');
        $this->expectException(DimensionException::class);
        $this->map->saveForValue($this->supplierId, $this->fve, [['synthetic_code' => '518', 'analytic_code' => '501.100']], $this->userId);
    }

    public function testOnlyOneDrivingTypePerCompany(): void
    {
        $this->enableDriving(false);
        $this->expectException(DimensionException::class);
        $this->dimensions->updateType($this->supplierId, $this->projectType, ['drives_accounts' => true]);
    }

    public function testMapValidityFollowsEntryDate(): void
    {
        $this->dimensions->updateType($this->supplierId, $this->centerType, ['drives_accounts' => true]);
        $this->map->saveForValue($this->supplierId, $this->fve, [
            ['synthetic_code' => '518', 'analytic_code' => '518.100', 'valid_from' => self::YEAR . '-07-01'],
        ], $this->userId);
        $purchase = $this->purchase('F2-VALID', [[100.00, 21.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);

        self::assertSame(['518' => 10_000], $this->expenseCents($this->postPurchase($purchase)), 'Mapa platná od července červnový doklad nemění.');
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    private function enableDriving(bool $withMap = true): void
    {
        $type = $this->dimensions->updateType($this->supplierId, $this->centerType, ['drives_accounts' => true]);
        self::assertTrue($type['drives_accounts']);
        self::assertSame('5, 6', $type['drives_accounts_mask']);
        if ($withMap) {
            $this->map->saveForValue($this->supplierId, $this->fve, [['synthetic_code' => '518', 'analytic_code' => '518.100']], $this->userId);
            $this->map->saveForValue($this->supplierId, $this->office, [['synthetic_code' => '518', 'analytic_code' => '518.200']], $this->userId);
        }
    }

    private function analytic(string $code, string $parentCode): void
    {
        $pdo = $this->db->pdo();
        $parent = $pdo->prepare('SELECT id, account_type, normal_side, tax_deductibility FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $parent->execute([$this->supplierId, $parentCode]);
        $p = $parent->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($p, 'Syntetika ' . $parentCode . ' v osnově chybí.');
        $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
             VALUES (?, ?, ?, ?, ?, 0, ?, 1, ?)
             ON DUPLICATE KEY UPDATE is_active = 1'
        )->execute([$this->supplierId, $code, 'Analytika ' . $code, $p['account_type'], $p['normal_side'], (int) $p['id'], $p['tax_deductibility']]);
    }

    private function value(int $typeId, string $code): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $typeId, ['code' => $code, 'name' => 'Hodnota ' . $code])['id'];
    }

    /** @return array<string,int> kód nákladového účtu => haléře (MD) */
    private function expenseCents(int $entryId): array
    {
        $out = [];
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $code = $this->code((int) $line['account_id']);
            if (!str_starts_with($code, '5')) {
                continue;
            }
            $out[$code] = ($out[$code] ?? 0) + (int) round((float) $line['amount'] * 100);
        }
        ksort($out);
        return $out;
    }

    private function code(int $accountId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code FROM chart_of_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        return (string) $stmt->fetchColumn();
    }

    /**
     * @param array<int,int> $dims
     * @return array<int,int>
     */
    private static function sorted(array $dims): array
    {
        ksort($dims);
        return $dims;
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
        $base = round(array_sum(array_column($items, 0)), 2);
        $vat = round(array_sum(array_column($items, 1)), 2);
        $issue = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                 received_at, currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat,
                 total_with_vat, status, vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", "40", "full", ?)'
        )->execute([
            $this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue, $this->currencyId,
            $base, $vat, round($base + $vat, 2), $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        foreach ($items as $i => [$itemBase, $itemVat]) {
            $pdo->prepare(
                "INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, 'Položka', 1, 'ks', ?, ?, 21.00, ?, ?, ?, ?)"
            )->execute([$id, $itemBase, $this->vatRateId, $itemBase, $itemVat, round($itemBase + $itemVat, 2), $i]);
        }
        return $id;
    }
}
