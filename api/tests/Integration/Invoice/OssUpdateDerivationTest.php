<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\CreateInvoiceAction;
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
 * PUT /api/invoices/{id} derivuje OSS stejně jako POST — a stejně jako POST rozlišuje
 * INTEGRÁTORA od UŽIVATELE podle toho, jestli klíč `oss_applicable` v payloadu JE.
 *
 * Derivaci měl dlouho jen POST. `replaceItems()` je přitom DELETE + INSERT, takže
 * payload integrátora bez `oss_*` klíčů OSS na dokladu tiše SMAZAL: řádek se založil
 * znovu s DB defaultem `oss_applicable = 0`, tedy jako tuzemský.
 *
 * ── Proč má testovací sazba ZEMI CZ, ačkoli je to polských 23 % ─────────────────────
 * Protože právě tak ji má zákazník, u kterého se únik naměřil: formulář v Nastavení →
 * Sazby DPH má zemi předvyplněnou na CZ, takže „PL-23" v číselníku sedí se zemí CZ.
 * Je to podstatná část reprodukce, ne kosmetika — guard „Zahraniční sazbu DPH lze
 * použít jen na řádku v režimu OSS" čte `vat_rates.country`, takže nad touhle sazbou
 * MLČÍ a doklad se uloží se stavem 200. Se správně vyplněnou zemí by PUT skončil na
 * 400 a únik by byl hlasitý; tichý je právě u téhle konfigurace.
 *
 * Derivace se tím nezmate: o místě plnění rozhoduje číselník ČLENSKÝCH STÁTŮ, ne
 * `vat_rates`. Řádek proto OSS dostane, jen si ponechá `vat_rate_id`, které poslal
 * volající — sazbu státu spotřeby v `vat_rates` není kde vzít (viz
 * {@see \MyInvoice\Action\Invoice\DerivesMissingOssColumns}).
 *
 * Druhá polovina je stejně důležitá: `oss_applicable = 0` PŘÍTOMNÉ v payloadu je
 * rozhodnutí uživatele v editoru a derivace ho přebít nesmí — jinak by nešlo OSS na
 * položce vypnout. Že editor OSS klíče posílá vždy, hlídá
 * {@see \MyInvoice\Tests\Architecture\InvoiceEditorOssPayloadContractTest}.
 *
 * Bez obalové transakce (viz {@see OssManualReviewEditorApiTest}) — akce končí
 * přepočtem revenue cache s vlastní transakcí. Data jsou syntetická.
 */
#[Group('integration')]
final class OssUpdateDerivationTest extends TestCase
{
    private const TAX_DATE = '2096-05-15';
    private const DUE_DATE = '2096-06-15';

