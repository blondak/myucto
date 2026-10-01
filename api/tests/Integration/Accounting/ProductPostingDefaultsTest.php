<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Product\ProductPostingDefaults;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Produkt a kategorie jako zdroj účtu a dimenzí (Účtování podle dimenzí, F1, migrace 1948).
 *
 *   • bez nastavení se účtuje bajtově stejně jako dřív (regrese),
 *   • účet: majetek > účet položky > produkt > kategorie > předkontace dokladu,
 *     přijatá: účet položky > druh výdaje > produkt > kategorie > předkontace,
 *   • dimenze: položka > produkt > kategorie > hlavička > zakázka > klient,
 *     přednost položky (a produktu) před hlavičkou jen na výsledkových řádcích,
 *   • vyúčtování proformy přenáší účet i dimenze položky.
 *
 * Vše v jedné transakci, tearDown rollbackne.
 */
#[Group('integration')]
final class ProductPostingDefaultsTest extends TestCase
{
    private const YEAR = 2095;

    private Connection $db;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private DimensionService $dimensions;
    private DimensionAssignmentRepository $assignments;
    private InvoiceRepository $invoices;
    private FinalFromProformaCreator $finalCreator;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $this->dimensions = $container->get(DimensionService::class);
            $this->assignments = $container->get(DimensionAssignmentRepository::class);
            $this->invoices = $container->get(InvoiceRepository::class);
            $this->finalCreator = $container->get(FinalFromProformaCreator::class);
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
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
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

    // ── regrese ──────────────────────────────────────────────────────────────

    public function testWithoutProductSettingsLinesAreIdenticalToUnlinkedDocument(): void
    {
        // Bez nastavení produktu (ani kategorie) se nesmí změnit NIC: vazba položky na kartu
        // dá tytéž řádky deníku jako položka bez karty — vydaná i přijatá strana.
        $category = $this->category('REG-CAT', null);
        $product = $this->product('REG-1', null, null, $category);
        $client = $this->client('Regrese');

        $invoiceId = $this->invoice('FV-REG', $client, [['net' => 1000.00], ['net' => 333.33]]);
        $before = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $this->db->pdo()->prepare('UPDATE invoice_items SET stock_item_id = ? WHERE invoice_id = ?')->execute([$product, $invoiceId]);
        self::assertSame($before, $this->posting->buildFromInvoice($this->supplierId, $invoiceId));

        $purchaseId = $this->purchase('PF-REG', $client, [['net' => 1000.00]]);
        $before = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET stock_item_id = ? WHERE purchase_invoice_id = ?')->execute([$product, $purchaseId]);
        self::assertSame($before, $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId));

