<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientBankAccountRepository;
use MyInvoice\Service\Bank\Match\CounterpartyMapService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class ClientBankAccountRepositoryTest extends TestCase
{
    private PDO $pdo;
    private ClientBankAccountRepository $accounts;
    private CounterpartyMapService $counterpartyMap;
    private BankStatementAction $statements;
    private int $supplierId;
    private int $clientId;
    private int $currencyId;
    private string $ownAccount;
    private ?string $ownBankCode;
    private ?int $statementId = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }

        $container = Bootstrap::buildApp()->getContainer();
        self::assertNotNull($container);
        $this->pdo = $container->get(Connection::class)->pdo();
        $this->accounts = $container->get(ClientBankAccountRepository::class);
        $this->counterpartyMap = $container->get(CounterpartyMapService::class);
        $this->statements = $container->get(BankStatementAction::class);

        $currency = $this->pdo->query(
            "SELECT id, supplier_id, account_number, bank_code
               FROM currencies
              WHERE account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if ($currency === false) {
            $this->markTestSkipped('Configured bank account missing');
        }
        $this->supplierId = (int) $currency['supplier_id'];
        $this->currencyId = (int) $currency['id'];
        $this->ownAccount = (string) $currency['account_number'];
        $this->ownBankCode = $currency['bank_code'] !== null ? (string) $currency['bank_code'] : null;
        $countryId = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        if ($this->supplierId <= 0 || $countryId <= 0 || $this->currencyId <= 0) {
            $this->markTestSkipped('Supplier, CZ country or currency missing');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "bank-account@example.test", "cs", ?, 1, 1)'
        );
        $stmt->execute([$this->supplierId, 'Bank account integration ' . bin2hex(random_bytes(4)), $countryId, $this->currencyId]);
        $this->clientId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo, $this->clientId) && $this->clientId > 0) {
            $this->pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        if (isset($this->pdo, $this->statementId) && $this->statementId !== null) {
            $this->pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$this->statementId]);
        }
    }

    public function testSameAccountMergesSourcesAndCanBeReactivated(): void
    {
        $manual = $this->accounts->addManual($this->clientId, $this->supplierId, [
            'account_number' => '00001000000005/0100',
        ]);
        self::assertTrue($manual['source_manual']);
        self::assertFalse($manual['source_vat_registry']);

        self::assertSame(1, $this->accounts->syncVatRegistry($this->clientId, $this->supplierId, [[
            'prefix' => '',
            'number' => '1000000005',
            'bank_code' => '0100',
            'iban' => null,
        ]]));

        $rows = $this->accounts->listForClient($this->clientId, $this->supplierId);
        self::assertCount(1, $rows);
        self::assertTrue($rows[0]['source_manual']);
        self::assertTrue($rows[0]['source_vat_registry']);
        self::assertSame('0100', $rows[0]['bank_code']);

        self::assertTrue($this->accounts->deactivate((int) $rows[0]['id'], $this->clientId, $this->supplierId));
        self::assertSame([], $this->accounts->listForClient($this->clientId, $this->supplierId));

        $reactivated = $this->accounts->addManual($this->clientId, $this->supplierId, [
            'account_number' => '1000000005',
            'bank_code' => '0100',
        ]);
        self::assertSame($rows[0]['id'], $reactivated['id']);
        self::assertTrue($reactivated['is_active']);
        self::assertCount(1, $this->accounts->listForClient($this->clientId, $this->supplierId));
    }

    public function testAccountsAreSupplierScoped(): void
    {
        $this->accounts->addManual($this->clientId, $this->supplierId, [
            'account_number' => '1000000005',
            'bank_code' => '0100',
        ]);

        self::assertSame([], $this->accounts->listForClient($this->clientId, $this->supplierId + 100000));
        $this->expectException(\InvalidArgumentException::class);
        $this->accounts->addManual($this->clientId, $this->supplierId + 100000, [
            'account_number' => '1000000005',
            'bank_code' => '0100',
        ]);
    }

    public function testCzechIbanMergesWithTheSameDomesticAccount(): void
    {
        self::assertSame(1, $this->accounts->syncVatRegistry($this->clientId, $this->supplierId, [[
            'prefix' => '',
            'number' => '1000000005',
            'bank_code' => '0100',
            'iban' => 'CZ1801000000001000000005',
        ]]));

        $manual = $this->accounts->addManual($this->clientId, $this->supplierId, [
            'account_number' => '1000000005/0100',
        ]);
        $rows = $this->accounts->listForClient($this->clientId, $this->supplierId);

        self::assertCount(1, $rows);
        self::assertSame($manual['id'], $rows[0]['id']);
        self::assertTrue($rows[0]['source_manual']);
        self::assertTrue($rows[0]['source_vat_registry']);
        self::assertSame('CZ1801000000001000000005', $rows[0]['iban']);
    }

    public function testMatchingLearningReusesPartnerAccountAndDemotesOnContradiction(): void
    {
        $manual = $this->accounts->addManual($this->clientId, $this->supplierId, [
            'account_number' => '1000000005',
            'bank_code' => '0100',
        ]);

        $marker = 'bank-learning-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, 'CZK', '2099-07-15')"
        )->execute([$marker . '.gpc', hash('sha256', $marker), $this->ownAccount, $this->ownBankCode]);
        $this->statementId = (int) $this->pdo->lastInsertId();
        $transactionIds = [];
        for ($i = 0; $i < 3; $i++) {
            $this->pdo->prepare(
                "INSERT INTO bank_transactions
                    (statement_id, posted_at, amount, currency, counterparty_account, counterparty_bank)
                 VALUES (?, '2099-07-15', ?, 'CZK', '00001000000005', '0100')"
            )->execute([$this->statementId, -1000.00 - $i]);
            $transactionIds[] = (int) $this->pdo->lastInsertId();
        }

        for ($i = 0; $i < 3; $i++) {
            $this->counterpartyMap->record(
                $this->supplierId,
                '00001000000005/0100',
                '',
                'outgoing',
                $this->clientId,
                true,
                transactionId: $transactionIds[0],
            );
        }

        self::assertSame(1, $this->counterpartyMap->lookup(
            $this->supplierId, '1000000005', '0100', 'outgoing',
        )['match_count']);
        foreach (array_slice($transactionIds, 1) as $transactionId) {
            $this->counterpartyMap->record(
                $this->supplierId, '00001000000005/0100', '', 'outgoing',
                $this->clientId, true, transactionId: $transactionId,
            );
        }

        $learned = $this->counterpartyMap->lookup($this->supplierId, '1000000005', '0100', 'outgoing');
        self::assertNotNull($learned);
        self::assertTrue($learned['promoted']);
        self::assertSame(3, $learned['match_count']);
        self::assertCount(1, $this->accounts->listForClient($this->clientId, $this->supplierId));
        self::assertSame($manual['id'], $this->accounts->listForClient($this->clientId, $this->supplierId)[0]['id']);

        $this->counterpartyMap->recordContradiction(
            $this->supplierId,
            '1000000005',
            '0100',
            'outgoing',
            $this->clientId,
        );
        self::assertFalse($this->counterpartyMap->lookup(
            $this->supplierId,
            '1000000005',
            '0100',
            'outgoing',
        )['promoted']);
    }

    public function testStatementListFiltersByPartialAccountCounterpartyAndAbsoluteAmountInBothDirections(): void
    {
        $this->accounts->addManual($this->clientId, $this->supplierId, [
            'account_number' => '1000000005',
            'bank_code' => '0100',
        ]);

        $marker = 'bank-filter-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, 'CZK', '2099-07-15')"
        )->execute([$marker . '.gpc', hash('sha256', $marker), $this->ownAccount, $this->ownBankCode]);
        $this->statementId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, counterparty_account, counterparty_bank, counterparty_name)
             VALUES (?, '2099-07-15', -1500.25, 'CZK', '00001000000005', '0100', 'Synthetic Vendor')"
        )->execute([$this->statementId]);

        $this->pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, counterparty_account, counterparty_bank, counterparty_name)
             VALUES (?, '2099-07-16', 2750.50, 'CZK', '00001000000005', '0100', 'Synthetic Customer')"
        )->execute([$this->statementId]);

        $result = $this->callStatementList([
            'filter' => [
                'counterparty_account' => '000005',
                'client_id' => $this->clientId,
                'amount' => '1500.25',
            ],
        ]);
        self::assertSame(200, $result['status']);
        self::assertContains($this->statementId, array_column($result['body']['items'] ?? [], 'id'));
        $statementRow = array_values(array_filter(
            $result['body']['items'] ?? [],
            fn (array $item): bool => (int) $item['id'] === $this->statementId,
        ))[0];
        self::assertSame(2, $statementRow['unposted_count']);

        $unposted = $this->callStatementList(['filter' => ['posting_status' => 'unposted']]);
        self::assertContains($this->statementId, array_column($unposted['body']['items'] ?? [], 'id'));

        $incoming = $this->callStatementList(['filter' => [
            'client_id' => $this->clientId,
            'amount' => '2750.50',
        ]]);
        self::assertContains($this->statementId, array_column($incoming['body']['items'] ?? [], 'id'));

        $wrongAmount = $this->callStatementList(['filter' => ['amount' => '1500.26']]);
        self::assertNotContains($this->statementId, array_column($wrongAmount['body']['items'] ?? [], 'id'));

        $wildcard = $this->callStatementList(['filter' => ['counterparty_account' => '%_']]);
        self::assertSame(422, $wildcard['status']);
    }

    /** @param array<string,mixed> $query @return array{status:int,body:array<string,mixed>} */
    private function callStatementList(array $query): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/bank-statements')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withQueryParams($query);
        $response = $this->statements->list($request, new Response());
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($body) ? $body : [],
        ];
    }
}