    private Connection $db;
    private CreateInvoiceAction $create;
    private UpdateInvoiceAction $update;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private bool $vatRateCreated = false;
    /** @var ?array<string,mixed> */
    private ?array $origOss = null;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->create = $c->get(CreateInvoiceAction::class);
            $this->update = $c->get(UpdateInvoiceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasColumn('supplier', 'oss_enabled')) {
            $this->markTestSkipped('Chybí OSS schéma (migrace 0137).');
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users).');
        }

        // Registrace do OSS je podmínka derivace — vynutit a v tearDown vrátit.
        $orig = $pdo->prepare('SELECT oss_enabled, oss_valid_from, oss_valid_to FROM supplier WHERE id = ?');
        $orig->execute([$this->supplierId]);
        $this->origOss = $orig->fetch(PDO::FETCH_ASSOC) ?: [];
        $pdo->prepare(
            'UPDATE supplier SET oss_enabled = 1, oss_valid_from = NULL, oss_valid_to = NULL WHERE id = ?'
        )->execute([$this->supplierId]);

        $this->currencyId = $this->currency();
        $this->clientId   = $this->client();
        $this->vatRateId  = $this->vatRate();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();

        if ($this->origOss !== null && $this->supplierId > 0) {
            $pdo->prepare(
                'UPDATE supplier SET oss_enabled = ?, oss_valid_from = ?, oss_valid_to = ? WHERE id = ?'
            )->execute([
                (int) ($this->origOss['oss_enabled'] ?? 0),
                $this->origOss['oss_valid_from'] ?? null,
                $this->origOss['oss_valid_to'] ?? null,
                $this->supplierId,
            ]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)')
                ->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        if ($this->vatRateCreated && $this->vatRateId > 0) {
            $pdo->prepare('DELETE FROM vat_rates WHERE id = ?')->execute([$this->vatRateId]);
        }

        $this->db->close();
    }

    /**
     * BEZ OPRAVY PADÁ: PUT OSS nederivoval, takže payload integrátora bez `oss_*` klíčů
     * uložil řádek s `oss_applicable = 0` — polské plnění za polskou sazbu jako tuzemské.
     */
    public function testUpdateWithoutOssKeysDerivesInsteadOfSilentlyClearingOss(): void
    {
        $id = $this->createOssInvoice();

        $resaved = $this->put($id, $this->payload([$this->integratorItem()]));
        self::assertSame(200, $resaved['status'],
            'Validace tenhle payload PROPUSTÍ — guard stojí na `vat_rates.country`, a ten je CZ. '
                . 'Právě proto musí OSS zajistit derivace: ' . json_encode($resaved['body'], JSON_UNESCAPED_UNICODE));

        $stored = $this->storedItem($id);
        self::assertSame(1, (int) $stored['oss_applicable'],
            'Payload BEZ OSS klíčů je integrátor, ne rozhodnutí uživatele — OSS se má odvodit, ne zhasnout.');
        self::assertSame('PL', (string) $stored['oss_consumer_country']);
    }

    /**
     * Protipól: nulu, kterou payload POSLAL, derivace přebít nesmí — jinak by nešlo OSS
     * na položce vypnout a editor by přišel o jedinou cestu, jak nejistotu ukončit.
     */
    public function testUpdateWithExplicitZeroKeepsTheUsersDecision(): void
    {
        $id = $this->createOssInvoice();

        $item = $this->integratorItem();
        // Přesně to, co posílá editor u odškrtnutého řádku (viz kontraktový test).
        $item['oss_applicable'] = false;
        $item['oss_consumer_country'] = null;
        $item['oss_rate_type'] = null;
        $item['oss_supply_type'] = null;

        $resaved = $this->put($id, $this->payload([$item]));
        self::assertSame(200, $resaved['status'], json_encode($resaved['body'], JSON_UNESCAPED_UNICODE));

        self::assertSame(0, (int) $this->storedItem($id)['oss_applicable'],
            'Přítomná nula je rozhodnutí uživatele — derivace se na takový řádek nesmí ani podívat.');
    }

    // ── payload ──────────────────────────────────────────────────────────────

    /** Doklad založený s explicitními OSS klíči, tedy „z editoru". */
    private function createOssInvoice(): int
    {
        $created = $this->post($this->payload([$this->integratorItem() + [
            'oss_applicable'       => true,
            'oss_consumer_country' => 'PL',
            'oss_rate_type'        => 'standard',
            'oss_supply_type'      => 'goods',
        ]]));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));

        return (int) $created['body']['id'];
    }

    /** Položka tak, jak ji pošle integrace, která o OSS neví: ŽÁDNÝ `oss_*` klíč. */
    private function integratorItem(): array
    {
        return [
            'description'            => 'TEST OSS položka (PHPUnit)',
            'quantity'               => 1,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 1000,
            'vat_rate_id'            => $this->vatRateId,
            'order_index'            => 0,
        ];
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
        return self::decode(($this->create)($this->request('POST', $body), new Psr7Response()));
    }

    /**
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function put(int $id, array $body): array
    {
        return self::decode(
            ($this->update)($this->request('PUT', $body), new Psr7Response(), ['id' => (string) $id])
        );
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    // ── data ─────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function storedItem(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY order_index, id LIMIT 1'
        );
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($row, 'Doklad nemá ani jednu položku — pak netvrdí nic ani zbytek testu.');

        return $row;
    }

    /** Měna se REUSUJE — druhá CZK témuž dodavateli by rozbila `is_default`. */
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

    /** Spotřebitel z JČS BEZ DIČ — jinak by derivace OSS vyloučila (B2B). */
    private function client(): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'PL' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát PL není v číselníku zemí.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "TEST OSS spotrebitel (PHPUnit)", "Ulica 1", "Warszawa", "00-001", ?,
                     "oss-put@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $countryId, $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Polských 23 % vedených v číselníku se ZEMÍ CZ — zákazníkova konfigurace, díky
     * které je únik tichý (viz docblock třídy). Vlastní kód, ať se test nepere o řádek
     * `PL-23` se sesterským {@see OssDerivedDocumentsTest}, který ho vede se zemí PL.
     */
    private function vatRate(): int
    {
        $pdo = $this->db->pdo();
        $code = 'TESTPLCZ-23';

        $probe = $pdo->prepare('SELECT id FROM vat_rates WHERE code = ?');
        $probe->execute([$code]);
        $this->vatRateCreated = ((int) $probe->fetchColumn()) === 0;

        $pdo->prepare(
            'INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default,
                                    is_reverse_charge, valid_from, valid_to, display_order)
             VALUES (?, 23.00, "CZ", ?, ?, 0, 0, "2090-01-01", NULL, 901)
             ON DUPLICATE KEY UPDATE rate_percent = VALUES(rate_percent), country = VALUES(country),
                                     valid_from = VALUES(valid_from), valid_to = VALUES(valid_to)'
        )->execute([$code, $code, $code]);

        $stmt = $pdo->prepare('SELECT id FROM vat_rates WHERE code = ?');
        $stmt->execute([$code]);

        return (int) $stmt->fetchColumn();
    }
}
