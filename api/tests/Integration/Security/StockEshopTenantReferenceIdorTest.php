<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Security;

use MyInvoice\Action\Eshop\CategoryAction;
use MyInvoice\Action\Eshop\FeeTypeAction;
use MyInvoice\Action\Eshop\ProductVendorAction;
use MyInvoice\Action\Stock\StockDocumentAction;
use MyInvoice\Action\Stock\StockItemAction;
use MyInvoice\Action\Stock\StockReceiptAction;
use MyInvoice\Action\Stock\StockTakeAction;
use MyInvoice\Bootstrap;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
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
 * z těla requestu, VĚTEV /api/stock/* a /api/eshop/* (sweep sites S100–S109).
 *
 * ## Proč vlastní soubor
 *
 * Reporter tuhle desítku projel POUZE čtením kódu: na jeho instanci měly obě firmy
 * `supplier.stock_enabled = 0`, takže {@see \MyInvoice\Action\Stock\GuardsStockEnabled}
 * vracel 403 `stock_disabled` dřív, než se cokoli vyhodnotilo. U nás je modul zapnutý,
 * takže to omezení neplatí a útoky se pouštějí ŽIVĚ přes Action vrstvu.
 *
 * Pozn.: `stock_enabled` je feature gate nad VLASTNÍ firmou volajícího, ne tenant
 * hranice — nesmí se používat jako důkaz nedosažitelnosti.
 *
 * ## Co každý test dělá
 *
 * Útočník je `accountant` firmy A; do těla dosadí `*_id` firmy B. Očekává se 4xx,
 * v těle odpovědi NIC z firmy B (kanárek `TENANT B SECRET`) a zpětné přečtení DB,
 * že nevznikl trvalý cross-tenant zápis. Ke každému útoku je pozitivní kontrola
 * s VLASTNÍM id, jinak by test zezelenal i tehdy, kdyby obrana blokovala vše.
 *
 * Pokryté skupiny obran (jedna reprezentace na skupinu, viz sweep §1):
 *   - `warehouses->find($supplierId,$id)`      → S102 hlavička, S103 inventura
 *   - `StockDocumentService::itemsMeta()`      → S102 řádky (`stock_item_id`)
 *   - `StockCategoryRepository::find()`        → S106 create, S108 move (`parent_id`)
 *   - kategorie z URL + `i18n->upsert($sid,…)` → S107 (žádný FK z těla)
 *   - `filterOwnedVendors()`                   → S109 (`client_id`)
 *   - globální `vat_rates` (bez `supplier_id`) → S100/S101/S104/S105 (`vat_rate_id`)
 *
 * A dva NOVÉ nálezy, které sweep minul (extrakce viděla jen Action vrstvu, kde je
 * z celého S102 vidět jediné `document_id`; skutečné FK se konzumují až ve službě):
 *   - S102 zapisoval `invoice_id`, `purchase_invoice_id`, `stock_take_id` a per-line
 *     `invoice_item_id` / `purchase_invoice_item_id` NEVÁZANĚ — a to na create i update.
 *     Sweep to v §3 sám přiznává, ale v tabulce §1 má u S102 verdikt „guarded";
 *   - S020 (`POST /api/purchase-invoices/{id}/stock-receipt`, v tabulce rovněž „guarded")
 *     ověřoval jen `lines`; `landed_costs[].purchase_invoice_id` a
 *     `landed_costs[].purchase_invoice_item_id` šly do DB bez kontroly vlastnictví.
 *
 * Vše běží v jedné transakci, kterou tearDown zahodí.
 */
