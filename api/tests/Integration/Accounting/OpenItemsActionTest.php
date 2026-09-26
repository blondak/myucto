<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Reports\OpenItemsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * HTTP vrstva otevřených položek: čtení, založení okruhu, validace a právo zápisu.
 */
#[Group('integration')]
final class OpenItemsActionTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private OpenItemsAction $action;
    private PostingService $posting;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db      = $container->get(Connection::class);
            $this->action  = $container->get(OpenItemsAction::class);
            $this->posting = $container->get(PostingService::class);
            $periods       = $container->get(AccountingPeriodRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $base = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($base === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT "Párování HTTP s.r.o.", "Testovací", "Praha", "11000", country_id, "izolace@example.com", default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        )->execute([$base]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    public function testReadCreateAndValidate(): void
    {
        $transit = $this->accountId('261');
        $a = $this->manual('261', '221', 250.00, '-04-01');
        $b = $this->manual('221', '261', 250.00, '-04-02');

        $get = $this->action->get($this->req('GET', ['as_of' => self::YEAR . '-12-31']), new Psr7Response(), ['accountId' => (string) $transit]);
        self::assertSame(200, $get->getStatusCode());
        $body = $this->decode($get);
        self::assertSame(2, $body['open_count']);
        self::assertSame(0.0, (float) $body['difference']);

        $bad = $this->action->get($this->req('GET', ['as_of' => '31.12.2099']), new Psr7Response(), ['accountId' => (string) $transit]);
        self::assertSame(422, $bad->getStatusCode());

        $lines = [$this->lineId($a, '261'), $this->lineId($b, '261')];
        $created = $this->action->create($this->req('POST', [], ['line_ids' => $lines]), new Psr7Response(), ['accountId' => (string) $transit]);
        self::assertSame(201, $created->getStatusCode());
        $pairing = $this->decode($created);
        self::assertTrue($pairing['balanced']);

        $again = $this->action->create($this->req('POST', [], ['line_ids' => $lines]), new Psr7Response(), ['accountId' => (string) $transit]);
        self::assertSame(409, $again->getStatusCode());

        $detail = $this->action->pairing($this->req('GET'), new Psr7Response(), ['id' => (string) $pairing['id']]);
        self::assertSame(200, $detail->getStatusCode());
        self::assertCount(2, $this->decode($detail)['items']);

        $deleted = $this->action->delete($this->req('POST', [], ['pairing_ids' => [$pairing['id']]]), new Psr7Response());
        self::assertSame(200, $deleted->getStatusCode());
        self::assertSame(1, $this->decode($deleted)['deleted']);
    }

    public function testReadOnlyUserCannotPair(): void
    {
        $transit = $this->accountId('261');
        $a = $this->manual('261', '221', 10.00, '-04-01');
        $b = $this->manual('221', '261', 10.00, '-04-02');

        $resp = $this->action->create(
            $this->req('POST', [], ['line_ids' => [$this->lineId($a, '261'), $this->lineId($b, '261')]], 'readonly'),
            new Psr7Response(),
            ['accountId' => (string) $transit],
        );
        self::assertSame(403, $resp->getStatusCode());
    }

    /**
     * @param array<string,string> $query
     * @param array<string,mixed>|null $body
     */
    private function req(string $method, array $query = [], ?array $body = null, string $role = 'accountant'): ServerRequestInterface
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/accounting/open-items')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
        return $body === null ? $req : $req->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function decode(ResponseInterface $resp): array
    {
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function accountId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$this->supplierId, $code]);
        return (int) $stmt->fetchColumn();
    }

    private function lineId(int $entryId, string $code): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE l.entry_id = ? AND ca.account_code = ?'
        );
        $stmt->execute([$entryId, $code]);
        return (int) $stmt->fetchColumn();
    }

    private function manual(string $debit, string $credit, float $amount, string $monthDay): int
    {
        return $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => self::YEAR . $monthDay, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
    }
}
