<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Security;

use MyInvoice\Action\Accounting\Assets\AssetAction;
use MyInvoice\Action\Accounting\Assets\AssetLifecycleAction;
use MyInvoice\Action\Accounting\SmallAssetAction;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Action\Report\Section46Action;
use MyInvoice\Action\Report\Section79Action;
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
 * CWE-639 / BOLA na `invoice_id` a `purchase_invoice_id` z TĚLA requestu — pět Action,
 * které rozsvítilo doplnění těch sloupců do {@see \MyInvoice\Http\TenantReferenceGuard::SCOPES}
 * (samostatná revize vyčleněná ze skladového kola, security report 2026-08 §7 „Zbývá").
 *
 * ## Proč tenhle soubor existuje
 *
 * Discovery sken v `ActionTenantReferenceTest` označil pět Action. Revize zjistila, že
 * čtyři z nich vazbu MAJÍ, jen jiným idiomem, než na jaký sken vidí — a jedna ji neměla
 * vůbec. Whitelist v `ALTERNATIVE_GUARDS` je ale jenom tvrzení; tvrzení bez testu se
 * z revize stává pověrou. Každá položka whitelistu má proto tady živý protějšek:
 * dvoutenantní scénář, cizí `*_id` v těle, zpětné přečtení DB.
 *
 *   | Action                    | sloupec                 | obrana                                    |
 *   |---------------------------|-------------------------|-------------------------------------------|
 *   | `AssetLifecycleAction`    | `purchase_invoice_id`   | `AssetService::assertPurchaseInvoiceOwned` |
 *   | `SmallAssetAction`        | `purchase_invoice_id`   | `SmallAssetService::assertSourceBelongsToTenant` |
 *   | `BankStatementAction`     | `invoice_id`            | `SupplierGuard::owns($request, $invoice)` |
 *   | `BankStatementAction`     | `purchase_invoice_id`   | `manualMatchPurchase()` — shoda `supplier_id` |
 *   | `Section46Action`         | `invoice_id`            | `Section46Service::fetchInvoice($supplierId, …)` |
 *   | `Section79Action`         | `purchase_invoice_id`   | **NÁLEZ** — dnes `TenantReferenceGuard`   |
 *   | `Section79Action`         | `asset_id`              | **NÁLEZ** — dnes `TenantReferenceGuard`   |
 *
 * ## Nález
 *
 * `Section79Service::register()` posílal `$links['purchase_invoice_id']` a `$links['asset_id']`
 * rovnou do INSERTu. `vat_registration_corrections` na těch sloupcích deklarovaný FK NEMÁ
 * (jediný constraint je `fk_vrc_supplier`), takže neexistovala ani databázová záchranná síť:
 * uložit šlo cizí i zcela neexistující id. Read-back (`itemsForPeriod()`) ty sloupce
 * neselektuje a nikdo je nejoinuje → únik dat z toho nebyl, ale TRVALÝ cross-tenant zápis
 * ano. Frontend (`web/src/api/reports.ts:779`) obě pole ani neposílá — celý povrch byl
 * dosažitelný jen ručním requestem.
 *
 * Útočník je vždy `accountant` firmy A, do těla dosadí id firmy B. Očekává se 4xx, v těle
 * odpovědi nic z firmy B (kanárek) a v DB žádný řádek s cizím id. Ke každému útoku patří
 * pozitivní kontrola s VLASTNÍM id — bez ní by test zezelenal i tehdy, kdyby obrana
 * blokovala všechno.
 *
 * Vše běží v jedné transakci, kterou tearDown zahodí.
 */
#[Group('integration')]
final class ReportTenantReferenceIdorTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2097;
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

        // Obě firmy v 'double_entry' — jinak GuardsAccountingMode vrátí 403 dřív, než se
        // k FK vůbec dojde, a test by neověřoval nic (viz AccountingTenantReferenceIdorTest).
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

    // ── NÁLEZ: Section79Action ────────────────────────────────────────────────

    /**
     * POST /api/reports/s79 s cizí `purchase_invoice_id`.
     *
     * Bez FK constraintu na sloupci je jediná obrana ta v aplikaci — kdyby zmizela,
     * neexistuje nic, co by cizí id zastavilo.
     */
    public function testSection79RejectsForeignPurchaseInvoice(): void
    {
        [$invoiceA] = $this->purchaseInvoice($this->supplierA, 'PF-A-79', 'Vlastní PF A');
        [$invoiceB] = $this->purchaseInvoice($this->supplierB, 'PF-B-79', self::CANARY . ' PF');

        $action = $this->container->get(Section79Action::class);

        $res = $this->call($action, 'create', 'POST', $this->s79Payload(['purchase_invoice_id' => $invoiceB]));
        self::assertSame(400, $res['status'], 'Cizí purchase_invoice_id musí skončit 4xx, ne 200.');
        self::assertSame('invalid_reference', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM vat_registration_corrections WHERE supplier_id = ? AND purchase_invoice_id = ?',
            [$this->supplierA, $invoiceB],
        ), 'Trvalý cross-tenant zápis je nález i bez úniku v odpovědi.');

        $ok = $this->call($action, 'create', 'POST', $this->s79Payload(['purchase_invoice_id' => $invoiceA]));
        self::assertSame(200, $ok['status'], 'Vlastní purchase_invoice_id obrana blokovat nesmí.');
        self::assertSame($invoiceA, $this->scalar(
            'SELECT purchase_invoice_id FROM vat_registration_corrections WHERE id = ?',
            [(int) ($ok['body']['id'] ?? 0)],
        ));
    }

    /** POST /api/reports/s79 s cizím `asset_id` — tentýž INSERT, druhý nevázaný sloupec. */
    public function testSection79RejectsForeignAsset(): void
    {
        $assetA = $this->assetCard($this->supplierA, 'M-79-A', 'Vlastní majetek A');
        $assetB = $this->assetCard($this->supplierB, 'M-79-B', self::CANARY . ' majetek');

        $action = $this->container->get(Section79Action::class);

        $res = $this->call($action, 'create', 'POST', $this->s79Payload(['asset_id' => $assetB]));
        self::assertSame(400, $res['status'], 'Cizí asset_id musí skončit 4xx, ne 200.');
        self::assertSame('invalid_reference', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM vat_registration_corrections WHERE supplier_id = ? AND asset_id = ?',
            [$this->supplierA, $assetB],
        ), 'Cizí karta majetku se nesmí uložit ani „potichu".');

        $ok = $this->call($action, 'create', 'POST', $this->s79Payload(['asset_id' => $assetA]));
        self::assertSame(200, $ok['status'], 'Vlastní asset_id obrana blokovat nesmí.');
        self::assertSame($assetA, $this->scalar(
            'SELECT asset_id FROM vat_registration_corrections WHERE id = ?',
            [(int) ($ok['body']['id'] ?? 0)],
        ));
    }

    /**
     * Neexistující id musí skončit stejně jako cizí.
     *
     * Sloupce nemají FK, takže bez guardu projde do DB i id, které nikdy neexistovalo —
     * a evidence § 79 pak odkazuje na doklad, který nelze doložit.
     */
    public function testSection79RejectsUnknownPurchaseInvoice(): void
    {
        $action = $this->container->get(Section79Action::class);
        $ghost = 1 + (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM purchase_invoices')->fetchColumn();

        $res = $this->call($action, 'create', 'POST', $this->s79Payload(['purchase_invoice_id' => $ghost]));
        self::assertSame(400, $res['status'], 'Neexistující purchase_invoice_id nesmí projít — sloupec nemá FK.');
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM vat_registration_corrections WHERE supplier_id = ? AND purchase_invoice_id = ?',
            [$this->supplierA, $ghost],
        ));
    }

    // ── whitelist: Section46Service::fetchInvoice($supplierId, …) ─────────────

    /**
     * POST /api/reports/s46/correction s cizí `invoice_id`.
     *
     * Vazba je ve service — `fetchInvoice()` má `supplier_id = ?` v predikátu, takže cizí
     * doklad se ani nenačte. Discovery sken na to nevidí, proto ta položka ve whitelistu
     * a proto tenhle test.
     */
    public function testSection46CorrectionRejectsForeignInvoice(): void
    {
        $invoiceA = $this->salesInvoice($this->supplierA, 'Vlastní odběratel A');
        $invoiceB = $this->salesInvoice($this->supplierB, self::CANARY . ' odběratel');

        $action = $this->container->get(Section46Action::class);
        $body = ['legal_ground' => 'insolvency', 'delivered_on' => self::YEAR . '-06-01'];

        $res = $this->call($action, 'correction', 'POST', ['invoice_id' => $invoiceB] + $body);
        self::assertSame(422, $res['status'], 'Cizí invoice_id musí skončit 4xx.');
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM vat_s46_corrections WHERE invoice_id = ?',
            [$invoiceB],
        ), 'Oprava k cizímu dokladu nesmí v DB zůstat.');

        // Pozitivní kontrola: vlastní doklad projde přes vlastnickou bránu dál. Co s ním
        // udělá § 46 (lhůty, právní důvod), tenhle test neřeší — testuje se tenant hranice.
        $ok = $this->call($action, 'correction', 'POST', ['invoice_id' => $invoiceA] + $body);
        self::assertStringNotContainsString(
            'nenalezen',
            (string) ($ok['body']['error']['message'] ?? ''),
            'Vlastní invoice_id obrana blokovat nesmí.',
        );
    }

    // ── whitelist: AssetService::assertPurchaseInvoiceOwned() ─────────────────

    /** POST /api/accounting/assets/{id}/improvements s cizí `purchase_invoice_id`. */
    public function testAssetImprovementRejectsForeignPurchaseInvoice(): void
    {
        [$invoiceA] = $this->purchaseInvoice($this->supplierA, 'PF-A-TZ', 'Vlastní PF A');
        [$invoiceB] = $this->purchaseInvoice($this->supplierB, 'PF-B-TZ', self::CANARY . ' PF');
        $assetId = $this->assetInUse('M-TZ-1');

        $action = $this->container->get(AssetLifecycleAction::class);
        $body = ['completed_on' => self::YEAR . '-06-01', 'amount' => 120000, 'description' => 'TZ idor-regrese'];
        $args = ['id' => (string) $assetId];

        $res = $this->call($action, 'addImprovement', 'POST', ['purchase_invoice_id' => $invoiceB] + $body, $args);
        self::assertSame(404, $res['status'], 'Cizí purchase_invoice_id musí skončit 4xx, ne 201.');
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM asset_improvements WHERE purchase_invoice_id = ?',
            [$invoiceB],
        ), 'TZ s cizí PF nesmí v DB zůstat.');

        $ok = $this->call($action, 'addImprovement', 'POST', ['purchase_invoice_id' => $invoiceA] + $body, $args);
        self::assertSame(201, $ok['status'], 'Vlastní purchase_invoice_id obrana blokovat nesmí.');
        self::assertSame($invoiceA, $this->scalar(
            'SELECT purchase_invoice_id FROM asset_improvements WHERE asset_id = ?',
            [$assetId],
        ));
    }

    // ── whitelist: SmallAssetService::assertSourceBelongsToTenant() ───────────

    /**
     * POST /api/accounting/small-assets s cizí `purchase_invoice_id`.
     *
     * Sesterský `purchase_invoice_item_id` hlídá už AccountingTenantReferenceIdorTest;
     * tady jde o hlavičkový sloupec, který je v ALTERNATIVE_GUARDS uveden samostatně.
     */
    public function testSmallAssetRejectsForeignPurchaseInvoice(): void
    {
        [$invoiceA] = $this->purchaseInvoice($this->supplierA, 'PF-A-DM', 'Vlastní PF A');
        [$invoiceB] = $this->purchaseInvoice($this->supplierB, 'PF-B-DM', self::CANARY . ' PF');

        $action = $this->container->get(SmallAssetAction::class);
        $body = ['name' => 'Vrtačka', 'acquisition_date' => self::YEAR . '-02-01', 'price' => 9000];

        $res = $this->call($action, 'create', 'POST', ['purchase_invoice_id' => $invoiceB] + $body);
        self::assertSame(422, $res['status'], 'Cizí purchase_invoice_id musí skončit 4xx, ne 201.');
        self::assertSame('source_not_found', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM small_assets WHERE supplier_id = ? AND purchase_invoice_id = ?',
            [$this->supplierA, $invoiceB],
        ), 'Karta s cizí PF nesmí v DB zůstat.');

        $ok = $this->call($action, 'create', 'POST', ['purchase_invoice_id' => $invoiceA] + $body);
        self::assertSame(201, $ok['status'], 'Vlastní purchase_invoice_id obrana blokovat nesmí.');
        self::assertSame($invoiceA, (int) ($ok['body']['card']['purchase_invoice_id'] ?? 0));
    }

    /** POST /api/accounting/small-assets/{id}/sell s cizí `sale_invoice_id`. */
    public function testSmallAssetSellRejectsForeignSaleInvoice(): void
    {
        $invoiceA = $this->salesInvoice($this->supplierA, 'Vlastní odběratel A');
        $invoiceB = $this->salesInvoice($this->supplierB, self::CANARY . ' odběratel');

        $action = $this->container->get(SmallAssetAction::class);
        $created = $this->call($action, 'create', 'POST', [
            'name' => 'Tiskárna', 'acquisition_date' => self::YEAR . '-02-01', 'price' => 4000,
        ]);
        self::assertSame(201, $created['status']);
        $cardId = (int) $created['body']['card']['id'];
        $args = ['id' => (string) $cardId];
        $body = ['sold_at' => self::YEAR . '-07-01', 'sale_price' => 1000];

        $res = $this->call($action, 'sell', 'POST', ['sale_invoice_id' => $invoiceB] + $body, $args);
        self::assertSame(422, $res['status'], 'Cizí sale_invoice_id musí skončit 4xx.');
        self::assertSame('sale_invoice_not_found', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertNull(
            $this->scalar('SELECT sale_invoice_id FROM small_assets WHERE id = ?', [$cardId]),
            'Cizí faktura prodeje se nesmí uložit ani jako „jen" trvalý zápis.',
        );

        $ok = $this->call($action, 'sell', 'POST', ['sale_invoice_id' => $invoiceA] + $body, $args);
        self::assertSame(200, $ok['status'], 'Vlastní sale_invoice_id obrana blokovat nesmí.');
        self::assertSame($invoiceA, $this->scalar('SELECT sale_invoice_id FROM small_assets WHERE id = ?', [$cardId]));
    }

    // ── whitelist: BankStatementAction::manualMatch() ─────────────────────────

    /**
     * POST /api/bank-transactions/{id}/match s cizí `invoice_id`.
     *
     * Zásah by byl dražší než jen zápis: úspěšný match eviduje ÚHRADU a překlápí fakturu
     * na 'paid'. Read-back proto kontroluje i `paid_total` oběti, ne jen odpověď.
     *
     * ## Pozitivní kontrola nedojede až do zápisu — a nesmí
     *
     * `manualMatch()` volá `beginTransaction()` bez testu `inTransaction()`, takže uvnitř
     * obalové transakce testu skončí PDOException. Kontrola proto cílí na první zastávku
     * ZA vlastnickou bránou: transakci předem svážeme platbou na jinou VLASTNÍ fakturu,
     * takže vlastní `invoice_id` narazí na 409 `tx_already_paired` (řádek ~2501) místo
     * 404 `invoice_not_found` (řádek ~2466). Rozdíl obou kódů je přesně to, co dokazuje,
     * že obrana nefiltruje všechno bez rozdílu.
     */
    public function testBankManualMatchRejectsForeignInvoice(): void
    {
        $invoiceA = $this->salesInvoice($this->supplierA, 'Vlastní odběratel A');
        $otherA   = $this->salesInvoice($this->supplierA, 'Jiný vlastní odběratel A');
        $invoiceB = $this->salesInvoice($this->supplierB, self::CANARY . ' odběratel');
        $txId = $this->bankTransaction($this->supplierA, 1210.00);

        $action = $this->container->get(BankStatementAction::class);
        $args = ['id' => (string) $txId];

        $res = $this->call($action, 'manualMatch', 'POST', ['invoice_id' => $invoiceB], $args);
        self::assertSame(404, $res['status'], 'Cizí invoice_id musí skončit 4xx, ne 200.');
        self::assertSame('invoice_not_found', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertNull(
            $this->scalar('SELECT matched_invoice_id FROM bank_transactions WHERE id = ?', [$txId]),
            'Transakce se nesmí navázat na cizí fakturu.',
        );
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?',
            [$invoiceB],
        ), 'Cizí faktura nesmí dostat úhradu.');
        self::assertSame(0, $this->rowCount(
            'SELECT CAST(paid_total AS SIGNED) FROM invoices WHERE id = ?',
            [$invoiceB],
        ), 'Cizí faktura nesmí být označena za uhrazenou.');

        $this->pdo->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, bank_transaction_id, amount, paid_on, source)
             VALUES (?, ?, ?, 1210, ?, 'bank')"
        )->execute([$this->supplierA, $otherA, $txId, self::YEAR . '-05-02']);

        $ok = $this->call($action, 'manualMatch', 'POST', ['invoice_id' => $invoiceA], $args);
        self::assertSame('tx_already_paired', $ok['body']['error']['code'] ?? null,
            'Vlastní invoice_id musí projít vlastnickou bránou — zastavit ho smí až věcné pravidlo.');
    }

    /**
     * POST /api/bank-transactions/{id}/match s cizí `purchase_invoice_id` (odchozí platba).
     *
     * Pozitivní kontrola stejnou technikou jako u vystavených faktur: vlastní PF ve stavu
     * 'draft' narazí na 409 `invalid_status` (řádek ~2986) místo 404 `purchase_not_found`
     * (řádek ~2979), tedy AŽ za tenant bránou a ještě před `beginTransaction()`.
     */
    public function testBankManualMatchRejectsForeignPurchaseInvoice(): void
    {
        [$invoiceA] = $this->purchaseInvoice($this->supplierA, 'PF-A-BANK', 'Vlastní PF A');
        [$invoiceB] = $this->purchaseInvoice($this->supplierB, 'PF-B-BANK', self::CANARY . ' PF');
        $txId = $this->bankTransaction($this->supplierA, -12100.00);

        $action = $this->container->get(BankStatementAction::class);
        $args = ['id' => (string) $txId];

        $res = $this->call($action, 'manualMatch', 'POST', ['purchase_invoice_id' => $invoiceB], $args);
        self::assertSame(404, $res['status'], 'Cizí purchase_invoice_id musí skončit 4xx, ne 200.');
        self::assertSame('purchase_not_found', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(self::CANARY, $this->json($res['body']));
        self::assertSame(0, $this->rowCount(
            'SELECT COUNT(*) FROM payment_matches WHERE purchase_invoice_id = ?',
            [$invoiceB],
        ), 'Vazba na cizí přijatou fakturu nesmí v DB zůstat.');
        self::assertSame(0, $this->rowCount(
            "SELECT COUNT(*) FROM purchase_invoices WHERE id = ? AND status = 'paid'",
            [$invoiceB],
        ), 'Cizí přijatá faktura nesmí být překlopena na paid.');

        $this->pdo->prepare("UPDATE purchase_invoices SET status = 'draft' WHERE id = ?")->execute([$invoiceA]);

        $ok = $this->call($action, 'manualMatch', 'POST', ['purchase_invoice_id' => $invoiceA], $args);
        self::assertSame('invalid_status', $ok['body']['error']['code'] ?? null,
            'Vlastní purchase_invoice_id musí projít tenant bránou — zastavit ho smí až stav dokladu.');
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
            ->createServerRequest($httpMethod, '/api/test')
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

    /** @param array<string,mixed> $links @return array<string,mixed> */
    private function s79Payload(array $links = []): array
    {
        return array_merge([
            'kind' => 'registration',
            'label' => 'Zásoby ke dni registrace',
            'acquired_on' => self::YEAR . '-01-15',
            'effective_on' => self::YEAR . '-03-01',
            'asset_kind' => 'inventory',
            'vat_amount' => 2100,
        ], $links);
    }

    private function client(int $supplierId, string $companyName): int
    {
        $this->pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Testovací 1", "Praha", "11000", ?, ?, 1, 1)'
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
                 total_without_vat, total_vat, total_with_vat, amount_to_pay, status,
                 vat_classification_code, vat_deduction, is_fixed_asset, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", 10000, 2100, 12100, 12100, "received", "40", "full", 1, ?)'
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
                 currency_id, status, total_without_vat, total_vat, total_with_vat, amount_to_pay, paid_total)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, "issued", 1000, 210, 1210, 1210, 0)'
        )->execute([$supplierId, $clientId, '97' . $supplierId . '0' . random_int(100, 999), $date, $date, $date, $this->currencyId]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Karta majetku vloženými daty — Section79 na ni jen odkazuje, nepotřebuje lifecycle. */
    private function assetCard(int $supplierId, string $inventoryNumber, string $name): int
    {
        $this->pdo->prepare(
            "INSERT INTO assets
                (supplier_id, inventory_number, name, input_price, acquisition_date,
                 tax_method, tax_group, acc_useful_life_months, status, created_by)
             VALUES (?, ?, ?, 500000, ?, 'straight', 2, 60, 'acquired', ?)"
        )->execute([$supplierId, $inventoryNumber, $name, self::YEAR . '-01-10', $this->userId]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Karta firmy A zařazená do užívání — TZ jde přidat jen k majetku v užívání. */
    private function assetInUse(string $inventoryNumber): int
    {
        $created = $this->call($this->container->get(AssetAction::class), 'create', 'POST', [
            'inventory_number' => $inventoryNumber,
            'name' => 'Majetek ' . $inventoryNumber,
            'input_price' => 500000.00,
            'acquisition_date' => self::YEAR . '-01-10',
            'tax_method' => 'straight',
            'tax_group' => 2,
            'acc_useful_life_months' => 60,
        ]);
        self::assertSame(201, $created['status'], 'Příprava: kartu majetku se nepodařilo založit.');
        $assetId = (int) $created['body']['asset']['id'];

        $putIntoUse = $this->call($this->container->get(AssetLifecycleAction::class), 'putIntoUse', 'POST', [
            'date' => self::YEAR . '-01-31',
            'book_entry' => false,
        ], ['id' => (string) $assetId]);
        self::assertSame(200, $putIntoUse['status'], 'Příprava: majetek se nepodařilo zařadit do užívání.');

        return $assetId;
    }

    /** Nespárovaná transakce na výpisu firmy A (`supplier_id` vyplněný → jednoznačné vlastnictví). */
    private function bankTransaction(int $supplierId, float $amount): int
    {
        $this->pdo->prepare(
            "INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, account_number, currency,
                 statement_date, transaction_count, matched_count)
             VALUES (?, 'gpc', ?, ?, '123456789/0100', 'CZK', ?, 1, 0)"
        )->execute([
            $supplierId,
            'idor-' . $supplierId . '.gpc',
            hash('sha256', 'idor-regrese-' . $supplierId . '-' . random_int(1, PHP_INT_MAX)),
            self::YEAR . '-05-01',
        ]);
        $statementId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, variable_symbol, match_status)
             VALUES (?, 'statement', ?, ?, 'CZK', '', 'unmatched')"
        )->execute([$statementId, self::YEAR . '-05-02', $amount]);

        return (int) $this->pdo->lastInsertId();
    }
}