#[Group('integration')]
final class StockEshopTenantReferenceIdorTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const CANARY = 'TENANT B SECRET';
    private const DOC_DATE = '2098-06-15';

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

        // Firma B je plný klon A. Modul musí být zapnutý u OBOU — jinak by útok
        // skončil na 403 `stock_disabled` a test by netestoval nic.
        $this->supplierB = $this->createIsolatedSupplier($this->pdo, $this->supplierA);
        $this->pdo->prepare('UPDATE supplier SET stock_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierA, $this->supplierB]);
        // Jazyk, ve kterém testy zapisují i18n, musí být v číselníku (1370) —
        // jinak by zápis skončil 400 `unknown_locale` a test by neověřil scope.
        $this->pdo->prepare(
            "INSERT IGNORE INTO stock_locales (supplier_id, code, name, is_default)
             VALUES (?, 'en', 'English', 1), (?, 'en', 'English', 1)"
        )->execute([$this->supplierA, $this->supplierB]);
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

    // ── obrana: warehouses->find($supplierId, $id) ────────────────────────────

    /** Sweep S102 — POST /api/stock/documents s cizím `warehouse_id`. */
    public function testStockDocumentRejectsForeignWarehouse(): void
    {
        $warehouseA = $this->warehouse($this->supplierA, 'WH-A1', 'Vlastní sklad A');
        $warehouseB = $this->warehouse($this->supplierB, 'WH-B1', self::CANARY . ' sklad');
        $itemA      = $this->stockItem($this->supplierA, 'SKU-A1', 'Vlastní karta A');

        $action = $this->container->get(StockDocumentAction::class);

        $res = $this->call($action, 'create', 'POST', $this->docPayload($warehouseB, $itemA));
        self::assertSame(422, $res['status'], 'Cizí warehouse_id musí skončit 4xx, ne 201.');
        self::assertSame('stock.error.invalid_document', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM stock_documents WHERE supplier_id = ? AND warehouse_id = ?',
            [$this->supplierA, $warehouseB],
        ), 'Doklad na cizím skladu nesmí v DB zůstat.');

        $ok = $this->call($action, 'create', 'POST', $this->docPayload($warehouseA, $itemA));
        self::assertSame(201, $ok['status'], 'Vlastní warehouse_id obrana blokovat nesmí.');
        self::assertSame($warehouseA, (int) ($ok['body']['warehouse_id'] ?? 0));
    }

    /** Sweep S103 — POST /api/stock/takes s cizím `warehouse_id`. */
    public function testStockTakeRejectsForeignWarehouse(): void
    {
        $warehouseA = $this->warehouse($this->supplierA, 'WH-A2', 'Vlastní sklad A');
        $warehouseB = $this->warehouse($this->supplierB, 'WH-B2', self::CANARY . ' sklad');

        $action = $this->container->get(StockTakeAction::class);
        $body = [
            'take_date' => self::DOC_DATE,
            'counting_method' => 'physical_count',
            'responsible_count_name' => 'Osoba A',
            'responsible_inventory_name' => 'Osoba B',
        ];

        $res = $this->call($action, 'create', 'POST', ['warehouse_id' => $warehouseB] + $body);
        self::assertSame(422, $res['status'], 'Cizí warehouse_id musí skončit 4xx, ne 201.');
        self::assertSame('stock.error.invalid_document', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM stock_takes WHERE supplier_id = ? AND warehouse_id = ?',
            [$this->supplierA, $warehouseB],
        ), 'Inventura na cizím skladu nesmí v DB zůstat.');

        $ok = $this->call($action, 'create', 'POST', ['warehouse_id' => $warehouseA] + $body);
        self::assertSame(201, $ok['status'], 'Vlastní warehouse_id obrana blokovat nesmí.');
        self::assertSame($warehouseA, (int) ($ok['body']['warehouse_id'] ?? 0));
    }

    // ── obrana: StockDocumentService::itemsMeta() ─────────────────────────────

    /** Sweep S102 — POST /api/stock/documents s cizím `lines[].stock_item_id`. */
    public function testStockDocumentRejectsForeignStockItem(): void
    {
        $warehouseA = $this->warehouse($this->supplierA, 'WH-A3', 'Vlastní sklad A');
        $itemA      = $this->stockItem($this->supplierA, 'SKU-A3', 'Vlastní karta A');
        $itemB      = $this->stockItem($this->supplierB, 'SKU-B3', self::CANARY . ' karta');

        $action = $this->container->get(StockDocumentAction::class);

        $res = $this->call($action, 'create', 'POST', $this->docPayload($warehouseA, $itemB));
        self::assertSame(422, $res['status'], 'Cizí stock_item_id musí skončit 4xx, ne 201.');
        self::assertSame('stock.error.invalid_document', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM stock_document_lines WHERE supplier_id = ? AND stock_item_id = ?',
            [$this->supplierA, $itemB],
        ), 'Řádek s cizí kartou nesmí v DB zůstat.');

        $ok = $this->call($action, 'create', 'POST', $this->docPayload($warehouseA, $itemA));
        self::assertSame(201, $ok['status'], 'Vlastní stock_item_id obrana blokovat nesmí.');
        self::assertSame($itemA, (int) ($ok['body']['lines'][0]['stock_item_id'] ?? 0));
    }

    // ── NOVÝ NÁLEZ: dokladové vazby skladu (S102 §3) ──────────────────────────

    /**
     * NOVÝ NÁLEZ — `invoice_id`, `purchase_invoice_id`, `stock_take_id` a per-line
     * `invoice_item_id` / `purchase_invoice_item_id` se ukládaly nevázané.
     *
     * Sweep to obhajoval tím, že „každý čtenář vrací syrová id a každý dotaz nad
     * `stock_documents` joinuje s `AND d.supplier_id = l.supplier_id`". To dnes
     * platí (ověřeno nezávisle na všech čtecích cestách: repository, PDF, XLSX,
     * sestavy, uzávěrková ocenění zásob i párování „zbývá přijmout"), takže únik
     * z toho nebyl. TRVALÝ cross-tenant zápis ale ano — a ten se stane únikem
     * první den, kdy na některý ten sloupec někdo napíše JOIN. Navíc `fk_sd_invoice`
     * a `fk_sd_pinvoice` jsou `ON DELETE SET NULL`, takže smazání dokladu u oběti
     * tiše přepisuje řádek útočníka; vazba je reálná, ne jen kosmetická.
     */
    public function testStockDocumentRejectsForeignDocumentReferences(): void
    {
        $warehouseA = $this->warehouse($this->supplierA, 'WH-A4', 'Vlastní sklad A');
        $itemA      = $this->stockItem($this->supplierA, 'SKU-A4', 'Vlastní karta A');

        [$invoiceA, $invoiceItemA] = $this->salesInvoice($this->supplierA, 'Vlastní odběratel A', '9840001');
        [$invoiceB, $invoiceItemB] = $this->salesInvoice($this->supplierB, self::CANARY . ' odběratel', '9840002');
        [$piA, $piItemA] = $this->purchaseInvoice($this->supplierA, 'PF-A4', 'Vlastní položka A');
        [$piB, $piItemB] = $this->purchaseInvoice($this->supplierB, 'PF-B4', self::CANARY . ' položka');
        $takeA = $this->stockTake($this->supplierA, $warehouseA);
        $takeB = $this->stockTake($this->supplierB, $this->warehouse($this->supplierB, 'WH-B4', self::CANARY . ' sklad'));

        $action = $this->container->get(StockDocumentAction::class);

        $attacks = [
            'invoice_id'               => ['header' => ['invoice_id' => $invoiceB]],
            'purchase_invoice_id'      => ['header' => ['purchase_invoice_id' => $piB]],
            'stock_take_id'            => ['header' => ['stock_take_id' => $takeB]],
            'invoice_item_id'          => ['line'   => ['invoice_item_id' => $invoiceItemB]],
            'purchase_invoice_item_id' => ['line'   => ['purchase_invoice_item_id' => $piItemB]],
        ];
        foreach ($attacks as $column => $spec) {
            $payload = $this->docPayload($warehouseA, $itemA, $spec['header'] ?? [], $spec['line'] ?? []);
            $res = $this->call($action, 'create', 'POST', $payload);

            self::assertSame(422, $res['status'], "Cizí {$column} musí skončit 4xx, ne 201.");
            self::assertSame('stock.error.invalid_reference', $res['body']['error']['code'] ?? null, "Sloupec {$column}");
            self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        }

        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM stock_documents WHERE supplier_id = ?
               AND (invoice_id = ? OR purchase_invoice_id = ? OR stock_take_id = ?)',
            [$this->supplierA, $invoiceB, $piB, $takeB],
        ), 'Trvalý cross-tenant zápis do hlavičky je nález i bez úniku v odpovědi.');
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM stock_document_lines WHERE supplier_id = ?
               AND (invoice_item_id = ? OR purchase_invoice_item_id = ?)',
            [$this->supplierA, $invoiceItemB, $piItemB],
        ), 'Totéž na řádku dokladu.');

        // Pozitivní kontrola — všechny vlastní vazby naráz projdou a uloží se.
        $ok = $this->call($action, 'create', 'POST', $this->docPayload(
            $warehouseA,
            $itemA,
            ['invoice_id' => $invoiceA, 'purchase_invoice_id' => $piA, 'stock_take_id' => $takeA],
            ['invoice_item_id' => $invoiceItemA, 'purchase_invoice_item_id' => $piItemA],
        ));
        self::assertSame(201, $ok['status'], 'Vlastní vazby obrana blokovat nesmí.');
        self::assertSame($invoiceA, (int) ($ok['body']['invoice_id'] ?? 0));
        self::assertSame($piA, (int) ($ok['body']['purchase_invoice_id'] ?? 0));
        self::assertSame($takeA, (int) ($ok['body']['stock_take_id'] ?? 0));
        self::assertSame($invoiceItemA, (int) ($ok['body']['lines'][0]['invoice_item_id'] ?? 0));
        self::assertSame($piItemA, (int) ($ok['body']['lines'][0]['purchase_invoice_item_id'] ?? 0));

        // A totéž na update() — validateBody je společná, ale PUT je druhá,
        // samostatná cesta k týmž sloupcům (a sweep ji vůbec nemá jako site).
        $docId = (int) $ok['body']['id'];
        $upd = $this->call(
            $action,
            'update',
            'PUT',
            $this->docPayload($warehouseA, $itemA, ['purchase_invoice_id' => $piB]),
            ['id' => (string) $docId],
        );
        self::assertSame(422, $upd['status'], 'PUT /stock/documents/{id} nesmí být obchvat kolem téže kontroly.');
        self::assertSame('stock.error.invalid_reference', $upd['body']['error']['code'] ?? null);
        self::assertSame($piA, $this->scalar('SELECT purchase_invoice_id FROM stock_documents WHERE id = ?', [$docId]));
    }

    /**
     * NOVÝ NÁLEZ — `landed_costs[].purchase_invoice_id` / `.purchase_invoice_item_id`
     * u příjemky z PF (sweep S020, v tabulce „guarded").
     *
     * Citovaná obrana (`StockReceiptService.php:173`) váže `lines`, ne vedlejší
     * náklady — ty se zapisovaly rovnou z těla requestu.
     */
    public function testStockReceiptRejectsForeignLandedCostReferences(): void
    {
        $warehouseA = $this->warehouse($this->supplierA, 'WH-A5', 'Vlastní sklad A');
        $itemA      = $this->stockItem($this->supplierA, 'SKU-A5', 'Vlastní karta A');
        [$piA, $piItemA] = $this->purchaseInvoice($this->supplierA, 'PF-A5', 'Vlastní položka A', $itemA);
        [$piB, $piItemB] = $this->purchaseInvoice($this->supplierB, 'PF-B5', self::CANARY . ' položka');

        $action = $this->container->get(StockReceiptAction::class);
        $body = [
            'warehouse_id' => $warehouseA,
            'doc_date'     => self::DOC_DATE,
            'description'  => 'idor-regrese příjemka',
            'lines'        => [['purchase_invoice_item_id' => $piItemA, 'stock_item_id' => $itemA, 'quantity' => 1]],
        ];

        $res = $this->call($action, 'create', 'POST', $body + [
            'landed_costs' => [[
                'amount' => 100, 'allocation' => 'by_value', 'description' => 'Doprava',
                'purchase_invoice_id' => $piB, 'purchase_invoice_item_id' => $piItemB,
            ]],
        ], ['id' => (string) $piA]);

        self::assertSame(422, $res['status'], 'Cizí vazba vedlejšího nákladu musí skončit 4xx, ne 201.');
        self::assertSame('stock.error.invalid_reference', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM stock_landed_costs WHERE supplier_id = ?
               AND (purchase_invoice_id = ? OR purchase_invoice_item_id = ?)',
            [$this->supplierA, $piB, $piItemB],
        ), 'Vedlejší náklad s cizí vazbou nesmí v DB zůstat.');

        $ok = $this->call($action, 'create', 'POST', $body + [
            'landed_costs' => [[
                'amount' => 100, 'allocation' => 'by_value', 'description' => 'Doprava',
                'purchase_invoice_id' => $piA, 'purchase_invoice_item_id' => $piItemA,
            ]],
        ], ['id' => (string) $piA]);
        self::assertSame(201, $ok['status'], 'Vlastní vazba vedlejšího nákladu blokovat nesmí.');
        self::assertSame(1, $this->rowCount(
            'SELECT COUNT(*) FROM stock_landed_costs WHERE supplier_id = ? AND purchase_invoice_item_id = ?',
            [$this->supplierA, $piItemA],
        ));
    }

    // ── obrana: StockCategoryRepository::find($supplierId, $id) ───────────────

    /** Sweep S106 + S108 — POST /api/eshop/categories a /{id}/move s cizím `parent_id`. */
    public function testEshopCategoryRejectsForeignParent(): void
    {
        $parentA = $this->category($this->supplierA, 'CAT-A', 'Vlastní kategorie A');
        $parentB = $this->category($this->supplierB, 'CAT-B', self::CANARY . ' kategorie');

        $action = $this->container->get(CategoryAction::class);

        $res = $this->call($action, 'create', 'POST', [
            'parent_id' => $parentB, 'code' => 'CAT-IDOR-1', 'name' => 'idor-regrese',
        ]);
        self::assertSame(422, $res['status'], 'Cizí parent_id musí skončit 4xx, ne 201.');
        self::assertSame('parent_not_found', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM stock_categories WHERE supplier_id = ? AND parent_id = ?',
            [$this->supplierA, $parentB],
        ), 'Podkategorie pod cizím rodičem nesmí v DB zůstat.');

        $ok = $this->call($action, 'create', 'POST', [
            'parent_id' => $parentA, 'code' => 'CAT-IDOR-2', 'name' => 'idor-regrese-ok',
        ]);
        self::assertSame(201, $ok['status'], 'Vlastní parent_id obrana blokovat nesmí.');
        self::assertSame($parentA, (int) ($ok['body']['parent_id'] ?? 0));

        // S108 — move je druhá cesta k témuž parent_id.
        $movedId = (int) $ok['body']['id'];
        $move = $this->call($action, 'move', 'POST', ['parent_id' => $parentB], ['id' => (string) $movedId]);
        self::assertSame(422, $move['status'], 'Přesun pod cizí kategorii musí skončit 4xx.');
        self::assertSame('parent_not_found', $move['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($move['body']));
        self::assertSame(
            $parentA,
            $this->scalar('SELECT parent_id FROM stock_categories WHERE id = ?', [$movedId]),
            'Cizí parent_id se nesmí uložit ani jako „jen" trvalý zápis.',
        );
    }

    /** Sweep S107 — PUT /api/eshop/categories/{id}/i18n na kategorii firmy B. */
    public function testEshopCategoryI18nIsScopedToOwnCategory(): void
    {
        $categoryA = $this->category($this->supplierA, 'CAT-I18N-A', 'Vlastní kategorie A');
        $categoryB = $this->category($this->supplierB, 'CAT-I18N-B', self::CANARY . ' kategorie');

        $action = $this->container->get(CategoryAction::class);
        $body = ['translations' => [['locale' => 'en', 'name' => 'idor-regrese']]];

        $res = $this->call($action, 'putI18n', 'PUT', $body, ['id' => (string) $categoryB]);
        self::assertSame(404, $res['status'], 'Kategorie firmy B musí skončit 404.');
        self::assertSame('not_found', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM stock_category_i18n WHERE category_id = ?',
            [$categoryB],
        ), 'Překlad k cizí kategorii nesmí vzniknout ani pod supplier_id útočníka.');

        $ok = $this->call($action, 'putI18n', 'PUT', $body, ['id' => (string) $categoryA]);
        self::assertSame(200, $ok['status'], 'Vlastní kategorii obrana blokovat nesmí.');
        self::assertSame(1, $this->rowCount(
            'SELECT COUNT(*) FROM stock_category_i18n WHERE supplier_id = ? AND category_id = ?',
            [$this->supplierA, $categoryA],
        ));
    }

    // ── obrana: filterOwnedVendors() ──────────────────────────────────────────

    /** Sweep S109 — PUT /api/eshop/products/{id}/vendors s cizím `client_id`. */
    public function testProductVendorsRejectForeignClient(): void
    {
        $itemA   = $this->stockItem($this->supplierA, 'SKU-A6', 'Vlastní karta A');
        $clientA = $this->client($this->supplierA, 'Vlastní dodavatel A');
        $clientB = $this->client($this->supplierB, self::CANARY . ' dodavatel');

        $action = $this->container->get(ProductVendorAction::class);

        $res = $this->call($action, 'put', 'PUT', [
            'vendors' => [['client_id' => $clientB, 'purchase_price' => 100]],
        ], ['id' => (string) $itemA]);
        self::assertSame(422, $res['status'], 'Cizí client_id musí skončit 4xx, ne 200.');
        self::assertSame('vendor_invalid', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM stock_item_vendors WHERE supplier_id = ? AND client_id = ?',
            [$this->supplierA, $clientB],
        ), 'Cizí dodavatel nesmí v DB zůstat.');

        $ok = $this->call($action, 'put', 'PUT', [
            'vendors' => [['client_id' => $clientA, 'purchase_price' => 100]],
        ], ['id' => (string) $itemA]);
        self::assertSame(200, $ok['status'], 'Vlastní client_id obrana blokovat nesmí.');
        self::assertSame(1, $this->rowCount(
            'SELECT COUNT(*) FROM stock_item_vendors WHERE supplier_id = ? AND client_id = ?',
            [$this->supplierA, $clientA],
        ));
    }

    // ── premisa verdiktu „not-in-class": globální vat_rates ───────────────────

    /**
     * Sweep S100/S101/S104/S105 — `vat_rate_id` míří na GLOBÁLNÍ číselník.
     *
     * Verdikt „not-in-class" stojí a padá s tím, že `vat_rates` nemá `supplier_id`.
     * Kdyby ho někdy dostala (per-firma sazby), stane se ze čtyř „not-in-class"
     * sites rázem tenant hranice — a tenhle test to řekne dřív než útočník.
     * Živá část: TÁŽ sazba je legitimně použitelná oběma firmami, tedy z ní žádné
     * překročení hranice udělat nejde.
     */
    public function testVatRateIsGlobalAndNotATenantReference(): void
    {
        $columns = $this->pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vat_rates'"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotContains('supplier_id', $columns, 'vat_rates dostala supplier_id — vat_rate_id přestal být globální.');
        self::assertArrayNotHasKey(
            'vat_rate_id',
            TenantReferenceGuard::SCOPES,
            'vat_rate_id je v tenant mapě — jeden z těch dvou pohledů na schéma lže.',
        );

        $items = $this->container->get(StockItemAction::class);
        $fees  = $this->container->get(FeeTypeAction::class);

        foreach ([$this->supplierA, $this->supplierB] as $index => $supplierId) {
            $item = $this->call($items, 'create', 'POST', [
                'sku' => 'SKU-VAT-' . $index, 'name' => 'Karta ' . $index, 'vat_rate_id' => $this->vatRateId,
            ], [], $supplierId);
            self::assertSame(201, $item['status'], 'Globální sazbu smí použít každá firma.');
            self::assertSame($this->vatRateId, (int) ($item['body']['vat_rate_id'] ?? 0));

            $fee = $this->call($fees, 'create', 'POST', [
                'code' => 'FEE-VAT-' . $index, 'name' => 'Poplatek ' . $index, 'vat_rate_id' => $this->vatRateId,
            ], [], $supplierId);
            self::assertSame(201, $fee['status'], 'Totéž pro číselník poplatků (S104/S105).');
            self::assertSame($this->vatRateId, (int) ($fee['body']['vat_rate_id'] ?? 0));
        }
    }

    // ── infrastruktura ────────────────────────────────────────────────────────

    /**
     * Volá Action metodu jménem firmy A (nebo $supplierId), rolí `accountant`.
     *
     * @param array<string,mixed>  $body
     * @param array<string,string> $args
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(object $action, string $method, string $httpMethod, array $body, array $args = [], ?int $supplierId = null): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/stock/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId ?? $this->supplierA)
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

    /**
     * Tělo příjemky — jeden řádek, volitelné vazby v hlavičce a na řádku.
     *
     * @param array<string,mixed> $header
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private function docPayload(int $warehouseId, int $stockItemId, array $header = [], array $line = []): array
    {
        return array_merge([
            'doc_type'     => 'receipt',
            'origin'       => 'manual',
            'warehouse_id' => $warehouseId,
            'doc_date'     => self::DOC_DATE,
            'description'  => 'idor-regrese',
            'lines'        => [array_merge([
                'stock_item_id' => $stockItemId,
                'qty'           => 1,
                'unit_cost'     => 100,
            ], $line)],
        ], $header);
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

    private function warehouse(int $supplierId, string $code, string $name): int
    {
        $this->pdo->prepare(
            'INSERT INTO warehouses (supplier_id, code, name, is_default, is_active) VALUES (?, ?, ?, 0, 1)'
        )->execute([$supplierId, $code, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function stockItem(int $supplierId, string $sku, string $name): int
    {
        $this->pdo->prepare(
            "INSERT INTO stock_items (supplier_id, sku, name, item_type, unit, is_active)
             VALUES (?, ?, ?, 'goods', 'ks', 1)"
        )->execute([$supplierId, $sku, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Uzavřená inventura — nesmí blokovat pozdější pohyby (guardNoOpenStockTake). */
    private function stockTake(int $supplierId, int $warehouseId): int
    {
        $this->pdo->prepare(
            "INSERT INTO stock_takes (supplier_id, warehouse_id, take_date, status, counting_method,
                                      responsible_count_name, responsible_inventory_name)
             VALUES (?, ?, ?, 'closed', 'physical_count', 'Osoba A', 'Osoba B')"
        )->execute([$supplierId, $warehouseId, self::DOC_DATE]);

        return (int) $this->pdo->lastInsertId();
    }

    private function category(int $supplierId, string $code, string $name): int
    {
        $this->pdo->prepare(
            "INSERT INTO stock_categories (supplier_id, parent_id, code, name, path, depth)
             VALUES (?, NULL, ?, ?, '/', 0)"
        )->execute([$supplierId, $code, $name]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE stock_categories SET path = ? WHERE id = ?')
            ->execute(['/' . $id . '/', $id]);

        return $id;
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

    /** @return array{0:int, 1:int} [invoice_id, invoice_item_id] */
    private function salesInvoice(int $supplierId, string $clientName, string $varsymbol): array
    {
        $clientId = $this->client($supplierId, $clientName);
        $this->pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, varsymbol, invoice_type, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_vat, total_with_vat, paid_total)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, "issued", 1000, 210, 1210, 0)'
        )->execute([$supplierId, $clientId, $varsymbol, self::DOC_DATE, self::DOC_DATE, self::DOC_DATE, $this->currencyId]);
        $invoiceId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, 1, "ks", 1000, ?, 21, 1000, 210, 1210, 0)'
        )->execute([$invoiceId, 'Položka ' . $varsymbol, $this->vatRateId]);

        return [$invoiceId, (int) $this->pdo->lastInsertId()];
    }

    /** @return array{0:int, 1:int} [purchase_invoice_id, purchase_invoice_item_id] */
    private function purchaseInvoice(int $supplierId, string $number, string $itemDescription, ?int $stockItemId = null): array
    {
        $vendorId = $this->client($supplierId, 'Dodavatel ' . $number);
        $this->pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", 10000, 2100, 12100, "received", "40", "full", ?)'
        )->execute([
            $supplierId, $vendorId, $number, self::DOC_DATE, self::DOC_DATE, self::DOC_DATE,
            self::DOC_DATE, $this->currencyId, $this->userId,
        ]);
        $invoiceId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, stock_item_id, order_index)
             VALUES (?, ?, 1, "ks", 10000, ?, 21, 10000, 2100, 12100, ?, 0)'
        )->execute([$invoiceId, $itemDescription, $this->vatRateId, $stockItemId]);

        return [$invoiceId, (int) $this->pdo->lastInsertId()];
    }
}
