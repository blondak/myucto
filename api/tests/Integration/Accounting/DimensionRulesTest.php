<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleAudit;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pravidla dimenzí podle účtu a rozpad řádku (Firma → Dimenze → Pravidla).
 *
 * Povinná dimenze při zaúčtování dokladu i ručního zápisu, varování, výchozí hodnota
 * pravidla (i vozidlo podle platební karty), platnost podle data, rozpad dokladu
 * a řádku včetně storna a přerazítkování, zpětná kontrola deníku. Vše v jedné
 * transakci, tearDown rollbackne.
 */
#[Group('integration')]
final class DimensionRulesTest extends TestCase
{
    private const YEAR = 2096;
    private const DATE = self::YEAR . '-06-15';

    private Connection $db;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private DimensionService $dimensions;
    private DimensionAssignmentRepository $assignments;
    private DimensionRuleService $rules;
    private DimensionRuleAudit $audit;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $centerType = 0;
    private int $projectType = 0;
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
            $this->rules = $container->get(DimensionRuleService::class);
            $this->audit = $container->get(DimensionRuleAudit::class);
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
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', supplier_group_id = NULL WHERE id = ?")
            ->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM dimension_account_rules WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->dimensions->setEnabled($this->supplierId, true);
        $types = $this->dimensions->ensureDefaultTypes($this->supplierId, ['stredisko', 'projekt', 'vozidlo']);
        $this->centerType = $types['cost_center'];
        $this->projectType = $types['project'];
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

