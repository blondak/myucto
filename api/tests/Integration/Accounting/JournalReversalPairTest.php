<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Smazání celé storno dvojice z deníku a filtr na stav stornování.
 *
 * Storno je správná cesta, jak zrušit účinek zápisu, ale v deníku po něm zůstane
 * dvojice, která se vzájemně ruší. U zápisu, který v účetnictví nikdy neměl vzniknout
 * (duplicitní bankovní pohyb po přepojení konektoru), je to jen šum — a v otevřeném
 * období ho jde odstranit, aniž by se změnil jediný zůstatek.
 *
 * Test hlídá obojí: že se dvojice smaže CELÁ a z obou stran, a že brány drží —
 * zavřené období, uzamčené datum a navazující storno se musí odmítnout.
 */
#[Group('integration')]
final class JournalReversalPairTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private JournalAction $journalAction;
    private OtherItemService $otherItems;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db            = $container->get(Connection::class);
            $this->journalAction = $container->get(JournalAction::class);
            $this->otherItems    = $container->get(OtherItemService::class);
            $this->periods       = $container->get(AccountingPeriodRepository::class);
            $seeder              = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    public function testDeletesBothEntriesAndTheirLines(): void
    {
        [$entryId, $reversalId] = $this->reversedPair('Duplicitní pohyb');

        $res = $this->call('deleteReversalPair', 'DELETE', 'accountant', ['id' => (string) $entryId]);

        self::assertSame(200, $res['status'], (string) json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame([$entryId, $reversalId], $res['body']['deleted_entry_ids']);
        self::assertSame(0, $this->countEntries([$entryId, $reversalId]), 'Z deníku musí zmizet obě strany.');
        self::assertSame(0, $this->countLines([$entryId, $reversalId]), 'S hlavičkami padnou i řádky.');

        $audit = $this->db->pdo()->query(
            "SELECT payload FROM activity_log
              WHERE supplier_id = {$this->supplierId} AND action = 'accounting.reversal_pair_deleted'
                AND entity_type = 'journal_entry' AND entity_id = {$entryId}
              ORDER BY id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($audit, 'Smazání dvojice musí zůstat dohledatelné v auditu.');
        $payload = json_decode((string) $audit['payload'], true);
        self::assertSame($reversalId, (int) $payload['reversal_entry_id']);
        self::assertCount(2, $payload['lines'], 'Audit nese zrušené řádky obou zápisů.');
    }

    public function testPairCanBeDeletedFromTheReversalSideToo(): void
    {
        [$entryId, $reversalId] = $this->reversedPair('Z druhé strany');

        $res = $this->call('deleteReversalPair', 'DELETE', 'accountant', ['id' => (string) $reversalId]);

        self::assertSame(200, $res['status'], (string) json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->countEntries([$entryId, $reversalId]));
    }

    public function testEntryWithoutReversalIsRejected(): void
    {
        $entryId = $this->manualEntry('Bez storna');

        $res = $this->call('deleteReversalPair', 'DELETE', 'accountant', ['id' => (string) $entryId]);

        self::assertSame(409, $res['status']);
        self::assertSame('entry_not_reversed', $res['body']['error']['code']);
        self::assertSame(1, $this->countEntries([$entryId]));
    }

    public function testClosedPeriodIsRejected(): void
    {
        [$entryId, $reversalId] = $this->reversedPair('V zavřeném období');
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $res = $this->call('deleteReversalPair', 'DELETE', 'accountant', ['id' => (string) $entryId]);

        self::assertSame(409, $res['status']);
        self::assertSame('period_not_open', $res['body']['error']['code']);
        self::assertSame(2, $this->countEntries([$entryId, $reversalId]), 'Zavřené období se nesmí dotknout.');
    }

    public function testLockedDateIsRejected(): void
    {
        [$entryId, $reversalId] = $this->reversedPair('V uzamčené části');
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, self::YEAR . '-12-31']);

        $res = $this->call('deleteReversalPair', 'DELETE', 'accountant', ['id' => (string) $entryId]);

        self::assertSame(409, $res['status']);
        self::assertSame('date_locked', $res['body']['error']['code']);
        self::assertTrue($res['body']['error']['can_acknowledge'] ?? false, 'Uzamčené datum jde vědomě přehlasovat.');
        self::assertSame(2, $this->countEntries([$entryId, $reversalId]));
    }

    public function testLockedDatePairIsDeletedAfterAcknowledgement(): void
    {
        [$entryId, $reversalId] = $this->reversedPair('Uzamčeno, potvrzeno');
        $this->lockYear();

        $res = $this->call('deleteReversalPair', 'DELETE', 'accountant', ['id' => (string) $entryId], [], ['ack_locked' => '1']);

        self::assertSame(200, $res['status'], (string) json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->countEntries([$entryId, $reversalId]));
        self::assertSame(self::YEAR . '-12-31', $this->auditPayload('accounting.reversal_pair_deleted', $entryId)['locked_override'] ?? null);
    }

    public function testLockedDateSingleEntryNeedsAcknowledgement(): void
    {
        $entryId = $this->manualEntry('Jeden zápis v uzamčené části');
        $this->lockYear();

        $refused = $this->call('delete', 'DELETE', 'accountant', ['id' => (string) $entryId]);
        self::assertSame(409, $refused['status']);
        self::assertSame('date_locked', $refused['body']['error']['code']);
        self::assertSame(1, $this->countEntries([$entryId]));

        $res = $this->call('delete', 'DELETE', 'accountant', ['id' => (string) $entryId], [], ['ack_locked' => '1']);
        self::assertSame(200, $res['status'], (string) json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->countEntries([$entryId]));
        self::assertSame(self::YEAR . '-12-31', $this->auditPayload('accounting.entry_deleted', $entryId)['locked_override'] ?? null);
    }

    public function testClosedPeriodIsNotOverriddenByAcknowledgement(): void
    {
        $entryId = $this->manualEntry('Zavřené období');
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $res = $this->call('delete', 'DELETE', 'accountant', ['id' => (string) $entryId], [], ['ack_locked' => '1']);

        self::assertSame(409, $res['status']);
        self::assertSame('period_not_open', $res['body']['error']['code']);
        self::assertSame(1, $this->countEntries([$entryId]));
    }

    /** Zúčtování DPH se nestornuje, přepisuje a maže se na místě — smí ho smazat i účetní. */
    public function testVatClearingEntryCanBeDeleted(): void
    {
        $entryId = $this->manualEntry('Zúčtování DPH');
        $this->db->pdo()->prepare("UPDATE journal_entries SET source_type = 'vat_clearing', source_id = ? WHERE id = ?")
            ->execute([(int) (self::YEAR . '061'), $entryId]);

        $res = $this->call('delete', 'DELETE', 'accountant', ['id' => (string) $entryId]);

        self::assertSame(200, $res['status'], (string) json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->countEntries([$entryId]));
    }

    public function testVatClearingReversalPairCanBeDeleted(): void
    {
        [$entryId, $reversalId] = $this->reversedPair('Stornované zúčtování DPH');
        $this->db->pdo()->prepare("UPDATE journal_entries SET source_type = 'vat_clearing' WHERE id IN (?, ?)")
            ->execute([$entryId, $reversalId]);
        $this->db->pdo()->prepare('UPDATE journal_entries SET source_id = ? WHERE id = ?')
            ->execute([(int) (self::YEAR . '061'), $entryId]);

        $res = $this->call('deleteReversalPair', 'DELETE', 'accountant', ['id' => (string) $entryId]);

        self::assertSame(200, $res['status'], (string) json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->countEntries([$entryId, $reversalId]));
    }

    public function testChainedReversalIsRejected(): void
    {
        // Storno storna je samostatná dvojice — řetěz se rozplétá odzadu.
        [$entryId, $reversalId] = $this->reversedPair('Řetěz');
        $second = $this->call('reverse', 'POST', 'accountant', ['id' => (string) $reversalId], ['entry_date' => self::YEAR . '-06-30']);
        self::assertSame(201, $second['status']);

        $res = $this->call('deleteReversalPair', 'DELETE', 'accountant', ['id' => (string) $entryId]);

        self::assertSame(409, $res['status']);
        self::assertSame('reversal_chain', $res['body']['error']['code']);
        self::assertSame(2, $this->countEntries([$entryId, $reversalId]));
    }

    public function testReadonlyCannotDeletePair(): void
    {
        [$entryId, $reversalId] = $this->reversedPair('Bez práv');

        $res = $this->call('deleteReversalPair', 'DELETE', 'readonly', ['id' => (string) $entryId]);

        self::assertSame(403, $res['status']);
        self::assertSame(2, $this->countEntries([$entryId, $reversalId]));
    }

    public function testOtherItemEntryMustBeReversedFromItsDetail(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $draft = $this->otherItems->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetické nájemné',
            'issued_on' => self::YEAR . '-06-15', 'accounting_on' => self::YEAR . '-06-15',
            'due_on' => self::YEAR . '-06-30', 'currency' => 'CZK', 'amount' => 1200,
            'counter_account_code' => '518',
        ], $this->userId);
        $posted = $this->otherItems->post($this->supplierId, (int) $draft['id'], $this->userId);
        $entryId = (int) $posted['journal_entry_id'];

        $res = $this->call('reverse', 'POST', 'accountant', ['id' => (string) $entryId],
            ['entry_date' => self::YEAR . '-06-16']);

        self::assertSame(409, $res['status'], (string) json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('other_item_use_detail', $res['body']['error']['code']);
        self::assertStringContainsString('detail', $res['body']['error']['message']);
        self::assertSame('posted', $this->otherItems->get($this->supplierId, (int) $draft['id'])['status']);
        self::assertSame(1, $this->countEntries([$entryId]));

        $reversed = $this->otherItems->reverse($this->supplierId, (int) $draft['id'],
            'Oprava syntetického dokladu', $this->userId);
        self::assertSame('reversed', $reversed['status']);
        self::assertGreaterThan(0, (int) $reversed['reversal_entry_id']);
    }

    public function testReversalFilterSeparatesBothSidesOfThePair(): void
    {
        [$entryId, $reversalId] = $this->reversedPair('Filtr storna');
        $plainId = $this->manualEntry('Nestornovaný');

        $reversed = $this->listIds(['reversal' => 'reversed']);
        self::assertContains($entryId, $reversed, 'Stornovaný zápis musí filtr najít.');
        self::assertNotContains($reversalId, $reversed);
        self::assertNotContains($plainId, $reversed);

        $reversals = $this->listIds(['reversal' => 'reversal']);
        self::assertContains($reversalId, $reversals, 'Protizápis musí filtr najít.');
        self::assertNotContains($entryId, $reversals);

        $any = $this->listIds(['reversal' => 'any']);
        self::assertContains($entryId, $any);
        self::assertContains($reversalId, $any);
        self::assertNotContains($plainId, $any);

        $none = $this->listIds(['reversal' => 'none']);
        self::assertContains($plainId, $none);
        self::assertNotContains($entryId, $none);
        self::assertNotContains($reversalId, $none);
    }

    private function lockYear(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, self::YEAR . '-12-31']);
    }

    /** @return array<string,mixed> */
    private function auditPayload(string $action, int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE supplier_id = ? AND action = ? AND entity_type = 'journal_entry' AND entity_id = ?
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$this->supplierId, $action, $entryId]);
        return (array) json_decode((string) $stmt->fetchColumn(), true);
    }

    /** @return array{0:int,1:int} [původní zápis, jeho protizápis] */
    private function reversedPair(string $description): array
    {
        $entryId = $this->manualEntry($description);
        $rev = $this->call('reverse', 'POST', 'accountant', ['id' => (string) $entryId], ['entry_date' => self::YEAR . '-06-15']);
        self::assertSame(201, $rev['status'], 'Fixture: zápis se stornuje.');
        return [$entryId, (int) $rev['body']['id']];
    }

    private function manualEntry(string $description): int
    {
        $res = $this->call('create', 'POST', 'accountant', [], [
            'entry_date'  => self::YEAR . '-06-15',
            'description' => $description,
            'lines' => [
                ['account_code' => '211', 'side' => 'debit', 'amount' => 1000.00],
                ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.00],
            ],
        ]);
        self::assertSame(201, $res['status'], 'Fixture: manuální zápis se založí.');
        return (int) $res['body']['id'];
    }

    /** @param list<int> $ids */
    private function countEntries(array $ids): int
    {
        $in = implode(',', array_map('intval', $ids));
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId} AND id IN ({$in})"
        )->fetchColumn();
    }

    /** @param list<int> $ids */
    private function countLines(array $ids): int
    {
        $in = implode(',', array_map('intval', $ids));
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entry_lines WHERE supplier_id = {$this->supplierId} AND entry_id IN ({$in})"
        )->fetchColumn();
    }

    /**
     * @param array<string,string> $query
     * @return list<int>
     */
    private function listIds(array $query): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/journal')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withQueryParams($query + ['period_id' => (string) $this->periodId, 'per_page' => '200']);
        $resp = $this->journalAction->list($req, new Psr7Response());
        $resp->getBody()->rewind();
        $body = json_decode((string) $resp->getBody(), true);
        return array_map(static fn (array $row): int => (int) $row['id'], $body['items'] ?? []);
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @param array<string,string> $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, string $httpMethod, string $role, array $args = [], array $body = [], array $query = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withQueryParams($query);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        $resp = $args === []
            ? $this->journalAction->{$method}($req, new Psr7Response())
            : $this->journalAction->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
