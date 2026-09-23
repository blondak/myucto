<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\OtherItemAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class OtherItemActionPaymentPermissionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private OtherItemAction $action;
    private OtherItemService $service;
    private int $supplierId;
    private int $itemId;
    private int $cashId;
    private int $bankId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $this->action = $container->get(OtherItemAction::class);
        $this->service = $container->get(OtherItemService::class);
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);
        $item = $this->service->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetický nájem',
            'issued_on' => '2099-01-01', 'due_on' => '2099-01-20', 'amount' => 1200,
            'currency' => 'CZK',
        ], null);
        $this->itemId = (int) $item['id'];
        $this->service->post($this->supplierId, $this->itemId, null);
        $this->pdo->prepare(
            "INSERT INTO cash_registers (supplier_id, name, currency_code, account_code)
             VALUES (?, 'Syntetická pokladna', 'CZK', '211')"
        )->execute([$this->supplierId]);
        $registerId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number,
                                         issue_date, description, total_amount, currency_code, status)
             VALUES (?, ?, 'out', 'other', 'SYNTH-CASH-OTHER-1', '2099-01-21',
                     'Syntetická hotovostní platba', 500, 'CZK', 'posted')"
        )->execute([$this->supplierId, $registerId]);
        $this->cashId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, statement_date, currency)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'synteticky-vypis', hash('sha256', uniqid('', true)),
            '1000000005/0100', '2099-01-20', 'CZK']);
        $statementId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, description)
             VALUES (?, '2099-01-20', -400, 'CZK', 'Syntetická bankovní platba')"
        )->execute([$statementId]);
        $this->bankId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testCandidatesRespectEachSourceReadPermission(): void
    {
        $bankOnly = $this->data($this->call('paymentCandidates', 'GET', [
            'other_items' => 1, 'bank' => 1,
        ]));
        self::assertSame(['bank'], array_values(array_unique(array_column($bankOnly['items'], 'source'))));
        self::assertSame([$this->bankId], array_column($bankOnly['items'], 'id'));
        self::assertSame([$this->bankId], array_column($this->data($this->call('paymentCandidates', 'GET',
            ['other_items' => 1, 'bank' => 1], [], null, ['limit' => 1]))['items'], 'id'));

        $cashOnly = $this->data($this->call('paymentCandidates', 'GET', [
            'other_items' => 1, 'cash' => 1,
        ]));
        self::assertSame(['cash'], array_values(array_unique(array_column($cashOnly['items'], 'source'))));
        self::assertSame([$this->cashId], array_column($cashOnly['items'], 'id'));
        self::assertSame(403, $this->call('paymentCandidates', 'GET', ['other_items' => 1])->getStatusCode());
    }

    public function testCashAllocationAndRemovalRequireCashDocumentWrite(): void
    {
        $bankMatcher = ['other_items' => 2, 'bank' => 1, 'bank.match' => 2];
        $cashWriter = ['other_items' => 2, 'cash' => 1, 'cash.document.write' => 2];
        $payload = ['cash_document_id' => $this->cashId, 'amount' => 200];

        self::assertSame(403, $this->call('allocate', 'POST', $bankMatcher, $payload)->getStatusCode());
        self::assertSame(403, $this->call('allocate', 'POST', $cashWriter,
            ['bank_transaction_id' => $this->bankId, 'amount' => 100])->getStatusCode());
        self::assertSame([], $this->service->allocations($this->supplierId, $this->itemId));

        $allocated = $this->call('allocate', 'POST', $cashWriter, $payload);
        self::assertSame(200, $allocated->getStatusCode());
        $allocationId = (int) $this->service->allocations($this->supplierId, $this->itemId)[0]['id'];
        self::assertSame([], $this->data($this->call('allocations', 'GET', $bankMatcher))['items']);

        self::assertSame(403, $this->call('unallocate', 'DELETE', $bankMatcher, [], $allocationId)->getStatusCode());
        self::assertCount(1, $this->service->allocations($this->supplierId, $this->itemId));
        self::assertSame(200, $this->call('unallocate', 'DELETE', $cashWriter, [], $allocationId)->getStatusCode());

        self::assertSame(200, $this->call('allocate', 'POST', $bankMatcher,
            ['bank_transaction_id' => $this->bankId, 'amount' => 100])->getStatusCode());
        $bankAllocationId = (int) $this->service->allocations($this->supplierId, $this->itemId)[0]['id'];
        self::assertSame(403, $this->call('unallocate', 'DELETE', $cashWriter, [], $bankAllocationId)->getStatusCode());
        self::assertSame(200, $this->call('unallocate', 'DELETE', $bankMatcher, [], $bankAllocationId)->getStatusCode());
    }

    public function testCashOnlyCandidateSurvivesMoreThanFiftyNewerBankPayments(): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM bank_statements WHERE supplier_id = ? LIMIT 1');
        $statement->execute([$this->supplierId]);
        $statementId = (int) $statement->fetchColumn();
        $insert = $this->pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, description)
             VALUES (?, '2099-01-22', -10, 'CZK', 'Syntetická novější bankovní platba')"
        );
        for ($i = 0; $i < 55; $i++) $insert->execute([$statementId]);

        $cashOnly = $this->data($this->call('paymentCandidates', 'GET', [
            'other_items' => 1, 'cash' => 1,
        ]));
        self::assertSame([$this->cashId], array_column($cashOnly['items'], 'id'));
        self::assertSame(['cash'], array_column($cashOnly['items'], 'source'));
    }

    private function call(string $method, string $http, array $permissions, array $body = [],
        ?int $allocationId = null, array $query = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($http, '/api/accounting/other-items/' . $this->itemId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, $permissions, 'custom'))
            ->withQueryParams($query)
            ->withParsedBody($body);
        $args = ['id' => (string) $this->itemId];
        if ($allocationId !== null) $args['allocation_id'] = (string) $allocationId;
        return $this->action->{$method}($request, new Response(), $args);
    }

    private function data(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        return $body['data'] ?? $body;
    }
}
