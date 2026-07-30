<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\CreateInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

#[Group('integration')]
final class InvoiceParentValidationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private CreateInvoiceAction $action;
    private int $supplierId;
    private int $foreignSupplierId;
    private int $clientId;
    private int $foreignClientId;
    private int $currencyId;
    private int $foreignCurrencyId;
    private int $vatRateId;
    private int $userId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(CreateInvoiceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$sourceSupplierId, $this->userId, $this->vatRateId, $countryId], true)) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->foreignSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->currencyId = $this->createCurrency($this->supplierId);
        $this->foreignCurrencyId = $this->createCurrency($this->foreignSupplierId);
        $this->clientId = $this->createClient(
            $this->supplierId,
            $countryId,
            $this->currencyId,
            'parent-check@example.test'
        );
        $this->foreignClientId = $this->createClient(
            $this->foreignSupplierId,
            $countryId,
            $this->foreignCurrencyId,
            'foreign-parent@example.test'
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testMissingParentReturnsControlledValidationError(): void
    {
        $response = $this->createCreditNote(2147483647);

        self::assertSame(400, $response['status']);
        self::assertSame('integrity_violation', $response['body']['error']['code'] ?? null);
        self::assertStringContainsString('Rodičovský doklad', $response['body']['error']['message'] ?? '');
        self::assertSame(0, $this->invoiceCount($this->supplierId));
    }

    public function testForeignParentReturnsControlledValidationError(): void
    {
        $foreignParentId = $this->insertParentInvoice(
            $this->foreignSupplierId,
            $this->foreignClientId,
            $this->foreignCurrencyId
        );

        $response = $this->createCreditNote($foreignParentId);

        self::assertSame(400, $response['status']);
        self::assertSame('integrity_violation', $response['body']['error']['code'] ?? null);
        self::assertStringContainsString('Rodičovský doklad', $response['body']['error']['message'] ?? '');
        self::assertSame(0, $this->invoiceCount($this->supplierId));
    }

    private function createCurrency(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "CZK", "CZK — test", "Kč", "Česká koruna", "Czech Koruna", 2, 1, 1)'
        );
        $stmt->execute([$supplierId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function createClient(int $supplierId, int $countryId, int $currencyId, string $email): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Testovací odběratel", "Testovací 1", "Praha", "11000", ?, ?, "cs", ?, 1, 0)'
        );
        $stmt->execute([$supplierId, $countryId, $email, $currencyId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertParentInvoice(int $supplierId, int $clientId, int $currencyId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, invoice_type, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, prices_include_vat, language, status, created_by)
             VALUES (?, ?, "invoice", "2099-01-10", "2099-01-10", "2099-01-17",
                     ?, 0, 1, "cs", "draft", ?)'
        );
        $stmt->execute([$supplierId, $clientId, $currencyId, $this->userId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function createCreditNote(int $parentInvoiceId): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices')
            ->withParsedBody([
                'client_id'          => $this->clientId,
                'invoice_type'       => 'credit_note',
                'parent_invoice_id'  => $parentInvoiceId,
                'issue_date'         => '2099-01-15',
                'tax_date'           => '2099-01-15',
                'due_date'           => '2099-01-22',
                'currency_id'        => $this->currencyId,
                'payment_method'     => 'card',
                'prices_include_vat' => true,
                'language'           => 'cs',
                'items'              => [[
                    'description'            => 'Dobropis test',
                    'quantity'               => 1,
                    'unit_price_without_vat' => -121,
                    'unit'                   => 'ks',
                    'vat_rate_id'            => $this->vatRateId,
                ]],
            ])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);

        $response = ($this->action)($request, new Psr7Response());
        $response->getBody()->rewind();

        return [
            'status' => $response->getStatusCode(),
            'body'   => json_decode((string) $response->getBody(), true) ?: [],
        ];
    }

    private function invoiceCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