        $byAccount = $this->byAccount($this->postInvoice($invoiceId));
        self::assertEqualsWithDelta(1333.33, $byAccount['602']['credit'], 0.001, 'Bez nastavení zůstává výnos na předkontaci.');
    }

    // ── účet ─────────────────────────────────────────────────────────────────

    public function testProductRevenueAccountPostsItemThere(): void
    {
        $product = $this->product('ACC-1', '604', null, null);
        $invoiceId = $this->invoice('FV-ACC-1', $this->client('Účet produktu'), [['net' => 2500.00, 'stock_item_id' => $product]]);

        $byAccount = $this->byAccount($this->postInvoice($invoiceId));
        self::assertEqualsWithDelta(2500.00, $byAccount['604']['credit'], 0.001, 'Výnos jde na účet produktu.');
        self::assertArrayNotHasKey('602', $byAccount);
        self::assertEqualsWithDelta(3025.00, $byAccount['311']['debit'], 0.001, 'Pohledávka se nehýbe.');
    }

    public function testTwoItemsWithDifferentAccountsSplitToTheCent(): void
    {
        // Účet položky přebíjí produkt; položka bez účtu i produktu jde na předkontaci.
        // Výnosové řádky sedí haléřově na základ položek a DPH zůstává jedna noha.
        $product = $this->product('ACC-2', '604', null, null);
        $invoiceId = $this->invoice('FV-ACC-2', $this->client('Dva účty'), [
            ['net' => 333.33, 'stock_item_id' => $product, 'revenue_account_code' => '601'],
            ['net' => 666.67, 'stock_item_id' => $product],
            ['net' => 0.01],
        ]);

        $byAccount = $this->byAccount($this->postInvoice($invoiceId));
        self::assertSame(33333, (int) round($byAccount['601']['credit'] * 100), 'Účet položky přebíjí produkt.');
        self::assertSame(66667, (int) round($byAccount['604']['credit'] * 100), 'Položka bez účtu bere účet produktu.');
        self::assertSame(1, (int) round($byAccount['602']['credit'] * 100), 'Položka bez produktu jde na předkontaci.');
    }

    public function testCategoryAccountIsInheritedFromParentCategory(): void
    {
        $parent = $this->category('PARENT', null, '601');
        $child = $this->category('CHILD', $parent);
        $product = $this->product('ACC-3', null, null, $child);

        $accounts = (new ProductPostingDefaults($this->db))->accountsFor($this->supplierId, [$product]);
        self::assertSame(['code' => '601', 'source' => 'product_category'], $accounts[$product]['revenue']);

        $invoiceId = $this->invoice('FV-ACC-3', $this->client('Kategorie'), [['net' => 100.00, 'stock_item_id' => $product]]);
        self::assertEqualsWithDelta(100.00, $this->byAccount($this->postInvoice($invoiceId))['601']['credit'], 0.001);
    }

    public function testAssetSaleStillWinsOverItemAccount(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO small_assets (supplier_id, name, acquisition_date, quantity, unit_price, price, status)
             VALUES (?, "Prodaný notebook", ?, 1, 5000, 5000, "in_use")'
        )->execute([$this->supplierId, self::YEAR . '-01-15']);
        $card = (int) $this->db->pdo()->lastInsertId();
        $invoiceId = $this->invoice('FV-ACC-4', $this->client('Majetek'), [['net' => 4000.00, 'revenue_account_code' => '604']]);
        $this->db->pdo()->prepare('UPDATE invoice_items SET small_asset_id = ? WHERE invoice_id = ?')->execute([$card, $invoiceId]);

        $byAccount = $this->byAccount($this->postInvoice($invoiceId));
        self::assertEqualsWithDelta(4000.00, $byAccount['642']['credit'], 0.001, 'Majetek má přednost před účtem položky.');
        self::assertArrayNotHasKey('604', $byAccount);
    }

    public function testPurchaseUsesProductExpenseAccountAfterItemAccountAndKind(): void
    {
        $product = $this->product('ACC-5', null, '504', null);
        $vendor = $this->client('Dodavatel zboží');
        $purchaseId = $this->purchase('PF-ACC-5', $vendor, [
            ['net' => 1000.00, 'stock_item_id' => $product],
            ['net' => 200.00, 'stock_item_id' => $product, 'expense_kind' => 'service'],
            ['net' => 50.00, 'stock_item_id' => $product, 'expense_account_code' => '501'],
        ]);

        $lines = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $byAccount = $this->byAccount($entryId);
        self::assertEqualsWithDelta(1000.00, $byAccount['504']['debit'], 0.001, 'Bez účtu položky a druhu → účet produktu.');
        self::assertEqualsWithDelta(200.00, $byAccount['518']['debit'], 0.001, 'Druh výdaje má přednost před produktem.');
        // 501 může mít analytiky (spotřeba materiálu) — účet položky se pak přesměruje pod ni.
        $material = array_sum(array_map(
            static fn (string $code, array $sides): float => str_starts_with($code, '501') ? $sides['debit'] : 0.0,
            array_keys($byAccount),
            $byAccount,
        ));
        self::assertEqualsWithDelta(50.00, $material, 0.001, 'Účet položky má přednost před vším.');
    }

    public function testInvalidAccountsAreRejected(): void
    {
        $validator = new ProductPostingDefaults($this->db);
        foreach ([['518', 'revenue'], ['602', 'expense'], ['311', 'revenue'], ['699999', 'revenue']] as [$code, $kind]) {
            try {
                $validator->validateAccount($this->supplierId, $code, $kind);
                self::fail("Účet {$code} nesmí projít jako {$kind}.");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('604', $validator->validateAccount($this->supplierId, ' 604 ', 'revenue'));
        self::assertNull($validator->validateAccount($this->supplierId, '', 'revenue'));

        $invoiceId = $this->invoice('FV-BAD', $this->client('Špatný účet'), [['net' => 10.00]]);
        $this->expectException(\InvalidArgumentException::class);
        $this->invoices->replaceItems($invoiceId, [[
            'description' => 'X', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 10,
            'vat_rate_id' => $this->vatRateId, 'revenue_account_code' => '518',
        ]]);
    }

    public function testDeactivatedProductAccountFailsLoudlyAtPosting(): void
    {
        $product = $this->product('ACC-6', '604', null, null);
        $invoiceId = $this->invoice('FV-ACC-6', $this->client('Zrušený účet'), [['net' => 10.00, 'stock_item_id' => $product]]);
        $this->db->pdo()->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code = '604'")
            ->execute([$this->supplierId]);
        try {
            $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
            self::fail('Neaktivní účet produktu se nesmí tiše přeskočit.');
        } catch (PostingException $e) {
            self::assertSame('invalid_item_account', $e->errorCode);
        }
    }

    // ── dimenze ──────────────────────────────────────────────────────────────

    public function testDimensionPrecedenceItemProductCategoryHeaderClient(): void
    {
        $this->dimensions->setEnabled($this->supplierId, true);
        $types = $this->dimensions->ensureDefaultTypes($this->supplierId, ['projekt', 'stredisko']);
        [$projectType, $centerType] = [$types['project'], $types['cost_center']];
        $value = fn (int $type, string $code): int => (int) $this->dimensions->createValue($this->supplierId, $type, ['code' => $code, 'name' => $code])['id'];
        $pClient = $value($projectType, 'P-CLIENT');
        $pHeader = $value($projectType, 'P-HEAD');
        $pCategory = $value($projectType, 'P-CAT');
        $cClient = $value($centerType, 'C-CLIENT');
        $cProduct = $value($centerType, 'C-PROD');
        $cItem = $value($centerType, 'C-ITEM');

        $client = $this->client('Dimenze');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'client', $client, [$projectType => $pClient, $centerType => $cClient]);
        $category = $this->category('DIM-CAT', null);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'product_category', $category, [$projectType => $pCategory]);
        $product = $this->product('DIM-1', null, null, $category);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'product', $product, [$centerType => $cProduct]);

        $invoiceId = $this->invoice('FV-DIM', $client, [
            ['net' => 600.00, 'stock_item_id' => $product],
            ['net' => 300.00, 'stock_item_id' => $product],
            ['net' => 100.00],
        ]);
        // Hlavička: projekt; položka 2 nese vlastní středisko.
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $invoiceId, [$projectType => $pHeader], [2 => [$centerType => $cItem]]);

        $prefill = $this->dimensions->productPrefill($this->supplierId, $product);
        self::assertEquals([$projectType => $pCategory, $centerType => $cProduct], $prefill['header']);
        self::assertEquals([$projectType => 'product_category', $centerType => 'product'], $prefill['sources']);

        $entryId = $this->postInvoice($invoiceId);
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $revenue = [];
        $receivable = null;
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $code = $this->accountCode((int) $line['account_id']);
            $lineDims = $dims[(int) $line['id']] ?? [];
            ksort($lineDims);
            if ($code === '602') {
                $revenue[(int) round((float) $line['amount'] * 100)] = $lineDims;
            } elseif ($code === '311') {
                $receivable = $lineDims;
            }
        }
        ksort($revenue);
        self::assertEquals([
            10000 => [$projectType => $pHeader, $centerType => $cClient],    // bez produktu: hlavička > klient
            30000 => [$projectType => $pCategory, $centerType => $cItem],    // položka > produkt, kategorie > hlavička
            60000 => [$projectType => $pCategory, $centerType => $cProduct], // produkt > klient, kategorie > hlavička
        ], $revenue, 'Výsledkové řádky nesou dimenze položky / produktu / kategorie.');
        self::assertEquals([$projectType => $pHeader, $centerType => $cClient], $receivable,
            'Rozvahový řádek nese jen hlavičku (zakázka > klient), dimenze produktu ne.');
    }

    // ── přenos ───────────────────────────────────────────────────────────────

    public function testFinalFromProformaCarriesItemAccountAndDimensions(): void
    {
        $this->dimensions->setEnabled($this->supplierId, true);
        $types = $this->dimensions->ensureDefaultTypes($this->supplierId, ['stredisko']);
        $center = (int) $this->dimensions->createValue($this->supplierId, $types['cost_center'], ['code' => 'C-PRO', 'name' => 'Proforma'])['id'];

        $proforma = $this->invoice('ZF-ACC', $this->client('Proforma'), [['net' => 100.00, 'revenue_account_code' => '604'], ['net' => 50.00]], 'proforma');
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $proforma, [], [1 => [$types['cost_center'] => $center]]);

        $finalId = $this->finalCreator->create($proforma, $this->userId, self::YEAR . '-06-15', self::YEAR . '-06-30');
        $items = $this->invoices->itemsFor($finalId);
        self::assertSame('604', $items[0]['revenue_account_code']);
        self::assertNull($items[1]['revenue_account_code']);
        self::assertEquals([1 => [$types['cost_center'] => $center]], $this->assignments->documentDimensions($this->supplierId, 'invoice', $finalId)['items']);
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    private function postInvoice(int $invoiceId): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $invoiceId,
            $this->posting->buildFromInvoice($this->supplierId, $invoiceId),
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId],
        );
    }

    /** @return array<string,array{debit:float,credit:float}> */
    private function byAccount(int $entryId): array
    {
        $out = [];
        $debit = 0;
        $credit = 0;
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $l) {
            $code = $this->accountCode((int) $l['account_id']);
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][$l['side']] += (float) $l['amount'];
            $cents = (int) round((float) $l['amount'] * 100);
            $l['side'] === 'debit' ? $debit += $cents : $credit += $cents;
        }
        self::assertSame($debit, $credit, 'Σ MD == Σ D (v haléřích).');
        return $out;
    }

    private function accountCode(int $accountId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code FROM chart_of_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        return (string) $stmt->fetchColumn();
    }

    private function client(string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "produkt@example.invalid", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, 'Klient ' . $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function product(string $sku, ?string $revenue, ?string $expense, ?int $categoryId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO stock_items (supplier_id, sku, name, revenue_account_code, expense_account_code) VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'F1-' . $sku, 'Produkt ' . $sku, $revenue, $expense]);
        $id = (int) $pdo->lastInsertId();
        if ($categoryId !== null) {
            $pdo->prepare('INSERT INTO stock_item_categories (supplier_id, stock_item_id, category_id, is_primary) VALUES (?, ?, ?, 1)')
                ->execute([$this->supplierId, $id, $categoryId]);
        }
        return $id;
    }

    private function category(string $code, ?int $parentId, ?string $revenue = null): int
    {
        $pdo = $this->db->pdo();
        $parentPath = '/';
        if ($parentId !== null) {
            $stmt = $pdo->prepare('SELECT path FROM stock_categories WHERE id = ?');
            $stmt->execute([$parentId]);
            $parentPath = (string) $stmt->fetchColumn();
        }
        $pdo->prepare(
            'INSERT INTO stock_categories (supplier_id, parent_id, code, name, path, depth, revenue_account_code) VALUES (?, ?, ?, ?, "/", 0, ?)'
        )->execute([$this->supplierId, $parentId, 'F1-' . $code, 'Kategorie ' . $code, $revenue]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE stock_categories SET path = ?, depth = ? WHERE id = ?')
            ->execute([$parentPath . $id . '/', substr_count($parentPath, '/') - 1, $id]);
        return $id;
    }

    /** @param list<array{net:float, stock_item_id?:int, revenue_account_code?:string}> $items */
    private function invoice(string $varsymbol, int $clientId, array $items, string $type = 'invoice'): int
    {
        $pdo = $this->db->pdo();
        $base = round(array_sum(array_column($items, 'net')), 2);
        $vat = round(array_sum(array_map(static fn (array $i): float => round($i['net'] * 0.21, 2), $items)), 2);
        $issue = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", "1", ?)'
        )->execute([$this->supplierId, $varsymbol, $type, $clientId, $issue, $issue, $issue, $this->currencyId, $base, $vat, round($base + $vat, 2), $this->userId]);
        $id = (int) $pdo->lastInsertId();
        foreach ($items as $i => $item) {
            $itemVat = round($item['net'] * 0.21, 2);
            $pdo->prepare(
                'INSERT INTO invoice_items
                    (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                     vat_classification_code, stock_item_id, revenue_account_code)
                 VALUES (?, "Položka", 1, "ks", ?, ?, 21.00, ?, ?, ?, ?, "1", ?, ?)'
            )->execute([
                $id, $item['net'], $this->vatRateId, $item['net'], $itemVat, round($item['net'] + $itemVat, 2), $i,
                $item['stock_item_id'] ?? null, $item['revenue_account_code'] ?? null,
            ]);
        }
        return $id;
    }

    /** @param list<array{net:float, stock_item_id?:int, expense_kind?:string, expense_account_code?:string}> $items */
    private function purchase(string $number, int $vendorId, array $items): int
    {
        $pdo = $this->db->pdo();
        $base = round(array_sum(array_column($items, 'net')), 2);
        $vat = round(array_sum(array_map(static fn (array $i): float => round($i['net'] * 0.21, 2), $items)), 2);
        $issue = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                 received_at, currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat,
                 total_with_vat, status, vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", "40", "full", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue, $this->currencyId, $base, $vat, round($base + $vat, 2), $this->userId]);
        $id = (int) $pdo->lastInsertId();
        foreach ($items as $i => $item) {
            $itemVat = round($item['net'] * 0.21, 2);
            $pdo->prepare(
                'INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                     stock_item_id, expense_kind, expense_account_code, expense_classification_source)
                 VALUES (?, "Nákup", 1, "ks", ?, ?, 21.00, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $id, $item['net'], $this->vatRateId, $item['net'], $itemVat, round($item['net'] + $itemVat, 2), $i,
                $item['stock_item_id'] ?? null, $item['expense_kind'] ?? null, $item['expense_account_code'] ?? null,
                isset($item['expense_kind']) || isset($item['expense_account_code']) ? 'rule' : null,
            ]);
        }
        return $id;
    }
}
