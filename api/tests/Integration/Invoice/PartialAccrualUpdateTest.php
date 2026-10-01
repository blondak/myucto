<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Action\Invoice\SetInvoiceAccrualAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Support\PaymentMethods;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * PUT /api/invoices/{id}/accrual s částečným řádkem (issue #113): vynechaný klíč
 * období drží uloženou hodnotu, explicitní null maže. Data jsou syntetická.
 */
#[Group('integration')]
final class PartialAccrualUpdateTest extends TestCase
{
    private Connection $db;
    private CreateInvoiceAction $create;
    private SetInvoiceAccrualAction $accrual;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db      = $c->get(Connection::class);
            $this->create  = $c->get(CreateInvoiceAction::class);
            $this->accrual = $c->get(SetInvoiceAccrualAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query(
            "SELECT id FROM vat_rates WHERE UPPER(COALESCE(country, 'CZ')) = 'CZ' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users / vat_rates).');
        }
        $stmt = $pdo->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 AND code = 'CZK' ORDER BY is_default DESC, id LIMIT 1"
        );
        $stmt->execute([$this->supplierId]);
        $this->currencyId = (int) $stmt->fetchColumn();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->currencyId === 0 || $countryId === 0) {
            $this->markTestSkipped('Chybí CZK nebo stát CZ.');
        }
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "TEST partial accrual (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "partial-accrual@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $countryId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)')
                ->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    /** BEZ OPRAVY PADÁ: řádek s jediným klíčem dostal 400, řádek bez klíčů měl období smazané. */
    public function testOmittedKeysKeepStoredPeriod(): void
    {
        [$id, $itemId] = $this->createInvoice();

        $res = $this->put($id, [['id' => $itemId]]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(['2096-05-01', '2096-12-31'], $this->period($itemId), 'Řádek bez klíčů se nemění.');

        $res = $this->put($id, [['id' => $itemId, 'accrual_to' => '2097-03-31']]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(['2096-05-01', '2097-03-31'], $this->period($itemId), 'Vynechaný začátek zůstává.');
    }

    public function testExplicitNullClears(): void
    {
        [$id, $itemId] = $this->createInvoice();

        $res = $this->put($id, [['id' => $itemId, 'accrual_from' => null, 'accrual_to' => null]]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame([null, null], $this->period($itemId));
    }

    /** @return array{int,int} */
    private function createInvoice(): array
    {
        $req = $this->request('POST', [
            'invoice_type'       => 'invoice',
            'client_id'          => $this->clientId,
            'issue_date'         => '2096-04-10',
            'tax_date'           => '2096-04-10',
            'due_date'           => '2096-05-10',
            'currency_id'        => $this->currencyId,
            'reverse_charge'     => false,
            'prices_include_vat' => false,
            'payment_method'     => PaymentMethods::DEFAULT,
            'language'           => 'cs',
            'items'              => [[
                'description'            => 'Předplatné (PHPUnit)',
                'quantity'               => 1,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 1000.0,
                'vat_rate_id'            => $this->vatRateId,
                'accrual_from'           => '2096-05-01',
                'accrual_to'             => '2096-12-31',
            ]],
        ]);
        $created = self::decode(($this->create)($req, new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        $id = (int) $created['body']['id'];
        $stmt = $this->db->pdo()->prepare('SELECT id FROM invoice_items WHERE invoice_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$id]);
        $itemId = (int) $stmt->fetchColumn();
        self::assertSame(['2096-05-01', '2096-12-31'], $this->period($itemId), 'Založení musí období uložit.');

        return [$id, $itemId];
    }

    /** @return array{?string,?string} */
    private function period(int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT accrual_from, accrual_to FROM invoice_items WHERE id = ?');
        $stmt->execute([$itemId]);
        $row = (array) $stmt->fetch(\PDO::FETCH_ASSOC);

        return [$row['accrual_from'] ?? null, $row['accrual_to'] ?? null];
    }

    /**
     * @param  list<array<string,mixed>> $items
     * @return array{status:int, body:array<string,mixed>}
     */
    private function put(int $id, array $items): array
    {
        return self::decode(
            ($this->accrual)($this->request('PUT', ['items' => $items]), new Psr7Response(), ['id' => (string) $id])
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
}
