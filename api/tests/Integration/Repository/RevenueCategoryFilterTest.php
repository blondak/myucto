<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Repository;

use MyInvoice\Action\Invoice\ListInvoicesAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Filtr seznamu vydaných faktur podle KATEGORIE TRŽBY — include (`revenue_category_id`)
 * i exclude (`revenue_category_exclude`), obojí čárkou oddělený seznam ID + sentinel
 * `none` pro doklad bez kategorie.
 *
 * Zamyká čtyři věci, které se dají snadno pokazit:
 *   • NULL v exclude — `revenue_category_id NOT IN (…)` je u dokladu BEZ kategorie
 *     UNKNOWN, takže by ho naivní exclude vyhodil taky. Doklady bez kategorie musí
 *     zůstat, dokud uživatel nevyloučí i `none`.
 *   • sentinel `none` funguje na OBOU stranách,
 *   • include + exclude se skládají (include vybere, exclude zúží),
 *   • tenant izolace — cizí ID nesmí nic prozradit ani vrátit.
 *
 * Izolace přes filtr client_id na čerstvě založeného klienta → výstup je deterministický.
 * Transakce + rollback, soft-skip bez cfg.php (stejný vzor jako ListGroupedByMonthTest).
 */
#[Group('integration')]
final class RevenueCategoryFilterTest extends TestCase
{
    private Connection $db;
    private InvoiceRepository $invoices;
    private ListInvoicesAction $listAction;
    private PDO $pdo;

    private int $supplierId = 0;
    private int $otherSupplierId = 0;
    private int $czkId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    private int $clientId = 0;
    private int $catConsulting = 0;
    private int $catSubscription = 0;
    private int $foreignCat = 0;
    private int $invConsulting = 0;
    private int $invSubscription = 0;
    private int $invNoCategory = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->invoices = $container->get(InvoiceRepository::class);
            $this->listAction = $container->get(ListInvoicesAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->pdo = $this->db->pdo();

        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->otherSupplierId = (int) ($this->pdo->query(
            "SELECT id FROM supplier WHERE id <> {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->czkId = (int) ($this->pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->otherSupplierId === 0 || $this->userId === 0 || $this->czId === 0 || $this->czkId === 0) {
            $this->markTestSkipped('Chybí supplier (dva) / user / country / CZK.');
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;

        $this->clientId = $this->client('RevCat Klient');
        $this->catConsulting   = $this->category($this->supplierId, 'konzultace');
        $this->catSubscription = $this->category($this->supplierId, 'predplatne');
        $this->foreignCat      = $this->category($this->otherSupplierId, 'cizi');

        $this->invConsulting   = $this->invoice('2004-03-10', 10000.0, $this->catConsulting);
        $this->invSubscription = $this->invoice('2004-03-11', 199.0, $this->catSubscription);
        $this->invNoCategory   = $this->invoice('2004-03-12', 5000.0, null);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->db->close();
        }
    }

    /** Bez filtru vidí uživatel všechno — kontrolní větev pro zbytek testů. */
    public function testWithoutFilterAllInvoicesAreVisible(): void
    {
        self::assertSame(
            $this->sorted([$this->invConsulting, $this->invSubscription, $this->invNoCategory]),
            $this->ids([]),
        );
    }

    public function testIncludeKeepsOnlySelectedCategories(): void
    {
        self::assertSame(
            [$this->invConsulting],
            $this->ids(['revenue_category_id' => (string) $this->catConsulting]),
            'Include jedné kategorie vrátí jen její doklady.',
        );

        self::assertSame(
            $this->sorted([$this->invConsulting, $this->invSubscription]),
            $this->ids(['revenue_category_id' => $this->catConsulting . ',' . $this->catSubscription]),
            'Include je 1:N — čárkou oddělený seznam sjednocuje.',
        );
    }

    /** Sentinel `none` = doklad BEZ kategorie; číselné ID by to vyjádřit nešlo. */
    public function testIncludeNoneSelectsInvoicesWithoutCategory(): void
    {
        self::assertSame(
            [$this->invNoCategory],
            $this->ids(['revenue_category_id' => 'none']),
            'Include `none` vrátí jen doklady bez kategorie.',
        );

        self::assertSame(
            $this->sorted([$this->invConsulting, $this->invNoCategory]),
            $this->ids(['revenue_category_id' => $this->catConsulting . ',none']),
            'Sentinel jde kombinovat s ID.',
        );
    }

    /**
     * Jádro zadání: vyřadit drobné faktury za předplatné. Doklad BEZ kategorie přitom
     * musí zůstat — `NOT IN` by ho na NULL vyhodil, což uživatel nečeká.
     */
    public function testExcludeHidesSelectedCategoriesButKeepsUncategorized(): void
    {
        self::assertSame(
            $this->sorted([$this->invConsulting, $this->invNoCategory]),
            $this->ids(['revenue_category_exclude' => (string) $this->catSubscription]),
            'Exclude skryje vybranou kategorii a doklad bez kategorie ponechá (NULL != vyloučeno).',
        );

        self::assertSame(
            [$this->invNoCategory],
            $this->ids(['revenue_category_exclude' => $this->catConsulting . ',' . $this->catSubscription]),
            'Exclude je 1:N.',
        );
    }

    public function testExcludeNoneHidesInvoicesWithoutCategory(): void
    {
        self::assertSame(
            $this->sorted([$this->invConsulting, $this->invSubscription]),
            $this->ids(['revenue_category_exclude' => 'none']),
            'Exclude `none` skryje doklady bez kategorie.',
        );

        self::assertSame(
            [$this->invConsulting],
            $this->ids(['revenue_category_exclude' => $this->catSubscription . ',none']),
            'Exclude kategorie + `none` skryje obojí.',
        );
    }

    /** Zadané obojí: include vybere množinu, exclude ji zúží. */
    public function testIncludeAndExcludeCombine(): void
    {
        self::assertSame(
            [$this->invConsulting],
            $this->ids([
                'revenue_category_id'      => $this->catConsulting . ',' . $this->catSubscription,
                'revenue_category_exclude' => (string) $this->catSubscription,
            ]),
        );

        self::assertSame(
            [],
            $this->ids([
                'revenue_category_id'      => (string) $this->catSubscription,
                'revenue_category_exclude' => (string) $this->catSubscription,
            ]),
            'Protichůdné zadání = prázdno, ne tiché ignorování jedné strany.',
        );
    }

    /**
     * Tenant izolace. Cizí kategorie se z filtru vyhodí, takže:
     *   • include jen s cizím ID → PRÁZDNO (ne „bez filtru", to by ukázalo všechno),
     *   • cizí ID v include neposune výsledek vlastního ID,
     *   • exclude s cizím ID nevyloučí nic (cizí kategorii žádný náš doklad nemá).
     * Volající tak z odpovědi nepozná, jestli cizí ID vůbec existuje.
     */
    public function testForeignCategoryIdLeaksNothing(): void
    {
        self::assertSame(
            [],
            $this->ids(['revenue_category_id' => (string) $this->foreignCat]),
            'Include cizí kategorie nevrací nic.',
        );

        self::assertSame(
            [$this->invConsulting],
            $this->ids(['revenue_category_id' => $this->foreignCat . ',' . $this->catConsulting]),
            'Cizí ID se z include jen vyhodí, vlastní část filtru platí dál.',
        );

        self::assertSame(
            $this->sorted([$this->invConsulting, $this->invSubscription, $this->invNoCategory]),
            $this->ids(['revenue_category_exclude' => (string) $this->foreignCat]),
            'Exclude cizí kategorie nevyloučí nic.',
        );
    }

    /** Nečíselné vstupy se zahodí; nezbyde-li nic, filtr je no-op (ne prázdný výsledek). */
    public function testNonNumericTokensAreDiscarded(): void
    {
        $all = $this->sorted([$this->invConsulting, $this->invSubscription, $this->invNoCategory]);

        self::assertSame($all, $this->ids(['revenue_category_id' => 'abc, ,-5']));
        self::assertSame($all, $this->ids(['revenue_category_exclude' => 'abc']));
        self::assertSame($all, $this->ids(['revenue_category_id' => '']));

        self::assertSame(
            [$this->invConsulting],
            $this->ids(['revenue_category_id' => 'abc,' . $this->catConsulting]),
            'Smetí vedle platného ID filtr nevypne.',
        );
    }

    /**
     * Akční vrstva: `filter[revenue_category_*]` z query stringu musí dotéct do
     * repository. Bez toho by SQL bylo správně, ale endpoint by filtr tiše zahazoval.
     */
    public function testFiltersReachRepositoryThroughQueryString(): void
    {
        $body = $this->callList(['revenue_category_id' => (string) $this->catConsulting]);
        self::assertSame([$this->invConsulting], $body);

        $body = $this->callList(['revenue_category_exclude' => $this->catSubscription . ',none']);
        self::assertSame([$this->invConsulting], $body);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * @param array<string,string> $filter
     * @return list<int>
     */
    private function callList(array $filter): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/invoices')
            ->withQueryParams(['filter' => array_merge(['client_id' => (string) $this->clientId], $filter)])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);

        $response = ($this->listAction)($request, new Psr7Response());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true) ?: [];

