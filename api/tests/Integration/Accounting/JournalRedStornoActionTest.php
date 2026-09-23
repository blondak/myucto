<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class JournalRedStornoActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private JournalAction $action;
    private JournalEntryRepository $journal;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(JournalAction::class);
        $this->journal = $container->get(JournalEntryRepository::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $container->get(AccountingPeriodRepository::class)->create($this->supplierId, 2098, '2098-01-01', '2098-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testManualFormPayloadCreatesRedLinesAndRejectsUnsignedBalance(): void
    {
        $lines = [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 100.0, 'is_red_storno' => true],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 100.0, 'is_red_storno' => true],
        ];
        $response = $this->create($lines);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $id = (int) json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['id'];
        $saved = $this->journal->linesForEntry($id, $this->supplierId);
        self::assertSame([true, true], array_column($saved, 'is_red_storno'));
        self::assertSame(['debit', 'credit'], array_column($saved, 'side'));
        self::assertSame([100.0, 100.0], array_column($saved, 'amount'));

        $lines[1]['is_red_storno'] = false;
        self::assertSame(422, $this->create($lines)->getStatusCode(), 'Absolute amounts balance, signed amounts do not.');
        $lines[1]['is_red_storno'] = 'false';
        self::assertSame(422, $this->create($lines)->getStatusCode(), 'The flag must be a boolean, not a truthy string.');
        $query = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
        $query->execute([$this->supplierId]);
        self::assertSame(1, (int) $query->fetchColumn());
    }

    public function testDeletingManualRedEntryPreservesFlagInAuditSnapshot(): void
    {
        $response = $this->create([
            ['account_code' => '501', 'side' => 'debit', 'amount' => 25.0, 'is_red_storno' => true],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 25.0, 'is_red_storno' => true],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $id = (int) json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['id'];

        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/api/accounting/journal/' . $id)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, ['accounting' => 2]));
        $deleted = $this->action->delete($request, new Response(), ['id' => (string) $id]);
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());

        $audit = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE supplier_id = ? AND action = 'accounting.entry_deleted' AND entity_id = ?
              ORDER BY id DESC LIMIT 1"
        );
        $audit->execute([$this->supplierId, $id]);
        $payload = json_decode((string) $audit->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([true, true], array_column($payload['lines'], 'is_red_storno'));
    }

    private function create(array $lines): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/accounting/journal')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, ['accounting' => 2]))
            ->withParsedBody(['entry_date' => '2098-03-01', 'description' => 'Syntetické červené storno', 'lines' => $lines]);
        return $this->action->create($request, new Response());
    }
}
