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

    /**
     * Výpis nastavení dohraje chybějící analytiky — účet zaevidovaný z výpisu se v UI
     * nesmí ukázat bez čísla, protože jinak by jeho pohyby padaly na plochou 221.
     */
    public function testListAssignsMissingAnalytics(): void
    {
        $id = $this->upsertAccount($this->supplierId, '1000000005', null);
        $action = $this->container->get(SupplierBankAccountAction::class);

        $list = $this->invoke($action, 'list', 'GET', '/api/accounting/bank-accounts');
        self::assertSame(200, $list['status']);

        $row = null;
        foreach ($list['body']['accounts'] as $account) {
            if ((int) $account['id'] === $id) {
                $row = $account;
            }
        }
        self::assertNotNull($row);
        self::assertNotNull($row['analytic_suffix'], 'Účet dostal analytiku už při výpisu nastavení.');
        self::assertNotNull(
            $this->accounts->findByCode($this->supplierId, '221.' . $row['analytic_suffix']),
            'Přidělená analytika existuje i v osnově.',
        );
    }

    /** Jedno číslo = jeden účet; jinak by se na analytice sešly dva různé zůstatky. */
    public function testDuplicateAnalyticIsRejected(): void
    {
        $first  = $this->upsertAccount($this->supplierId, '1000000005', '601');
        $second = $this->upsertAccount($this->supplierId, '1000000013', null);
        $action = $this->container->get(SupplierBankAccountAction::class);

        $clash = $this->invoke($action, 'update', 'PATCH', '/api/accounting/bank-accounts/' . $second,
            ['analytic_suffix' => '601'], ['id' => (string) $second]);

        self::assertSame(422, $clash['status']);
        self::assertSame('601', $this->bankAccountRow($first)['analytic_suffix']);
        self::assertNull($this->bankAccountRow($second)['analytic_suffix']);
    }

    /** Analytiku s historií nelze zrušit — jen změnit na jiné číslo. */
    public function testClearingAssignedAnalyticIsRejected(): void
    {
        $id = $this->upsertAccount($this->supplierId, '1000000005', '602');
        $action = $this->container->get(SupplierBankAccountAction::class);

        $cleared = $this->invoke($action, 'update', 'PATCH', '/api/accounting/bank-accounts/' . $id,
            ['analytic_suffix' => ''], ['id' => (string) $id]);

        self::assertSame(422, $cleared['status']);
        self::assertSame('602', $this->bankAccountRow($id)['analytic_suffix']);
    }

    /** Prázdné pole u účtu BEZ analytiky znamená „přiděl automaticky", ne „nech prázdné". */
    public function testEmptySuffixAssignsAutomatically(): void
    {
        $id = $this->upsertAccount($this->supplierId, '1000000005', null);
        $action = $this->container->get(SupplierBankAccountAction::class);

        $patched = $this->invoke($action, 'update', 'PATCH', '/api/accounting/bank-accounts/' . $id,
            ['analytic_suffix' => ''], ['id' => (string) $id]);

        self::assertSame(200, $patched['status']);
        self::assertMatchesRegularExpression('/^[0-9]{1,6}$/', (string) $patched['body']['analytic_suffix']);
    }

    /** @return array<string,mixed> */
    private function bankAccountRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM supplier_bank_accounts WHERE id = ?');
        $stmt->execute([$id]);
        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function upsertAccount(int $supplierId, string $canonical = '1000000005', ?string $suffix = null): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency,
                 account_canonical, kind, analytic_suffix, source, is_active)
             VALUES (?, "API test účet", ?, "0100", "0100", "CZK",
                     ?, "current", ?, "manual", 1)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), label = VALUES(label), is_active = 1'
        )->execute([$supplierId, $canonical, $canonical, $suffix]);
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