        self::assertSame(200, $response->getStatusCode());

        $ids = [];
        foreach (($decoded['data'] ?? []) as $group) {
            foreach (($group['invoices'] ?? []) as $row) {
                $ids[] = (int) $row['id'];
            }
        }
        sort($ids);
        return $ids;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<int>
     */
    private function ids(array $filters): array
    {
        $res = $this->invoices->listGroupedByMonth(array_merge([
            'supplier_id' => $this->supplierId,
            'client_id'   => $this->clientId,
        ], $filters));

        $ids = [];
        foreach ($res['data'] as $group) {
            foreach ($group['invoices'] as $row) {
                $ids[] = (int) $row['id'];
            }
        }
        sort($ids);
        return $ids;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);
        return $ids;
    }

    private function category(int $supplierId, string $code): int
    {
        $unique = substr($code . uniqid(), 0, 20);
        $this->pdo->prepare(
            'INSERT INTO revenue_categories (supplier_id, code, label, display_order) VALUES (?, ?, ?, 0)'
        )->execute([$supplierId, $unique, $code]);
        return (int) $this->pdo->lastInsertId();
    }

    private function client(string $name): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "revcat@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->czkId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function invoice(string $issueDate, float $net, ?int $categoryId): int
    {
        $vat = round($net * 0.21, 2);
        $stmt = $this->pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, revenue_category_id, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 1.0, 0, ?, ?, ?, "issued", "1", ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, 'RC-' . uniqid(), $this->clientId, $issueDate, $issueDate, $issueDate,
            $this->czkId, $net, $vat, $net + $vat, $categoryId, $this->userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
