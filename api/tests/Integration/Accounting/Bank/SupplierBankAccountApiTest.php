<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Accounting\Bank\SupplierBankAccountAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

#[Group('integration')]
final class SupplierBankAccountApiTest extends BankPostingTestCase
{
    public function testListAndPatchAreTenantScoped(): void
    {
        $vatRateId = (int) $this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->db->pdo()->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, email, default_currency_id,
                 default_vat_rate_id, accounting_mode)
             VALUES ("Cizí API test firma", "Testovací 2", "Praha", "11000", ?, ?, ?, ?, "double_entry")'
        )->execute([
            $this->czId,
            'bank-account-idor-' . uniqid('', true) . '@example.com',
            $this->currencyId,
            $vatRateId,
        ]);
        $otherSupplierId = (int) $this->db->pdo()->lastInsertId();

        $ownId = $this->upsertAccount($this->supplierId);
        $foreignId = $this->upsertAccount($otherSupplierId);
        $action = $this->container->get(SupplierBankAccountAction::class);

        $list = $this->invoke($action, 'list', 'GET', '/api/accounting/bank-accounts');
        self::assertSame(200, $list['status']);
        self::assertContains($ownId, array_column($list['body']['accounts'], 'id'));
        self::assertNotContains($foreignId, array_column($list['body']['accounts'], 'id'));

        $foreignPatch = $this->invoke(
            $action,
            'update',
            'PATCH',
            '/api/accounting/bank-accounts/' . $foreignId,
            ['kind' => 'term_deposit'],
            ['id' => (string) $foreignId],
        );
        self::assertSame(404, $foreignPatch['status']);

        $ownPatch = $this->invoke(
            $action,
            'update',
            'PATCH',
            '/api/accounting/bank-accounts/' . $ownId,
            ['kind' => 'savings', 'is_active' => false, 'analytic_suffix' => '101'],
            ['id' => (string) $ownId],
        );
        self::assertSame(200, $ownPatch['status']);
        self::assertSame('savings', $ownPatch['body']['kind']);
        self::assertFalse($ownPatch['body']['is_active']);
        self::assertSame('101', $ownPatch['body']['analytic_suffix']);

        $invalidBoolean = $this->invoke(
            $action,
            'update',
            'PATCH',
            '/api/accounting/bank-accounts/' . $ownId,
            ['is_active' => 'false'],
            ['id' => (string) $ownId],
        );
        self::assertSame(422, $invalidBoolean['status']);
    }

    private function upsertAccount(int $supplierId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency,
                 account_canonical, kind, source, is_active)
             VALUES (?, "API test účet", "1000000005", "0100", "0100", "CZK",
                     "1000000005", "current", "manual", 1)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), label = VALUES(label), is_active = 1'
        )->execute([$supplierId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $args
     * @return array{status:int,body:array<string,mixed>}
     */
    private function invoke(
        SupplierBankAccountAction $action,
        string $method,
        string $httpMethod,
        string $path,
        array $body = [],
        array $args = [],
    ): array {
        $request = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);
        $response = $method === 'update'
            ? $action->update($request, new Psr7Response(), $args)
            : $action->list($request, new Psr7Response());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
