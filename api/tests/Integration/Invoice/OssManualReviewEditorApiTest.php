<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\GetInvoiceAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Support\PaymentMethods;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Příznak „místo plnění k ručnímu posouzení" (migrace 1293) na CESTĚ, kterou chodí editor:
 * POST /api/invoices → GET /api/invoices/{id} → PUT /api/invoices/{id}.
 *
 * Doplněk k {@see OssManualReviewPersistenceTest}, který tutéž věc hlídá na úrovni
 * repository. Rozdíl je podstatný: `InvoiceRepository::replaceItems()` je DELETE + INSERT,
 * takže o osudu příznaku nerozhoduje jen to, jestli ho zápis UMÍ uložit, ale hlavně to,
 * jestli ho uložení faktury vůbec POŠLE. Backendový round-trip byl hotový a příznak se
 * přesto po prvním uložení dokladu z UI ztrácel, protože ho payload editoru neobsahoval —
 * u migrace 1 670 dokladů tím celá kategorie „nedokázali jsme určit místo plnění" mizela
 * dřív, než se na ni kdokoli podíval.
 *
 * Poslední test je proto NEGATIVNÍ KONTROLA: dokazuje, že to, co payload nepošle, žádná
 * backendová vrstva nezachrání. Bez ní by tenhle soubor svítil zeleně i nad rozbitým
 * editorem a tvrdil by něco jiného, než co hlídá.
 *
 * ── Proč tenhle test NEBĚŽÍ v obalové transakci ──────────────────────────────────────
 * Většina integračních testů se izoluje `beginTransaction()` v setUp a rollbackem
 * v tearDown. Tady to nejde: `UpdateInvoiceAction` končí přepočtem revenue cache
 * ({@see \MyInvoice\Service\Stats\StatsRecomputer::recomputeClient()}), který si otevírá
 * VLASTNÍ transakci — a PDO nad MariaDB vnořenou transakci neumí, takže by PUT spadl na
 * „There is already an active transaction". Obalová transakce by tím pádem netestovala
 * cestu editoru, jen by ji shodila. Testy, které skutečné akce volají, se proto uklízejí
 * ručně (stejně {@see OssManualReviewPersistenceTest} i `ClientChangeStatsTest`).
 *
 * Data jsou syntetická; tearDown maže přesně to, co setUp a testy založily.
 */
#[Group('integration')]
final class OssManualReviewEditorApiTest extends TestCase
{
    private const TAX_DATE = '2096-05-15';
    private const DUE_DATE = '2096-06-15';

