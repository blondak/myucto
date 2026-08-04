<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Security;

use MyInvoice\Action\Accounting\Assets\AssetAction;
use MyInvoice\Action\Accounting\Cash\CashDocumentAction;
use MyInvoice\Action\Accounting\ChartOfAccountsAction;
use MyInvoice\Action\Accounting\ExpenseClassificationRuleAction;
use MyInvoice\Action\Accounting\InvoiceSettlementAction;
use MyInvoice\Action\Accounting\SmallAssetAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Regrese k externímu security reportu 2026-08 (R2) — CWE-639 / BOLA na cizích klíčích
 * z těla requestu, VĚTEV /api/accounting/* (sweep sites S026–S048).
 *
 * ## Proč vlastní soubor vedle TenantReferenceGuardIdorTest
 *
 * Reporter tuhle skupinu 25 endpointů projel POUZE čtením kódu: na jeho instanci měly
 * obě firmy `accounting_mode = 'tax_evidence'`, takže {@see \MyInvoice\Http\GuardsAccountingMode}
 * vracel 403 `wrong_accounting_mode` dřív, než se cokoli vyhodnotilo. Sám k tomu napsal
 * „If any residual risk remains in this class, it is here." Tenhle test tu díru zavírá:
 * obě firmy jedou v `double_entry` a útoky se pouštějí ŽIVĚ přes Action vrstvu.
 *
 * Pozn.: `GuardsAccountingMode` čte `supplier.accounting_mode` VOLAJÍCÍHO
 * (`SupplierGuard::currentId()`), takže je to feature gate nad vlastní firmou, ne tenant
 * hranice — nesmí se používat jako důkaz nedosažitelnosti.
 *
 * ## Co každý test dělá
 *
 * Útočník je `accountant` firmy A; do těla dosadí `*_id` firmy B. Očekává se 4xx, v těle
 * odpovědi NIC z firmy B (kanárek `TENANT B SECRET`) a — u zápisových cest — zpětné
 * přečtení DB, že nevznikl trvalý cross-tenant zápis. Ke každému útoku je pozitivní
 * kontrola s VLASTNÍM id, jinak by test zezelenal i tehdy, kdyby obrana blokovala vše.
 *
 * Pokryté skupiny obran (jedna reprezentace na skupinu, viz report §1):
 *   - `assertVendor()`                    → S034/S035 expense-rules
 *   - `assertSourceBelongsToTenant()`     → S036 small-assets
 *   - `assertPurchaseInvoiceOwned()`      → S042/S043 assets
 *   - `requireActiveRegister()`           → S046/S047 cash-documents
 *   - `find($supplierId, $id)`            → S026 accounts (parent_id)
 *   - `lockInvoice($supplierId, $docId)`  → S041 settlements (doc_id — zapisuje úhradu)
 *
 * A dva NOVÉ nálezy, které sweep minul (extrakce viděla jen `user_id` v Action vrstvě,
 * FK se ale konzumují až v AssetService::normalize()):
 *   - `assets.purchase_invoice_item_id` byl nevázaný na create i update,
 *   - `assets.sale_invoice_id` šel přepsat přes PUT /assets/{id}, ačkoli je to
 *     lifecycle pole psané jen dispose()/revertDisposal().
 *
 * Vše běží v jedné transakci, kterou tearDown zahodí.
 */