    public function testPurchaseWithoutRequiredDimensionIsRefusedWithNamedDimension(): void
    {
        $this->rule(['account_mask' => '5, 6, !59']);
        $purchase = $this->purchase('RULE-ERR', [[1_000.00, 210.00]]);
        try {
            $this->postPurchase($purchase);
            self::fail('Doklad bez povinné dimenze se nesměl zaúčtovat.');
        } catch (PostingException $e) {
            self::assertSame('dimension_required', $e->errorCode);
            self::assertStringContainsString('„Středisko"', $e->getMessage());
            self::assertStringContainsString('518', $e->getMessage());
            self::assertSame('518', substr($e->context['violations'][0]['account_code'], 0, 3));
        }
        self::assertNull($this->journal->findBySource($this->supplierId, 'purchase_invoice', $purchase), 'Nic se nezapsalo.');

        $center = $this->value($this->centerType, 'S-OK');
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $center], []);
        $entryId = $this->postPurchase($purchase);
        self::assertGreaterThan(0, $entryId);
        self::assertSame([], $this->posting->dimensionWarnings());
    }

    public function testManualEntryRefusedAndPassesWithExplicitDimension(): void
    {
        $this->rule(['account_mask' => '518']);
        try {
            $this->manual([]);
            self::fail('Ruční zápis bez povinné dimenze se nesměl zaúčtovat.');
        } catch (PostingException $e) {
            self::assertSame('dimension_required', $e->errorCode);
        }
        $center = $this->value($this->centerType, 'S-MAN');
        $entryId = $this->manual([$this->centerType => $center]);
        self::assertGreaterThan(0, $entryId);
    }

    public function testWarningRulePostsAndReportsWarning(): void
    {
        $this->rule(['account_mask' => '5', 'enforcement' => 'warning']);
        $entryId = $this->manual([]);
        self::assertGreaterThan(0, $entryId);
        $warnings = $this->posting->dimensionWarnings();
        self::assertCount(1, $warnings);
        self::assertSame($this->centerType, $warnings[0]['type_id']);
        self::assertSame('518', substr($warnings[0]['account_code'], 0, 3));
    }

    public function testRuleDefaultFillsMissingTypeButDocumentWins(): void
    {
        $fallback = $this->value($this->centerType, 'S-DEF');
        $own = $this->value($this->centerType, 'S-OWN');
        $this->rule(['account_mask' => '5', 'default_value_id' => $fallback]);

        $plain = $this->postPurchase($this->purchase('RULE-DEF', [[500.00, 105.00]]));
        $lines = $this->lines($plain);
        foreach ($lines as $line) {
            $isCost = str_starts_with($line['account_code'], '5');
            self::assertSame($isCost ? [$this->centerType => $fallback] : [], $line['dims'], 'Výchozí hodnota jen na nákladovém řádku.');
            self::assertSame($isCost ? 'S-DEF' : null, $line['cost_center'], 'Hodnota navázaná na středisko doplní i textové středisko.');
        }

        $purchase = $this->purchase('RULE-OWN', [[500.00, 105.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $own], []);
        foreach ($this->lines($this->postPurchase($purchase)) as $line) {
            self::assertSame([$this->centerType => $own], $line['dims'], 'Dimenze dokladu má přednost před výchozí hodnotou pravidla.');
        }
    }

    public function testRuleValidityFollowsEntryDate(): void
    {
        $this->rule(['account_mask' => '5', 'valid_from' => self::YEAR . '-07-01']);
        self::assertGreaterThan(0, $this->manual([]), 'Před platností pravidla se účtuje beze změny.');
        $this->expectException(PostingException::class);
        $this->manual([], self::YEAR . '-07-01');
    }

    public function testDisabledDimensionsIgnoreRules(): void
    {
        $this->rule(['account_mask' => '5']);
        $this->dimensions->setEnabled($this->supplierId, false);
        self::assertGreaterThan(0, $this->manual([]));
    }

    public function testVehicleFromPaymentCardOnPurchase(): void
    {
        $vehicleType = $this->dimensions->ensureDefaultTypes($this->supplierId, ['vozidlo'])['vehicle'];
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name) VALUES (?, ?)')
            ->execute([$this->supplierId, 'Řidič Testovací']);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO cars (supplier_id, registration, driver_employee_id) VALUES (?, ?, ?)')
            ->execute([$this->supplierId, '9Z9 9999', $employeeId]);
        $carId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO payment_cards (supplier_id, label, last4, employee_id) VALUES (?, 'Karta řidiče', '4242', ?)")
            ->execute([$this->supplierId, $employeeId]);
        $vehicle = (int) $this->dimensions->createValue($this->supplierId, $vehicleType, [
            'code' => '9Z99999', 'name' => 'Služební vůz', 'car_id' => $carId,
        ])['id'];
        $this->rule(['dimension_type_id' => $vehicleType, 'account_mask' => '5', 'enforcement' => 'none', 'default_from_card' => true]);

        $purchase = $this->purchase('RULE-CARD', [[800.00, 168.00]]);
        $pdo->prepare("UPDATE purchase_invoices SET card_last4 = '4242' WHERE id = ?")->execute([$purchase]);
        $costLines = array_filter($this->lines($this->postPurchase($purchase)), static fn (array $l): bool => str_starts_with($l['account_code'], '5'));
        self::assertNotSame([], $costLines);
        foreach ($costLines as $line) {
            self::assertSame([$vehicleType => $vehicle], $line['dims']);
        }
    }

    public function testHeaderSplitStampsLinesAndReversalMirrorsIt(): void
    {
        $this->rule(['account_mask' => '5']);
        $a = $this->value($this->centerType, 'S-A');
        $b = $this->value($this->centerType, 'S-B');
        $purchase = $this->purchase('RULE-SPLIT', [[1_000.00, 210.00]]);
        $split = [0 => [$this->centerType => [['value_id' => $a, 'share' => 0.6], ['value_id' => $b, 'share' => 0.4]]]];
        $saved = $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [], false, $split);
        self::assertSame([$this->centerType => [['value_id' => $a, 'share' => 0.6], ['value_id' => $b, 'share' => 0.4]]], $saved['splits'][0]);

        $entryId = $this->postPurchase($purchase);
        $splits = $this->assignments->entryLineSplits($this->supplierId, $entryId);
        $lines = $this->journal->linesForEntry($entryId, $this->supplierId);
        self::assertCount(count($lines), $splits, 'Rozpad hlavičky dostane každý řádek zápisu.');
        foreach ($splits as $byType) {
            self::assertSame([$this->centerType => [$a => 0.6, $b => 0.4]], $byType);
        }
        self::assertSame([], $this->assignments->entryLineDimensions($this->supplierId, $entryId), 'Typ s rozpadem nemá jedinou hodnotu.');

        // Přerazítkování beze změny nic nemění a rozpad zachová.
        $restamp = $this->posting->restampDimensions($this->supplierId, 'purchase_invoice', $purchase);
        self::assertSame(0, $restamp['lines']);

        $reversal = $this->posting->reverse($this->supplierId, $entryId, ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
        $reversalSplits = $this->assignments->entryLineSplits($this->supplierId, $reversal);
        self::assertCount(count($lines), $reversalSplits);
        foreach ($reversalSplits as $byType) {
            self::assertSame([$this->centerType => [$a => 0.6, $b => 0.4]], $byType, 'Storno odečte ze stejného rozpadu.');
        }

        // Jediná hodnota zvolená teď rozpad téhož typu nahradí.
        $again = $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $a], []);
        self::assertSame([], $again['splits']);
    }

    public function testSplitMustSumToHundredPercent(): void
    {
        $a = $this->value($this->centerType, 'S-X');
        $b = $this->value($this->centerType, 'S-Y');
        $this->expectException(DimensionException::class);
        $this->expectExceptionMessage('100 %');
        $this->dimensions->normalizeSplits($this->supplierId, [
            $this->centerType => [['value_id' => $a, 'share' => 0.5], ['value_id' => $b, 'share' => 0.4]],
        ]);
    }

    public function testManualLineSplitAndRequiredDimensionCannotBeRemoved(): void
    {
        $a = $this->value($this->centerType, 'S-M1');
        $b = $this->value($this->centerType, 'S-M2');
        $this->rule(['account_mask' => '518']);
        $entryId = $this->manual([$this->centerType => $a]);
        $costLine = null;
        foreach ($this->lines($entryId) as $line) {
            if (str_starts_with($line['account_code'], '518')) {
                $costLine = $line['id'];
            }
        }
        self::assertNotNull($costLine);

        $changed = $this->dimensions->saveEntryLines($this->supplierId, $entryId, [], [
            $costLine => [$this->centerType => [['value_id' => $a, 'share' => 0.25], ['value_id' => $b, 'share' => 0.75]]],
        ]);
        self::assertSame(1, $changed);
        self::assertSame([$this->centerType => [$a => 0.25, $b => 0.75]], $this->assignments->entryLineSplits($this->supplierId, $entryId)[$costLine]);
        self::assertArrayNotHasKey($costLine, $this->assignments->entryLineDimensions($this->supplierId, $entryId));

        try {
            $this->dimensions->saveEntryLines($this->supplierId, $entryId, [], [$costLine => []]);
            self::fail('Povinnou dimenzi nešlo z řádku odebrat.');
        } catch (DimensionException $e) {
            self::assertSame('dimension_required', $e->errorCode);
        }
    }

    public function testAuditFindsHistoricLinesAndCoverageSuggests(): void
    {
        $center = $this->value($this->centerType, 'S-AUD');
        $bare = $this->manual([]);
        $tagged = $this->manual([$this->centerType => $center]);
        $rule = $this->rule(['account_mask' => '518', 'enforcement' => 'warning']);

        $result = $this->audit->violations($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $entries = array_unique(array_column($result['rows'], 'entry_id'));
        self::assertContains($bare, $entries);
        self::assertNotContains($tagged, $entries);
        self::assertSame(1, $result['total']);
        self::assertSame('warning', $result['rows'][0]['enforcement']);

        $coverage = array_values(array_filter(
            $this->audit->coverage($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31'),
            fn (array $c): bool => $c['type_id'] === $this->centerType && $c['synthetic'] === '518',
        ));
        self::assertSame(2, $coverage[0]['lines']);
        self::assertSame(1, $coverage[0]['covered']);

        $this->rules->delete($this->supplierId, (int) $rule['id']);
        self::assertSame(0, $this->audit->violations($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31')['total']);
    }

    public function testRuleDefaultValueIsInUse(): void
    {
        $value = $this->value($this->centerType, 'S-USED');
        $this->rule(['account_mask' => '5', 'default_value_id' => $value]);
        self::assertSame(['deleted' => false], $this->dimensions->deleteValue($this->supplierId, $value), 'Hodnota z pravidla se jen uzavře.');
    }

    public function testRuleValidation(): void
    {
        foreach ([
            ['account_mask' => '5%'],
            ['account_mask' => '5', 'enforcement' => 'none'],
            ['account_mask' => '5', 'default_from_card' => true],
            ['account_mask' => '5', 'valid_from' => self::YEAR . '-05-01', 'valid_to' => self::YEAR . '-04-01'],
        ] as $body) {
            try {
                $this->rule($body);
                self::fail('Neplatné pravidlo prošlo: ' . json_encode($body));
            } catch (DimensionException) {
                self::assertTrue(true);
            }
        }
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function rule(array $body): array
    {
        return $this->rules->create($this->supplierId, $body + ['dimension_type_id' => $this->centerType, 'enforcement' => 'error']);
    }

    private function value(int $typeId, string $code): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $typeId, ['code' => $code, 'name' => 'Hodnota ' . $code])['id'];
    }

    /** @param array<int,int> $dims */
    private function manual(array $dims, string $date = self::DATE): int
    {
        return $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 300.00] + ($dims !== [] ? ['dimensions' => $dims] : []),
            ['account_code' => '321', 'side' => 'credit', 'amount' => 300.00],
        ], ['entry_date' => $date, 'posted_by' => $this->userId]);
    }

    /** @return list<array{id:int, account_code:string, dims:array<int,int>, cost_center:?string}> */
    private function lines(int $entryId): array
    {
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, a.account_code, l.cost_center FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ? ORDER BY l.line_no, l.id'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'account_code' => (string) $r['account_code'],
            'dims' => $dims[(int) $r['id']] ?? [],
            'cost_center' => $r['cost_center'] !== null ? (string) $r['cost_center'] : null,
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function postPurchase(int $purchaseId): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchaseId,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId),
            ['entry_date' => self::DATE, 'posted_by' => $this->userId],
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
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                 received_at, currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat,
                 total_with_vat, status, vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", "40", "full", ?)'
        )->execute([
            $this->supplierId, $vendorId, $number, self::DATE, self::DATE, self::DATE, self::DATE, $this->currencyId,
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