    private Connection $db;
    private CreateInvoiceAction $create;
    private GetInvoiceAction $get;
    private UpdateInvoiceAction $update;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private bool $vatRateCreated = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->create = $c->get(CreateInvoiceAction::class);
            $this->get    = $c->get(GetInvoiceAction::class);
            $this->update = $c->get(UpdateInvoiceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasColumn('invoice_items', 'oss_needs_manual_review')) {
            $this->markTestSkipped('Chybí migrace 1293.');
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users).');
        }

        $this->currencyId = $this->currency();
        $this->clientId   = $this->client('Testowy Odbiorca sp. z o.o.', 'PL');
        $this->vatRateId  = $this->vatRate('PL', 23.0);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();

        // Pořadí je dané cizími klíči: položky → doklady → cache → klient. Doklady se
        // hledají přes klienta, protože ID vzniklá uvnitř testu sem nedosáhnou.
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)')
                ->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        // Sazba státu spotřeby je v GLOBÁLNÍ `vat_rates` — mazat ji smíme jen tehdy,
        // když jsme ji sami založili, jinak bychom sáhli na data jiného testu či fixture.
        if ($this->vatRateCreated && $this->vatRateId > 0) {
            $pdo->prepare('DELETE FROM vat_rates WHERE id = ?')->execute([$this->vatRateId]);
        }

        $this->db->close();
    }

    /**
     * Založení dokladu z editoru se zapnutým příznakem — nejjednodušší polovina, ale bez
     * ní nemá zbytek co ověřovat.
     */
    public function testFlagSetFromTheEditorPayloadIsStored(): void
    {
        $created = $this->post($this->payload([$this->ossItem(needsReview: true)]));

        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $this->storedFlag((int) $created['body']['id']));
    }

    /**
     * PLNÝ ROUND TRIP EDITORU: doklad se otevře (GET) a znovu uloží (PUT) s payloadem
     * složeným z toho, co detail vrátil — přesně jak to dělá `InvoiceEditor.vue`.
     *
     * BEZ OPRAVY PADÁ: payload editoru `oss_needs_manual_review` neposílal, `replaceItems()`
     * položky smaže a založí znovu, takže první uložení faktury příznak zhaslo — a protože
     * report importu je v ten okamžik dávno zavřený, nezbyla po kategorii stopa nikde.
     */
    public function testFlagSurvivesOpeningAndResavingTheInvoiceThroughTheApi(): void
    {
        $id = (int) $this->post($this->payload([$this->ossItem(needsReview: true)]))['body']['id'];

        $detail = $this->fetch($id);
        self::assertTrue($detail['items'][0]['oss_needs_manual_review'] ?? null,
            'Detail dokladu příznak nevrací — editor nemá co poslat zpátky.');

        $resaved = $this->put($id, $this->payload(array_map(
            fn (array $item): array => $this->editorItemPayload($item),
            $detail['items'],
        )));

        self::assertSame(200, $resaved['status'], json_encode($resaved['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $this->storedFlag($id), 'Druhé uložení z editoru příznak zahodilo.');
    }

    /**
     * Vypnutí OSS na položce je ROZHODNUTÍ ČLOVĚKA, kterým nejistota končí — příznak se
     * proto zhasne i cestou přes API. Je to jediná cesta, jak ho legitimně zhasnout;
     * samostatné pole „zkontrolováno" editor nemá schválně (příznak je záznam o odvození,
     * ne uživatelská volba).
     */
    public function testTurningOssOffFromTheEditorClearsTheFlag(): void
    {
        $id = (int) $this->post($this->payload([$this->ossItem(needsReview: true)]))['body']['id'];

        $item = $this->editorItemPayload($this->fetch($id)['items'][0]);
        $item['oss_applicable'] = false;
        // Editor u ne-OSS řádku posílá OSS pole jako null/false — stejná mapa jako v UI.
        $item['oss_consumer_country'] = null;
        $item['oss_rate_type'] = null;
        $item['oss_supply_type'] = null;
        $item['oss_needs_manual_review'] = false;
        // Bez OSS by zahraniční sazba neprošla validací („Zahraniční sazbu DPH lze použít
        // jen na řádku v režimu OSS"), takže řádek přechází na tuzemskou — přesně jako
        // v UI, kde se nabídka sazeb u ne-OSS řádku filtruje na tuzemsko.
        $item['vat_rate_id'] = $this->domesticVatRateId();

        $resaved = $this->put($id, $this->payload([$item]));

        self::assertSame(200, $resaved['status'], json_encode($resaved['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->storedFlag($id));
    }

    /**
     * NEGATIVNÍ KONTROLA — payload BEZ pole (tvar, který editor posílal před opravou).
     * Backend ho nemá odkud doplnit: `replaceItems()` je DELETE + INSERT, takže co se
     * nepošle, to v datech není.
     *
     * Tenhle test se nesmí „opravit" tím, že by backend chybějící klíč dopočítal ze
     * starého řádku — pak by nešlo příznak zhasnout vypnutím OSS (viz test výš) a příznak
     * by se stal nesmazatelným. Zodpovědnost je na payloadu, a proto ji hlídá i
     * {@see \MyInvoice\Tests\Architecture\InvoiceEditorOssPayloadContractTest}.
     */
    public function testPayloadWithoutTheFieldLosesTheFlagWhichIsWhyTheEditorMustSendIt(): void
    {
        $id = (int) $this->post($this->payload([$this->ossItem(needsReview: true)]))['body']['id'];

        $item = $this->editorItemPayload($this->fetch($id)['items'][0]);
        unset($item['oss_needs_manual_review']);

        $resaved = $this->put($id, $this->payload([$item]));

        self::assertSame(200, $resaved['status'], json_encode($resaved['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->storedFlag($id),
            'Kdyby si backend chybějící klíč domýšlel, nešlo by příznak zhasnout vypnutím OSS.');
    }

    // ── payload editoru ──────────────────────────────────────────────────────

    /**
     * Položka tak, jak ji `InvoiceEditor.vue` skládá z načteného dokladu — včetně
     * `?? false` u příznaku. Mapa se drží pořadí i sémantiky UI, ať se testovaný tvar
     * payloadu nerozejde s tím skutečným.
     *
     * @param  array<string,mixed> $item položka z GET /api/invoices/{id}
     * @return array<string,mixed>
     */
    private function editorItemPayload(array $item): array
    {
        $oss = !empty($item['oss_applicable']);

        return [
            'description'            => (string) ($item['description'] ?? ''),
            'quantity'               => $item['quantity'] ?? 1,
            'unit'                   => $item['unit'] ?? 'ks',
            'unit_price_without_vat' => $item['unit_price_without_vat'] ?? 0,
            'vat_rate_id'            => $item['vat_rate_id'] ?? null,
            'order_index'            => 0,
            'stock_item_id'          => $item['stock_item_id'] ?? null,
            'warehouse_id'           => $item['warehouse_id'] ?? null,
            'small_asset_id'         => $item['small_asset_id'] ?? null,
            'asset_id'               => $item['asset_id'] ?? null,
            'oss_applicable'         => $oss,
            'oss_consumer_country'   => $oss ? ($item['oss_consumer_country'] ?: null) : null,
            'oss_rate_type'          => $oss ? ($item['oss_rate_type'] ?: null) : null,
            'oss_supply_type'        => $oss ? ($item['oss_supply_type'] ?: 'goods') : null,
            'oss_exchange_rate'      => $oss ? ($item['oss_exchange_rate'] ?? null) : null,
            'oss_exchange_rate_date' => $oss ? ($item['oss_exchange_rate_date'] ?? null) : null,
            'oss_taxable_amount_return' => $oss ? ($item['oss_taxable_amount_return'] ?? null) : null,
            'oss_vat_amount_return'  => $oss ? ($item['oss_vat_amount_return'] ?? null) : null,
            'oss_original_period'    => $oss ? ($item['oss_original_period'] ?? null) : null,
            'oss_needs_manual_review' => $oss ? ($item['oss_needs_manual_review'] ?? false) : false,
        ];
    }

    /** @return array<string,mixed> */
    private function ossItem(bool $needsReview): array
    {
        return $this->editorItemPayload([
            'description'             => 'TEST OSS položka (PHPUnit)',
            'quantity'                => 1,
            'unit'                    => 'ks',
            'unit_price_without_vat'  => 1000,
            'vat_rate_id'             => $this->vatRateId,
            'oss_applicable'          => true,
            'oss_consumer_country'    => 'PL',
            'oss_rate_type'           => 'standard',
            'oss_supply_type'         => 'goods',
            'oss_needs_manual_review' => $needsReview,
        ]);
    }

    /**
     * @param  list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function payload(array $items): array
    {
        return [
            'invoice_type'       => 'invoice',
            'client_id'          => $this->clientId,
            'issue_date'         => self::TAX_DATE,
            'tax_date'           => self::TAX_DATE,
            'due_date'           => self::DUE_DATE,
            'currency_id'        => $this->currencyId,
            'reverse_charge'     => false,
            'prices_include_vat' => false,
            // Doména je `PaymentMethods::ALL`, ne volný text — `transfer` tam není a
            // `InvoiceValidation` doklad odmítne dřív, než se k OSS položce vůbec dostane.
            'payment_method'     => PaymentMethods::DEFAULT,
            'language'           => 'cs',
            'items'              => array_values($items),
        ];
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function post(array $body): array
    {
        $response = ($this->create)($this->request('POST', $body), new Psr7Response());

        return self::decode($response);
    }

    /**
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function put(int $id, array $body): array
    {
        $response = ($this->update)($this->request('PUT', $body), new Psr7Response(), ['id' => (string) $id]);

        return self::decode($response);
    }

    /** @return array<string,mixed> */
    private function fetch(int $id): array
    {
        $response = ($this->get)($this->request('GET'), new Psr7Response(), ['id' => (string) $id]);
        $decoded = self::decode($response);
        self::assertSame(200, $decoded['status']);

        return $decoded['body'];
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);

        return $body !== [] ? $request->withParsedBody($body) : $request;
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return [
            'status' => $response->getStatusCode(),
            'body'   => is_array($decoded) ? $decoded : [],
        ];
    }

    // ── data ─────────────────────────────────────────────────────────────────

    private function storedFlag(int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT oss_needs_manual_review FROM invoice_items
              WHERE invoice_id = ? ORDER BY order_index, id LIMIT 1'
        );
        $stmt->execute([$invoiceId]);
        $value = $stmt->fetch(PDO::FETCH_COLUMN);
        self::assertNotFalse($value, 'Doklad nemá ani jednu položku — pak netvrdí nic ani zbytek testu.');

        return (int) $value;
    }

    /** Měna se REUSUJE — zakládat druhou CZK stejnému dodavateli by rozbilo `is_default`. */
    private function currency(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1
              ORDER BY (code = 'CZK') DESC, is_default DESC, id LIMIT 1"
        );
        $stmt->execute([$this->supplierId]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            self::markTestSkipped('Dodavatel nemá aktivní měnu.');
        }

        return $id;
    }

    private function client(string $name, string $iso2): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query(
            "SELECT id FROM countries WHERE UPPER(iso2) = '" . strtoupper($iso2) . "' LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát ' . $iso2 . ' není v číselníku zemí.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Ulica 1", "Warszawa", "00-001", ?, "odberatel@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $name, $countryId, $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }

    /** Sazba státu spotřeby v globální `vat_rates` — zakládá ji uživatel, tady tedy test. */
    private function vatRate(string $country, float $percent): int
    {
        $code = strtoupper($country) . '-' . rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
        $pdo = $this->db->pdo();

        $probe = $pdo->prepare('SELECT id FROM vat_rates WHERE code = ?');
        $probe->execute([$code]);
        $this->vatRateCreated = ((int) $probe->fetchColumn()) === 0;

        $pdo->prepare(
            'INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default,
                                    is_reverse_charge, valid_from, valid_to, display_order)
             VALUES (?, ?, ?, ?, ?, 0, 0, "2090-01-01", NULL, 900)
             ON DUPLICATE KEY UPDATE rate_percent = VALUES(rate_percent), country = VALUES(country),
                                     valid_from = VALUES(valid_from), valid_to = VALUES(valid_to)'
        )->execute([$code, $percent, strtoupper($country), $code, $code]);

        $stmt = $pdo->prepare('SELECT id FROM vat_rates WHERE code = ?');
        $stmt->execute([$code]);

        return (int) $stmt->fetchColumn();
    }

    /** Tuzemská sazba platná k DUZP — pro řádek, ze kterého se OSS vypne. */
    private function domesticVatRateId(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM vat_rates
              WHERE country = 'CZ' AND is_reverse_charge = 0 AND rate_percent > 0
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
           ORDER BY rate_percent DESC LIMIT 1"
        );
        $stmt->execute([self::TAX_DATE, self::TAX_DATE]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            self::markTestSkipped('Číselník sazeb DPH nemá tuzemskou sazbu k ' . self::TAX_DATE . '.');
        }

        return $id;
    }
}
