<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Currency;

use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\FixedExchangeRateRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Currency\PurchaseInvoiceRateReloader;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Přenačtení kurzu na přijaté faktuře po změně rozhodného dne / měny (migrace 1303).
 *
 * Vada, kterou to zavírá: `PurchaseInvoiceRepository::updateDraft()` zapisovala kurz
 * i jeho datum při KAŽDÉM PUT a editor je posílal vždycky, takže změna DUZP uložila
 * zpátky starý kurz se starým datem.
 *
 * Nejdůležitější je opačná regrese: vědomě zadaný kurz se přenačtením NESMÍ ztratit.
 *
 * Vše běží v jedné transakci, tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class PurchaseInvoiceRateReloadTest extends TestCase
{
    private const OLD_TAX_DATE = '2099-03-10';
    private const NEW_TAX_DATE = '2099-04-20';
    private const ISSUE_DATE   = '2099-03-12';
    private const OLD_RATE     = 24.500000;
    private const CNB_RATE     = 26.750000;

    private ContainerInterface $container;
    private Connection $db;
    private PurchaseInvoiceRepository $repo;

    private int $supplierId = 0;
    private int $eurId = 0;
    private int $czkId = 0;
    private int $vendorId = 0;
    private int $userId = 0;
    private int $vatRateId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->repo = $this->container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $base = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($base === 0 || $this->userId === 0 || $this->vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $sup = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací 1", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        );
        $sup->execute(['Kurz PF test s.r.o.', 'kurz-pf@example.com', $base]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $cur = $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1)'
        );
        $cur->execute([$this->supplierId, 'EUR', 'EUR', '€', 'euro', 'euro']);
        $this->eurId = (int) $pdo->lastInsertId();
        $cur->execute([$this->supplierId, 'CZK', 'CZK', 'Kč', 'koruna', 'koruna']);
        $this->czkId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_vat_payer)
             VALUES (?, "Kurzovy dodavatel s.r.o.", "Test 1", "Praha", "11000", ?, "v@example.com", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $czId, $this->eurId]);
        $this->vendorId = (int) $pdo->lastInsertId();
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

    // ── klíčová regrese: vědomě zadaný kurz se NEPŘEPÍŠE ─────────────────────────────

    /**
     * Kurz, který nevznikl odvozením z data, musí změnu DUZP přežít. Bez opravy sem
     * spadl přepočet z ČNB a účetní by přišla o hodnotu, kterou vědomě zadala nebo
     * kterou nese doklad dodavatele.
     */
    #[DataProvider('protectedSourcesProvider')]
    public function testRateFromStrongerSourceSurvivesTaxDateChange(string $source): void
    {
        $id = $this->eurInvoice($source, self::OLD_RATE, self::OLD_TAX_DATE);

        // Stub, ne `never()` mock: ČNB se po uložení ptá i CnbRateDeviationChecker.
        // Že se na ni NEPTÁ přenačtení, hlídá testProtectedSourceNeverAsksCnb().
        $result = $this->update($id, $this->body(taxDate: self::NEW_TAX_DATE), $this->cnbReturning(self::NEW_TAX_DATE));

        self::assertSame(200, $result['status']);
        $row = $this->rateRow($id);
        self::assertEqualsWithDelta(self::OLD_RATE, (float) $row['exchange_rate'], 1e-6,
            'Kurz ze silnějšího zdroje se automatikou přepsat nesmí.');
        self::assertSame(self::OLD_TAX_DATE, (string) $row['exchange_rate_date']);
        self::assertSame($source, (string) $row['exchange_rate_source']);
        self::assertContains('exchange_rate_not_reloaded', $result['body']['_warnings'] ?? [],
            'Uživatel musí dostat varování, že kurz zůstal na starém datu.');
        self::assertSame(
            'source_locked',
            $result['body']['_warning_meta']['exchange_rate_not_reloaded']['reason'] ?? null,
        );
    }

    /** @return list<array{string}> */
    public static function protectedSourcesProvider(): array
    {
        return [['user'], ['manual'], ['import']];
    }

    public function testCnbRateIsReloadedOnTaxDateChange(): void
    {
        $id = $this->eurInvoice('cnb', self::OLD_RATE, self::OLD_TAX_DATE);

        $result = $this->update($id, $this->body(taxDate: self::NEW_TAX_DATE), $this->cnbReturning(self::NEW_TAX_DATE));

        self::assertSame(200, $result['status']);
        $row = $this->rateRow($id);
        self::assertEqualsWithDelta(self::CNB_RATE, (float) $row['exchange_rate'], 1e-6,
            'Kurz odvozený z data se k novému DUZP přenačte.');
        self::assertSame(self::NEW_TAX_DATE, (string) $row['exchange_rate_date']);
        self::assertSame('cnb', (string) $row['exchange_rate_source']);
        self::assertNotContains('exchange_rate_not_reloaded', $result['body']['_warnings'] ?? []);
    }

    /** Firma v pevném kurzu (§ 24/7) dostane pevný kurz NOVÉHO období, ne ČNB. */
    public function testFixedRateModeUsesFixedRateOfNewPeriod(): void
    {
        $this->container->get(FixedExchangeRateRepository::class)
            ->upsert($this->supplierId, 'EUR', 2099, 4, 27.250000, 'manual');
        $this->container->get(AccountingSupplierSettingsRepository::class)
            ->setFxRateMode($this->supplierId, 'fixed_monthly');

        $id = $this->eurInvoice('fixed', self::OLD_RATE, self::OLD_TAX_DATE);

        $result = $this->update($id, $this->body(taxDate: self::NEW_TAX_DATE), $this->cnbNever());

        self::assertSame(200, $result['status']);
        $row = $this->rateRow($id);
        self::assertEqualsWithDelta(27.25, (float) $row['exchange_rate'], 1e-6,
            'Pevný kurz nového období, ne ČNB ani starý pevný kurz.');
        self::assertSame('2099-04-01', (string) $row['exchange_rate_date']);
        self::assertSame('fixed', (string) $row['exchange_rate_source']);
    }

    // ── trigger je VÝSLEDEK rozhodného data, ne jednotlivá pole ──────────────────────
    //
    // Tyhle tři jdou přímo na PurchaseInvoiceRateReloader, ne přes Action: v Action volá
    // ČNB ještě CnbRateDeviationChecker (kontrola odchylky po uložení), takže by se do
    // `expects(never())` započítalo cizí volání a test by netvrdil to, co má.

    /**
     * Doklad má vyplněné DUZP → rozhodný den se změnou data vystavení NEMĚNÍ.
     * Volání ČNB by tu bylo zbytečné a u zaúčtovaného dokladu i drahé (repost deníku).
     */
    public function testIssueDateChangeWithTaxDateFilledNeverAsksCnb(): void
    {
        $decision = $this->decide(
            $this->cnbNever(),
            $this->body(taxDate: self::OLD_TAX_DATE, issueDate: '2099-03-25'),
            'cnb',
        );

        self::assertSame('unchanged', $decision['reason']);
        self::assertSame([], $decision['apply']);
        self::assertFalse($decision['rate_will_change']);
    }

    /** Změna obou dat najednou = jeden rozhodný den = právě jeden dotaz na ČNB. */
    public function testBothDatesChangedAskCnbExactlyOnce(): void
    {
        $cnb = $this->createMock(CnbExchangeRateClient::class);
        $cnb->expects(self::once())->method('getRate')->willReturn([
            'rate' => self::CNB_RATE, 'rate_date' => self::NEW_TAX_DATE, 'fallback_used' => false, 'source' => 'fresh',
        ]);

        $decision = $this->decide(
            $cnb,
            $this->body(taxDate: self::NEW_TAX_DATE, issueDate: '2099-04-22'),
            'cnb',
        );

        self::assertSame('reloaded', $decision['reason']);
        self::assertEqualsWithDelta(self::CNB_RATE, (float) $decision['apply']['exchange_rate'], 1e-6);
    }

    /**
     * Doplnění CHYBĚJÍCÍHO kurzu je taky změna korunové hodnoty dokladu — reloader to
     * musí ohlásit přes `rate_will_change`, protože podle toho se u zaúčtovaného
     * dokladu ve force-editu rozhoduje o přeúčtování deníku. Requestu tahle změna
     * není vidět (kurz do těla dosadil až server), takže `financialFieldsChanged()`
     * ji sama zachytit nemůže.
     */
    public function testFillingMissingRateReportsRateWillChange(): void
    {
        $this->container->set(CnbExchangeRateClient::class, $this->cnbReturning(self::OLD_TAX_DATE));
        $reloader = $this->container->get(PurchaseInvoiceRateReloader::class);

        $id = $this->eurInvoice('cnb', self::OLD_RATE, self::OLD_TAX_DATE);
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET exchange_rate = NULL, exchange_rate_date = NULL WHERE id = ?'
        )->execute([$id]);
        $existing = $this->repo->find($id, $this->supplierId) ?? [];

        // Editor kurz hydratuje z dokladu, takže u prázdného pošle prázdný.
        $body = $this->body(taxDate: self::OLD_TAX_DATE);
        $body['exchange_rate'] = null;
        $body['exchange_rate_date'] = null;

        $decision = $reloader->resolveForUpdate($this->supplierId, $existing, $body);

        self::assertSame('reloaded', $decision['reason']);
        self::assertTrue($decision['rate_will_change']);
    }

    /** Kurz ze silnějšího zdroje se na ČNB vůbec neptá — rozhodne se dřív. */
    public function testProtectedSourceNeverAsksCnb(): void
    {
        $decision = $this->decide($this->cnbNever(), $this->body(taxDate: self::NEW_TAX_DATE), 'user');

        self::assertSame('source_locked', $decision['reason']);
        self::assertTrue($decision['blocked']);
        self::assertFalse($decision['rate_will_change']);
    }

    /** Vymazání DUZP posune rozhodný den na datum vystavení → přenačíst. */
    public function testClearingTaxDateReloadsRateToIssueDate(): void
    {
        $id = $this->eurInvoice('cnb', self::OLD_RATE, self::OLD_TAX_DATE);

        $result = $this->update($id, $this->body(taxDate: null), $this->cnbReturning(self::ISSUE_DATE));

        self::assertSame(200, $result['status']);
        $row = $this->rateRow($id);
        self::assertEqualsWithDelta(self::CNB_RATE, (float) $row['exchange_rate'], 1e-6);
        self::assertSame(self::ISSUE_DATE, (string) $row['exchange_rate_date']);
    }

    // ── korunový doklad ──────────────────────────────────────────────────────────────

    public function testSwitchingToCzkClearsRateAndDate(): void
    {
        $id = $this->eurInvoice('cnb', self::OLD_RATE, self::OLD_TAX_DATE);

        $result = $this->update(
            $id,
            $this->body(taxDate: self::OLD_TAX_DATE, currencyId: $this->czkId),
            $this->cnbNever(),
        );

        self::assertSame(200, $result['status']);
        $row = $this->rateRow($id);
        self::assertNull($row['exchange_rate'], 'Korunový doklad kurz nést nesmí.');
        self::assertNull($row['exchange_rate_date']);
    }

    /** Ani ruční pokus o uložení kurzu na korunový doklad neprojde přes repository. */
    public function testCzkInvoiceNeverStoresRateEvenWhenClientSendsOne(): void
    {
        $id = $this->repo->createDraft([
            'vendor_id' => $this->vendorId,
            'vendor_invoice_number' => 'CZK-RATE-1',
            'issue_date' => self::ISSUE_DATE,
            'due_date' => '2099-04-11',
            'currency_id' => $this->czkId,
            'exchange_rate' => 1.0,
            'exchange_rate_date' => self::ISSUE_DATE,
            'exchange_rate_source' => 'import',
        ], $this->userId, $this->supplierId);

        $row = $this->rateRow($id);
        self::assertNull($row['exchange_rate']);
        self::assertNull($row['exchange_rate_date']);
    }

    // ── výpadek ČNB ──────────────────────────────────────────────────────────────────

    public function testCnbOutageKeepsOldRateAndWarns(): void
    {
        $id = $this->eurInvoice('cnb', self::OLD_RATE, self::OLD_TAX_DATE);

        $cnb = $this->createStub(CnbExchangeRateClient::class);
        $cnb->method('getRate')->willReturn(null);

        $result = $this->update($id, $this->body(taxDate: self::NEW_TAX_DATE), $cnb);

        self::assertSame(200, $result['status'], 'Výpadek ČNB nesmí shodit uložení dokladu.');
        $row = $this->rateRow($id);
        self::assertEqualsWithDelta(self::OLD_RATE, (float) $row['exchange_rate'], 1e-6);
        self::assertContains('exchange_rate_not_reloaded', $result['body']['_warnings'] ?? []);
        self::assertSame(
            'cnb_unavailable',
            $result['body']['_warning_meta']['exchange_rate_not_reloaded']['reason'] ?? null,
        );
    }

    // ── zdroj kurzu se při PUT bez pole neztratí ─────────────────────────────────────

    /**
     * Volající, který kurzová pole neposlal (API klient, interní update cesta), si je
     * dřív vynuloval: `exchange_rate` spadl na NULL a zdroj na výchozí hodnotu.
     */
    public function testUpdateWithoutRateFieldsKeepsStoredRate(): void
    {
        $id = $this->eurInvoice('user', self::OLD_RATE, self::OLD_TAX_DATE);

        $body = $this->body(taxDate: self::OLD_TAX_DATE);
        unset($body['exchange_rate'], $body['exchange_rate_date'], $body['exchange_rate_source']);

        $result = $this->update($id, $body, $this->cnbReturning(self::OLD_TAX_DATE));

        self::assertSame(200, $result['status']);
        $row = $this->rateRow($id);
        self::assertEqualsWithDelta(self::OLD_RATE, (float) $row['exchange_rate'], 1e-6);
        self::assertSame(self::OLD_TAX_DATE, (string) $row['exchange_rate_date']);
        self::assertSame('user', (string) $row['exchange_rate_source']);
    }

    // ── zámky mají přednost: přenačtení je nesmí obejít ──────────────────────────────
    //
    // Přenačtení kurzu sahá na ČNB a mění účetní hodnotu dokladu, takže nesmí proběhnout
    // dřív, než guardy odmítnou požadavek. Testy proto používají `never()` mock: kdyby
    // resolve běžel před guardem, ČNB by se zeptala.

    /** Klient na zaúčtovaném dokladu → 403 a kurz zůstává, jak byl. */
    public function testClientOnBookedInvoiceGetsForbiddenBeforeAnyRateWork(): void
    {
        $id = $this->eurInvoice('cnb', self::OLD_RATE, self::OLD_TAX_DATE);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'booked', booked_at = NOW() WHERE id = ?")
            ->execute([$id]);

        $result = $this->update($id, $this->body(taxDate: self::NEW_TAX_DATE), $this->cnbNever(), role: 'client');

        self::assertSame(403, $result['status']);
        self::assertSame('document_locked', $result['body']['error']['code'] ?? null);
        self::assertEqualsWithDelta(self::OLD_RATE, (float) $this->rateRow($id)['exchange_rate'], 1e-6);
    }

    /** Účetní přesouvající doklad do uzavřeného období bez force → 409, kurz beze změny. */
    public function testMoveIntoClosedPeriodWithoutForceIsRejected(): void
    {
        // Období existují jen u podvojného účetnictví — u tax_evidence stojí zámek
        // jen na booked_at a testovaná větev by se vůbec nespustila.
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, 2099, "2099-01-01", "2099-12-31", "closed")'
        )->execute([$this->supplierId]);

        $id = $this->eurInvoice('cnb', self::OLD_RATE, self::OLD_TAX_DATE);

        $result = $this->update($id, $this->body(taxDate: self::NEW_TAX_DATE), $this->cnbNever());

        self::assertSame(409, $result['status']);
        self::assertSame('period_closed', $result['body']['error']['code'] ?? null);
        self::assertEqualsWithDelta(self::OLD_RATE, (float) $this->rateRow($id)['exchange_rate'], 1e-6);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────

    private function eurInvoice(string $source, float $rate, string $taxDate): int
    {
        $id = $this->repo->createDraft([
            'vendor_id' => $this->vendorId,
            'vendor_invoice_number' => 'PF-' . strtoupper($source) . '-' . bin2hex(random_bytes(3)),
            'issue_date' => self::ISSUE_DATE,
            'tax_date' => $taxDate,
            'due_date' => '2099-05-11',
            'currency_id' => $this->eurId,
            'exchange_rate' => $rate,
            'exchange_rate_date' => $taxDate,
            'exchange_rate_source' => $source,
            'items' => [],
        ], $this->userId, $this->supplierId);

        $this->repo->replaceItems($id, [[
            'description' => 'Služba', 'quantity' => 1, 'unit' => 'ks',
            'unit_price_without_vat' => 100.0, 'vat_rate_id' => $this->vatRateId,
        ]]);

        return $id;
    }

    /**
     * Tělo PUT požadavku tak, jak ho posílá editor — VČETNĚ kurzových polí se starou
     * hodnotou. Právě to byl zdroj vady: server je zapsal zpátky.
     *
     * @return array<string,mixed>
     */
    private function body(?string $taxDate, ?string $issueDate = null, ?int $currencyId = null): array
    {
        return [
            'vendor_id' => $this->vendorId,
            'vendor_invoice_number' => 'PF-UPDATED',
            'document_kind' => 'invoice',
            'issue_date' => $issueDate ?? self::ISSUE_DATE,
            'tax_date' => $taxDate,
            'due_date' => '2099-05-11',
            'currency_id' => $currencyId ?? $this->eurId,
            'exchange_rate' => self::OLD_RATE,
            'exchange_rate_date' => self::OLD_TAX_DATE,
            'exchange_rate_source' => 'cnb',
            'items' => [[
                'description' => 'Služba', 'quantity' => 1, 'unit' => 'ks',
                'unit_price_without_vat' => 100.0, 'vat_rate_id' => $this->vatRateId,
            ]],
        ];
    }

    /**
     * Rozhodnutí reloaderu nad čerstvě založeným EUR dokladem — bez Action, takže do
     * mocku ČNB nezasahuje kontrola odchylky.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function decide(CnbExchangeRateClient $cnb, array $body, string $source): array
    {
        $this->container->set(CnbExchangeRateClient::class, $cnb);
        $reloader = $this->container->get(PurchaseInvoiceRateReloader::class);

        $id = $this->eurInvoice($source, self::OLD_RATE, self::OLD_TAX_DATE);
        $existing = $this->repo->find($id, $this->supplierId) ?? [];

        return $reloader->resolveForUpdate($this->supplierId, $existing, $body);
    }

    private function cnbNever(): CnbExchangeRateClient
    {
        $cnb = $this->createMock(CnbExchangeRateClient::class);
        $cnb->expects(self::never())->method('getRate');

        return $cnb;
    }

    private function cnbReturning(string $rateDate): CnbExchangeRateClient
    {
        $cnb = $this->createStub(CnbExchangeRateClient::class);
        $cnb->method('getRate')->willReturn([
            'rate' => self::CNB_RATE, 'rate_date' => $rateDate, 'fallback_used' => false, 'source' => 'fresh',
        ]);

        return $cnb;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function update(int $id, array $body, CnbExchangeRateClient $cnb, string $role = 'accountant'): array
    {
        // Mock ČNB musí být v kontejneru DŘÍV, než se Action (a s ní ExchangeRateApplier)
        // poprvé vyrobí — PHP-DI instance memoizuje.
        $this->container->set(CnbExchangeRateClient::class, $cnb);
        $action = $this->container->get(UpdatePurchaseInvoiceAction::class);

        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/purchase-invoices/' . $id)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withParsedBody($body);

        $resp = $action($req, new Psr7Response(), ['id' => (string) $id]);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);

        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** @return array<string,mixed> */
    private function rateRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT exchange_rate, exchange_rate_date, exchange_rate_source
               FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $this->supplierId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
