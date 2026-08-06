<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Security;

use MyInvoice\Action\Logbook\FuelingsAction;
use MyInvoice\Action\Logbook\TripsAction;
use MyInvoice\Action\Recurring\RecurringTemplateAction;
use MyInvoice\Action\Report\RelatedPartyAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Regrese k externímu security reportu 2026-08 (R2) — CWE-639 / BOLA na cizích
 * klíčích z těla requestu.
 *
 * Útočník je běžná role `accountant` u firmy A. Do těla requestu dosadí `*_id`
 * patřící firmě B. Před opravou vrátila aplikace 200/201 i s popisky firmy B
 * (`partner_name`, `category_label`, `vendor_name`), protože read-back JOIN
 * neměl tenant predikát. Po opravě musí každý takový request skončit 400
 * `invalid_reference` a NIC z firmy B se nesmí objevit v odpovědi.
 *
 * Ke každému útoku je i pozitivní kontrola s VLASTNÍM id — jinak by test zezelenal
 * i tehdy, kdyby guard blokoval všechno (třeba proto, že se rozešel se schématem).
 *
 * Vše běží v jedné transakci, kterou tearDown zahodí.
 */
#[Group('integration')]
final class TenantReferenceGuardIdorTest extends TestCase
{
    private Connection $db;
    private ContainerInterface $container;
    private PDO $pdo;
    private bool $inTx = false;

    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->container = Bootstrap::buildApp()->getContainer();
            $this->db = $this->container->get(Connection::class);
            $this->pdo = $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $this->supplierA = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierA === 0) {
            $this->markTestSkipped('Testovací DB nemá žádnou firmu.');
        }
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);

        $this->pdo->beginTransaction();
        $this->inTx = true;

        $this->supplierB = $this->cloneSupplier($this->supplierA);
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

    /** Sweep F3 — POST /api/logbook/trips s cizím `category_id`. */
    public function testForeignTripCategoryIsRejected(): void
    {
        $carA        = $this->car($this->supplierA, 'IDOR-A-1');
        $categoryA   = $this->tripCategory($this->supplierA, 'OWN-A', 'Vlastní kategorie A');
        $categoryB   = $this->tripCategory($this->supplierB, 'SECRET-B', 'TENANT B SECRET category');

        $action = $this->container->get(TripsAction::class);
        $body = [
            'car_id' => $carA, 'trip_date' => '2099-01-05', 'distance_km' => 10,
            'purpose' => 'idor-regrese', 'category_id' => $categoryB,
        ];

        $res = $this->call($action, 'create', 'POST', $body);
        self::assertSame(400, $res['status'], 'Cizí category_id musí skončit 400, ne 201.');
        self::assertSame('invalid_reference', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString('TENANT B SECRET', json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        // Pozitivní kontrola — vlastní kategorie projde.
        $ok = $this->call($action, 'create', 'POST', ['category_id' => $categoryA] + $body);
        self::assertSame(201, $ok['status'], 'Vlastní category_id guard blokovat nesmí.');
        self::assertSame($categoryA, (int) ($ok['body']['category_id'] ?? 0));
    }

    /**
     * Zlomkové ID neobejde guard přes rozdíl v zaokrouhlování.
     *
     * PHP `(int)` ořezává (5.7 → 5), MySQL při zápisu do INT sloupce zaokrouhluje
     * (5.7 → 6). Bez explicitního odmítnutí by guard ověřil vlastnictví řádku,
     * který se NEULOŽÍ, a uložil se řádek o jedno ID vedle — tedy potenciálně cizí.
     */
    public function testFractionalIdIsRejectedInsteadOfTruncated(): void
    {
        $carA      = $this->car($this->supplierA, 'IDOR-A-FRAC');
        $categoryA = $this->tripCategory($this->supplierA, 'OWN-A-FRAC', 'Vlastní kategorie A');

        $action = $this->container->get(TripsAction::class);
        $res = $this->call($action, 'create', 'POST', [
            'car_id' => $carA, 'trip_date' => '2099-01-06', 'distance_km' => 10,
            'purpose' => 'idor-regrese-zlomek',
            // Ořezáním vlastní kategorie, zaokrouhlením už ne — guard musí odmítnout.
            'category_id' => $categoryA + 0.7,
        ]);

        self::assertSame(400, $res['status'], 'Zlomkové category_id musí skončit 400.');
        self::assertSame('invalid_reference', $res['body']['error']['code'] ?? null);
    }

    /** Sweep F1 — POST /api/logbook/fuelings s cizím `vendor_id`. */
    public function testForeignFuelingVendorIsRejected(): void
    {
        $carA     = $this->car($this->supplierA, 'IDOR-A-2');
        $clientA  = $this->client($this->supplierA, 'Vlastní dodavatel A');
        $clientB  = $this->client($this->supplierB, 'TENANT B SECRET vendor');

        $action = $this->container->get(FuelingsAction::class);
        $body = [
            'fueled_date' => '2099-01-06', 'amount_with_vat' => 700,
            'car_id' => $carA, 'station' => 'idor-regrese',
        ];

        $res = $this->call($action, 'create', 'POST', ['vendor_id' => $clientB] + $body);
        self::assertSame(400, $res['status'], 'Cizí vendor_id musí skončit 400, ne 201.');
        self::assertSame('invalid_reference', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString('TENANT B SECRET', json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $ok = $this->call($action, 'create', 'POST', ['vendor_id' => $clientA] + $body);
        self::assertSame(201, $ok['status'], 'Vlastní vendor_id guard blokovat nesmí.');
        self::assertSame($clientA, (int) ($ok['body']['vendor_id'] ?? 0));
    }

    /** Sweep F2 — POST /api/reports/related-parties/adjustments s cizím `client_id`. */
    public function testForeignRelatedPartyClientIsRejected(): void
    {
        $clientA = $this->client($this->supplierA, 'Vlastní spojená osoba A');
        $clientB = $this->client($this->supplierB, 'TENANT B SECRET partner');

        $action = $this->container->get(RelatedPartyAction::class);
        $body = ['fiscal_year' => 2099, 'amount' => 1000, 'reason' => 'idor-regrese', 'movement' => 'increase'];

        $res = $this->call($action, 'createAdjustment', 'POST', ['client_id' => $clientB] + $body);
        self::assertSame(400, $res['status'], 'Cizí client_id musí skončit 400, ne 200.');
        self::assertSame('invalid_reference', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString('TENANT B SECRET', json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $ok = $this->call($action, 'createAdjustment', 'POST', ['client_id' => $clientA] + $body);
        self::assertSame(200, $ok['status'], 'Vlastní client_id guard blokovat nesmí.');
    }

    /** Report R2 #2 — POST /api/recurring s cizím `client_id` (guard běží před validací). */
    public function testForeignRecurringTemplateClientIsRejected(): void
    {
        $clientB = $this->client($this->supplierB, 'TENANT B SECRET odběratel');

        $action = $this->container->get(RecurringTemplateAction::class);
        $res = $this->call($action, 'create', 'POST', [
            'client_id' => $clientB, 'name' => 'idor-regrese',
            'frequency' => 'monthly', 'anchor_date' => '2099-01-01',
            'items' => [['description' => 'x', 'quantity' => 1, 'unit_price_without_vat' => 100]],
        ]);

        self::assertSame(400, $res['status'], 'Cizí client_id musí skončit 400.');
        self::assertSame('invalid_reference', $res['body']['error']['code'] ?? null);
        self::assertStringNotContainsString('TENANT B SECRET', json_encode($res['body'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Volá Action metodu jménem firmy A, rolí `accountant`.
     *
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(object $action, string $method, string $httpMethod, array $body): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);

        /** @var ResponseInterface $response */
        $response = $action->{$method}($request, new Psr7Response());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** Klon firmy uvnitř rollbackované transakce — multi-tenant test i na jednofiremní DB. */
    private function cloneSupplier(int $sourceId): int
    {
        $columns = $this->pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier'
                AND COLUMN_NAME <> 'id' AND EXTRA NOT LIKE '%auto_increment%'
                AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '')
              ORDER BY ORDINAL_POSITION"
        )->fetchAll(PDO::FETCH_COLUMN);
        $list = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));

        // Id přiděluje AUTO_INCREMENT, ne hledání volného místa v rozsahu 1–255. Ten
        // rozsah tu zbyl z doby, kdy byl `supplier.id` TINYINT; jakmile pool došel,
        // padal test na chybějící id místo na to, co měří (viz IsolatedSupplierTrait).
        $this->pdo->prepare("INSERT INTO supplier ({$list}) SELECT {$list} FROM supplier WHERE id = ?")
            ->execute([$sourceId]);
        $newId = (int) $this->pdo->lastInsertId();
        self::assertGreaterThan(0, $newId, 'Klon firmy se nezaložil.');

        return $newId;
    }

    private function car(int $supplierId, string $registration): int
    {
        $this->pdo->prepare('INSERT INTO cars (supplier_id, registration, name) VALUES (?, ?, ?)')
            ->execute([$supplierId, $registration, 'IDOR test ' . $registration]);

        return (int) $this->pdo->lastInsertId();
    }

    private function tripCategory(int $supplierId, string $code, string $label): int
    {
        $this->pdo->prepare('INSERT INTO trip_categories (supplier_id, code, label) VALUES (?, ?, ?)')
            ->execute([$supplierId, $code, $label]);

        return (int) $this->pdo->lastInsertId();
    }

    private function client(int $supplierId, string $companyName): int
    {
        $countryId = (int) ($this->pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId = (int) ($this->pdo->query(
            'SELECT id FROM currencies WHERE supplier_id = ' . $supplierId . ' ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($currencyId === 0) {
            $this->pdo->prepare(
                'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, 2, 1, 1)'
            )->execute([$supplierId, 'CZK', 'CZK', 'Kč', 'Koruna', 'Koruna', ]);
            $currencyId = (int) $this->pdo->lastInsertId();
        }

        $this->pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, $companyName, 'Testovací 1', 'Praha', '11000', $countryId, $currencyId]);

        return (int) $this->pdo->lastInsertId();
    }
}