#[Group('integration')]
final class AccountingTenantReferenceIdorTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2098;
    private const CANARY = 'TENANT B SECRET';

    private Connection $db;
    private ContainerInterface $container;
    private PDO $pdo;
    private bool $inTx = false;

    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $countryId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->container = Bootstrap::buildApp()->getContainer();
            $this->db  = $this->container->get(Connection::class);
            $this->pdo = $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $this->supplierA  = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($this->pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->countryId  = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierA === 0 || $this->userId === 0 || $this->currencyId === 0
            || $this->vatRateId === 0 || $this->countryId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user/currency/vat_rate/country).');
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;

        // Firma B je plný klon A → i ona jede v 'double_entry'. Kdyby zůstala v daňové
        // evidenci, útok by nikdy nedošel dál než k 403 a test by nic netestoval.
        $this->supplierB = $this->createIsolatedSupplier($this->pdo, $this->supplierA);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id IN (?, ?)")
            ->execute([$this->supplierA, $this->supplierB]);

        $seeder = $this->container->get(ChartOfAccountsSeeder::class);
        $seeder->seedForSupplier($this->supplierA);
        $seeder->seedForSupplier($this->supplierB);

        $periods = $this->container->get(AccountingPeriodRepository::class);
        foreach ([$this->supplierA, $this->supplierB] as $sid) {
            $periods->create($sid, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        }
    }

    protected function tearDown(): void
    {
        if ($this->inTx && isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    // ── obrana: assertVendor() ────────────────────────────────────────────────

    /** Sweep S034 — POST /api/accounting/expense-rules s cizím `vendor_client_id`. */
    public function testExpenseRuleRejectsForeignVendorClient(): void
    {
        $clientA = $this->client($this->supplierA, 'Vlastní dodavatel A');
        $clientB = $this->client($this->supplierB, self::CANARY . ' dodavatel');

        $action = $this->container->get(ExpenseClassificationRuleAction::class);
        $body = ['expense_kind' => 'service', 'name' => 'idor-regrese'];

        $res = $this->call($action, 'create', 'POST', ['vendor_client_id' => $clientB] + $body);
        self::assertSame(422, $res['status'], 'Cizí vendor_client_id musí skončit 4xx, ne 201.');
        self::assertSame('vendor_not_found', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM expense_classification_rules WHERE supplier_id = ? AND vendor_client_id = ?',
            [$this->supplierA, $clientB],
        ), 'Cizí vendor_client_id se nesmí uložit ani „potichu".');

        $ok = $this->call($action, 'create', 'POST', ['vendor_client_id' => $clientA] + $body);
        self::assertSame(201, $ok['status'], 'Vlastní vendor_client_id obrana blokovat nesmí.');
        self::assertSame($clientA, (int) ($ok['body']['rule']['vendor_client_id'] ?? 0));
    }

    // ── obrana: assertSourceBelongsToTenant() ─────────────────────────────────

    /** Sweep S036 — POST /api/accounting/small-assets s cizím `purchase_invoice_item_id`. */
    public function testSmallAssetRejectsForeignPurchaseInvoiceItem(): void
    {
        [, $itemA] = $this->purchaseInvoice($this->supplierA, 'PF-A-1', 'Vlastní položka A');
        [, $itemB] = $this->purchaseInvoice($this->supplierB, 'PF-B-1', self::CANARY . ' položka');

        $action = $this->container->get(SmallAssetAction::class);
        $body = ['name' => 'Notebook', 'acquisition_date' => self::YEAR . '-02-01', 'price' => 12000];

        $res = $this->call($action, 'create', 'POST', ['purchase_invoice_item_id' => $itemB] + $body);
        self::assertSame(422, $res['status'], 'Cizí purchase_invoice_item_id musí skončit 4xx.');
        self::assertSame('source_not_found', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM small_assets WHERE supplier_id = ? AND purchase_invoice_item_id = ?',
            [$this->supplierA, $itemB],
        ), 'Karta s cizím zdrojovým řádkem nesmí v DB zůstat.');

        $ok = $this->call($action, 'create', 'POST', ['purchase_invoice_item_id' => $itemA] + $body);
        self::assertSame(201, $ok['status'], 'Vlastní purchase_invoice_item_id obrana blokovat nesmí.');
        self::assertSame($itemA, (int) ($ok['body']['card']['purchase_invoice_item_id'] ?? 0));
    }

    // ── obrana: assertPurchaseInvoiceOwned() ──────────────────────────────────

    /** Sweep S042 — POST /api/accounting/assets s cizí `purchase_invoice_id`. */
    public function testAssetCardRejectsForeignPurchaseInvoice(): void
    {
        [$invoiceA] = $this->purchaseInvoice($this->supplierA, 'PF-A-2', 'Vlastní PF A');
        [$invoiceB] = $this->purchaseInvoice($this->supplierB, 'PF-B-2', self::CANARY . ' PF');

        $action = $this->container->get(AssetAction::class);

        $res = $this->call($action, 'create', 'POST', $this->assetPayload('M-IDOR-1', ['purchase_invoice_id' => $invoiceB]));
        self::assertSame(404, $res['status'], 'Cizí purchase_invoice_id musí skončit 4xx, ne 201.');
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM assets WHERE supplier_id = ? AND purchase_invoice_id = ?',
            [$this->supplierA, $invoiceB],
        ), 'Karta s cizí PF nesmí v DB zůstat.');

        $ok = $this->call($action, 'create', 'POST', $this->assetPayload('M-IDOR-2', ['purchase_invoice_id' => $invoiceA]));
        self::assertSame(201, $ok['status'], 'Vlastní purchase_invoice_id obrana blokovat nesmí.');
        self::assertSame($invoiceA, (int) ($ok['body']['asset']['purchase_invoice_id'] ?? 0));
    }

    /**
     * NOVÝ NÁLEZ — `assets.purchase_invoice_item_id` byl nevázaný.
     *
     * Sweep ho odbyl poznámkou „sloupec je bez FK a nikdo ho nejoinuje" (report §3).
     * Únik z toho opravdu nebyl, ale TRVALÝ zápis přes hranici firmy ano — a přesně
     * takový zápis se z něj stane únikem první den, kdy na ten sloupec někdo napíše JOIN.
     */
    public function testAssetCardRejectsForeignPurchaseInvoiceItem(): void
    {
        [$invoiceA, $itemA] = $this->purchaseInvoice($this->supplierA, 'PF-A-3', 'Vlastní položka A');
        [, $itemB] = $this->purchaseInvoice($this->supplierB, 'PF-B-3', self::CANARY . ' položka');

        $action = $this->container->get(AssetAction::class);

        $res = $this->call($action, 'create', 'POST', $this->assetPayload('M-IDOR-3', [
            'purchase_invoice_id' => $invoiceA,
            'purchase_invoice_item_id' => $itemB,
        ]));
        self::assertSame(404, $res['status'], 'Cizí purchase_invoice_item_id musí skončit 4xx.');
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM assets WHERE supplier_id = ? AND purchase_invoice_item_id = ?',
            [$this->supplierA, $itemB],
        ), 'Trvalý cross-tenant zápis je nález i bez úniku v odpovědi.');

        $ok = $this->call($action, 'create', 'POST', $this->assetPayload('M-IDOR-4', [
            'purchase_invoice_id' => $invoiceA,
            'purchase_invoice_item_id' => $itemA,
        ]));
        self::assertSame(201, $ok['status'], 'Vlastní purchase_invoice_item_id obrana blokovat nesmí.');
        self::assertSame($itemA, (int) ($ok['body']['asset']['purchase_invoice_item_id'] ?? 0));

        // A totéž na update() — normalize() staví kartu nad `SELECT *`, takže update
        // je druhá, samostatná cesta k témuž sloupci.
        $assetId = (int) $ok['body']['asset']['id'];
        $upd = $this->call($action, 'update', 'PUT', ['purchase_invoice_item_id' => $itemB], ['id' => (string) $assetId]);
        self::assertSame(404, $upd['status'], 'PUT /assets/{id} nesmí být obchvat kolem téže kontroly.');
        self::assertSame($itemA, $this->scalar('SELECT purchase_invoice_item_id FROM assets WHERE id = ?', [$assetId]));
    }

    /**
     * NOVÝ NÁLEZ — `assets.sale_invoice_id` šel přepsat přes PUT /assets/{id}.
     *
     * Je to lifecycle pole (píše ho jen dispose()/revertDisposal()), ale ve výčtu
     * `unset($data['status'], $data['disposal_*'])` chybělo. Přes normalize() nad
     * `SELECT *` se dostalo do whitelistu repository úplně bez kontroly vlastnictví.
     */
    public function testAssetUpdateCannotPlantForeignSaleInvoice(): void
    {
        $invoiceB = $this->salesInvoice($this->supplierB, self::CANARY . ' faktura');

        $action = $this->container->get(AssetAction::class);
        $created = $this->call($action, 'create', 'POST', $this->assetPayload('M-IDOR-5'));
        self::assertSame(201, $created['status']);
        $assetId = (int) $created['body']['asset']['id'];

        $res = $this->call($action, 'update', 'PUT', [
            'name' => 'Pokus o podvržení prodeje',
            'sale_invoice_id' => $invoiceB,
        ], ['id' => (string) $assetId]);

        // Pole se ignoruje (zbytek patche projde) — ale do DB se cizí id dostat NESMÍ.
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertNull(
            $this->scalar('SELECT sale_invoice_id FROM assets WHERE id = ?', [$assetId]),
            'Cizí sale_invoice_id se nesmí uložit ani jako „jen" trvalý zápis.',
        );
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM assets WHERE supplier_id = ? AND sale_invoice_id = ?',
            [$this->supplierA, $invoiceB],
        ));
    }

    // ── obrana: requireActiveRegister() ───────────────────────────────────────

    /** Sweep S046 — POST /api/accounting/cash-documents s cizím `register_id`. */
    public function testCashDocumentRejectsForeignRegister(): void
    {
        $registerA = $this->cashRegister($this->supplierA, 'Vlastní pokladna A');
        $registerB = $this->cashRegister($this->supplierB, self::CANARY . ' pokladna');

        $action = $this->container->get(CashDocumentAction::class);
        $body = [
            'doc_type' => 'in', 'purpose' => 'sale', 'issue_date' => self::YEAR . '-03-01',
            'description' => 'idor-regrese', 'total_amount' => 500, 'post' => false,
        ];

        $res = $this->call($action, 'create', 'POST', ['register_id' => $registerB] + $body);
        self::assertSame(404, $res['status'], 'Cizí register_id musí skončit 4xx, ne 201.');
        self::assertSame('cash.error.register_not_found', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM cash_documents WHERE supplier_id = ? AND register_id = ?',
            [$this->supplierA, $registerB],
        ), 'Doklad v cizí pokladně nesmí v DB zůstat.');

        $ok = $this->call($action, 'create', 'POST', ['register_id' => $registerA] + $body);
        self::assertSame(201, $ok['status'], 'Vlastní register_id obrana blokovat nesmí.');
        self::assertSame($registerA, $this->scalar(
            'SELECT register_id FROM cash_documents WHERE id = ?',
            [(int) $ok['body']['id']],
        ));
    }

    // ── obrana: find($supplierId, $id) ────────────────────────────────────────

    /** Sweep S026 — POST /api/accounting/accounts s cizím `parent_id`. */
    public function testChartOfAccountsRejectsForeignParent(): void
    {
        $parentA = $this->syntheticAccount($this->supplierA, '901', 'Vlastní syntetika A');
        $parentB = $this->syntheticAccount($this->supplierB, '902', self::CANARY . ' syntetika');

        $action = $this->container->get(ChartOfAccountsAction::class);

        $res = $this->call($action, 'create', 'POST', [
            'parent_id' => $parentB, 'account_code' => '902.1', 'name' => 'idor-regrese',
        ]);
        self::assertSame(404, $res['status'], 'Cizí parent_id musí skončit 4xx, ne 201.');
        self::assertSame('not_found', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = ? AND parent_id = ?',
            [$this->supplierA, $parentB],
        ), 'Analytika pod cizí syntetikou nesmí v DB zůstat.');

        $ok = $this->call($action, 'create', 'POST', [
            'parent_id' => $parentA, 'account_code' => '901.1', 'name' => 'idor-regrese-ok',
        ]);
        self::assertSame(201, $ok['status'], 'Vlastní parent_id obrana blokovat nesmí.');
        self::assertSame($parentA, (int) ($ok['body']['parent_id'] ?? 0));
    }

    // ── obrana: lockInvoice($supplierId, $docId) ──────────────────────────────

    /**
     * Sweep S041 — POST /api/accounting/settlements s cizím `doc_id`.
     *
     * Nejdražší cíl celé skupiny: úspěšný zápočet zapisuje ÚHRADU na doklad
     * (`recordPayment` / `setStatus('paid')`). Cizí `doc_id` tedy nesmí projít nejen kvůli
     * úniku, ale hlavně proto, že by cizí firmě označil fakturu za zaplacenou. Read-back
     * proto kontroluje i `paid_total` oběti, ne jen odpověď.
     */
    public function testSettlementRejectsForeignDocument(): void
    {
        $accountA = $this->syntheticAccount($this->supplierA, '903', 'Zápočtový protiúčet A');
        $invoiceA = $this->salesInvoice($this->supplierA, 'Vlastní odběratel A');
        $invoiceB = $this->salesInvoice($this->supplierB, self::CANARY . ' odběratel');

        $action = $this->container->get(InvoiceSettlementAction::class);
        $body = [
            'doc_type' => 'invoice', 'settled_on' => self::YEAR . '-05-01',
            'amount' => 100, 'account_id' => $accountA, 'note' => 'idor-regrese',
        ];

        $res = $this->call($action, 'create', 'POST', ['doc_id' => $invoiceB] + $body);
        self::assertSame(404, $res['status'], 'Cizí doc_id musí skončit 4xx, ne 200.');
        self::assertSame('doc_not_found', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            "SELECT COUNT(*) FROM invoice_settlements WHERE doc_type = 'invoice' AND doc_id = ?",
            [$invoiceB],
        ), 'Zápočet na cizí doklad nesmí v DB zůstat.');
        self::assertSame(0, $this->rowCount(
            'SELECT CAST(paid_total AS SIGNED) FROM invoices WHERE id = ?',
            [$invoiceB],
        ), 'Cizí faktura nesmí být označena za částečně uhrazenou.');

        // Pozitivní kontrola: vlastní doklad se přes vlastnickou bránu dostane.
        // (Dál už rozhoduje účetní logika — testuje se tenant hranice, ne posting engine.)
        $ok = $this->call($action, 'create', 'POST', ['doc_id' => $invoiceA] + $body);
        self::assertNotSame(
            'doc_not_found',
            $ok['body']['error']['code'] ?? null,
            'Vlastní doc_id obrana blokovat nesmí.',
        );
    }

    // ── infrastruktura ────────────────────────────────────────────────────────

    /**
     * Volá Action metodu jménem firmy A, rolí `accountant`.
     *
     * @param array<string,mixed>  $body
     * @param array<string,string> $args
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(object $action, string $method, string $httpMethod, array $body, array $args = []): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);

        /** @var ResponseInterface $response */
        $response = $args === []
            ? $action->{$method}($request, new Psr7Response())
            : $action->{$method}($request, new Psr7Response(), $args);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** @param array<string,mixed> $body */
    private function json(array $body): string
    {
        return (string) json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    /** @param list<int|string> $params */
    private function rowCount(string $sql, array $params): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @param list<int|string> $params */
    private function scalar(string $sql, array $params): ?int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return ($value === false || $value === null) ? null : (int) $value;
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function assetPayload(string $inventoryNumber, array $overrides = []): array
    {
        return array_merge([
            'inventory_number' => $inventoryNumber,
            'name' => 'Majetek ' . $inventoryNumber,
            'input_price' => 500000.00,
            'acquisition_date' => self::YEAR . '-01-10',
            'tax_method' => 'straight',
            'tax_group' => 2,
            'acc_useful_life_months' => 60,
        ], $overrides);
    }

    private function client(int $supplierId, string $companyName): int
    {
        $this->pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Testovací 1", "Praha", "11000", ?, ?, 0, 1)'
        )->execute([$supplierId, $companyName, $this->countryId, $this->currencyId]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{0:int, 1:int} [purchase_invoice_id, purchase_invoice_item_id] */
    private function purchaseInvoice(int $supplierId, string $number, string $itemDescription): array
    {
        $vendorId = $this->client($supplierId, 'Dodavatel ' . $number);
        $date = self::YEAR . '-01-10';
        $this->pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, is_fixed_asset, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", 10000, 2100, 12100, "received", "40", "full", 1, ?)'
        )->execute([$supplierId, $vendorId, $number, $date, $date, $date, $date, $this->currencyId, $this->userId]);
        $invoiceId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, is_fixed_asset, order_index)
             VALUES (?, ?, 1, "ks", 10000, ?, 21, 10000, 2100, 12100, 1, 0)'
        )->execute([$invoiceId, $itemDescription, $this->vatRateId]);

        return [$invoiceId, (int) $this->pdo->lastInsertId()];
    }

    private function salesInvoice(int $supplierId, string $note): int
    {
        $clientId = $this->client($supplierId, $note);
        $date = self::YEAR . '-04-01';
        $this->pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, varsymbol, invoice_type, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_vat, total_with_vat, paid_total)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, "issued", 1000, 210, 1210, 0)'
        )->execute([$supplierId, $clientId, '99' . $supplierId . '0001', $date, $date, $date, $this->currencyId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function cashRegister(int $supplierId, string $name): int
    {
        $this->pdo->prepare(
            'INSERT INTO cash_registers (supplier_id, name, currency_code, account_code, is_default, is_active)
             VALUES (?, ?, "CZK", "211", 0, 1)'
        )->execute([$supplierId, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Vlastní syntetika mimo seedovanou osnovu — kanárek v názvu tak zůstane náš. */
    private function syntheticAccount(int $supplierId, string $code, string $name): int
    {
        $this->pdo->prepare(
            "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
             VALUES (?, ?, ?, 'expense', 'debit', 1, 1)"
        )->execute([$supplierId, $code, $name]);

        return (int) $this->pdo->lastInsertId();
    }
}
