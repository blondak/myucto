<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Note\CreateJournalNoteAction;
use MyInvoice\Action\Accounting\Note\DeleteJournalNoteAction;
use MyInvoice\Action\Accounting\Note\ListJournalNotesAction;
use MyInvoice\Action\Accounting\Note\PatchJournalNoteAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryNoteRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Poznámky účetního zápisu (1:N, migrace 1129) — CRUD happy path, soft delete,
 * připnutí a řazení, cross-entry/cross-tenant izolace, validace a RBAC.
 *
 * Klíčová vlastnost featury, kterou test hlídá: poznámku lze psát i k zápisu,
 * jehož `description` je řízený zdrojovým dokladem a editovat ho NELZE.
 *
 * DB běží v transakci (rollback v tearDown).
 */
#[Group('integration')]
final class JournalNotesTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private JournalEntryRepository $journal;
    private JournalEntryNoteRepository $notes;
    private AccountingPeriodRepository $periods;
    private ListJournalNotesAction $listAction;
    private CreateJournalNoteAction $createAction;
    private PatchJournalNoteAction $patchAction;
    private DeleteJournalNoteAction $deleteAction;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $accountId = 0;
    private int $entryA = 0;
    private int $entryB = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db           = $container->get(Connection::class);
            $this->journal      = $container->get(JournalEntryRepository::class);
            $this->notes        = $container->get(JournalEntryNoteRepository::class);
            $this->periods      = $container->get(AccountingPeriodRepository::class);
            $this->listAction   = $container->get(ListJournalNotesAction::class);
            $this->createAction = $container->get(CreateJournalNoteAction::class);
            $this->patchAction  = $container->get(PatchJournalNoteAction::class);
            $this->deleteAction = $container->get(DeleteJournalNoteAction::class);
            $seeder             = $container->get(ChartOfAccountsSeeder::class);
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
        $this->periodId  = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->accountId = (int) $pdo->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '211' LIMIT 1"
        )->fetchColumn();
        if ($this->accountId === 0) {
            $this->markTestSkipped('Osnova nemá účet 211.');
        }
        $this->entryA = $this->makeEntry();
        $this->entryB = $this->makeEntry();
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

    public function testCreateListUpdateDeleteHappyPath(): void
    {
        $created = $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => (string) $this->entryA], ['body' => 'Zkontrolovat DUZP u dodavatele.']);
        self::assertSame(201, $created['status']);
        self::assertSame('Zkontrolovat DUZP u dodavatele.', $created['body']['body']);
        self::assertFalse($created['body']['pinned']);
        self::assertSame($this->userId, $created['body']['created_by']);
        self::assertNull($created['body']['updated_at']);
        $noteId = (int) $created['body']['id'];

        $list = $this->invokeJson($this->listAction, 'GET', 'accountant', ['id' => (string) $this->entryA]);
        self::assertSame(200, $list['status']);
        self::assertCount(1, $list['body']['items']);
        self::assertSame(1, $list['body']['total']);

        $patched = $this->invokeJson($this->patchAction, 'PATCH', 'accountant',
            ['id' => (string) $this->entryA, 'noteId' => (string) $noteId], ['body' => 'DUZP ověřeno, sedí.']);
        self::assertSame(200, $patched['status']);
        self::assertSame('DUZP ověřeno, sedí.', $patched['body']['body']);
        self::assertNotNull($patched['body']['updated_at'], 'Editace zapíše updated_at.');
        self::assertSame($this->userId, $patched['body']['updated_by']);

        $deleted = $this->invokeJson($this->deleteAction, 'DELETE', 'accountant',
            ['id' => (string) $this->entryA, 'noteId' => (string) $noteId]);
        self::assertSame(200, $deleted['status']);
        self::assertSame($noteId, $deleted['body']['deleted']);
        self::assertCount(0, $this->notes->list($this->entryA, $this->supplierId));
    }

    public function testDeleteIsSoftAndRowSurvives(): void
    {
        // Tabulka NENÍ system-versioned (viz migrace 1129) — dohledatelnost stojí
        // právě na tom, že smazaný řádek fyzicky zůstane s deleted_at.
        $created = $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => (string) $this->entryA], ['body' => 'Ke smazání.']);
        $noteId = (int) $created['body']['id'];

        $this->invokeJson($this->deleteAction, 'DELETE', 'accountant',
            ['id' => (string) $this->entryA, 'noteId' => (string) $noteId]);

        $row = $this->db->pdo()->query(
            "SELECT body, deleted_at FROM journal_entry_notes WHERE id = {$noteId}"
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'Řádek zůstává v DB (soft delete).');
        self::assertNotNull($row['deleted_at']);
        self::assertSame('Ke smazání.', $row['body']);

        // Z API je pryč — druhé smazání i čtení vrací 404.
        self::assertNull($this->notes->find($noteId, $this->entryA, $this->supplierId));
        $again = $this->invokeJson($this->deleteAction, 'DELETE', 'accountant',
            ['id' => (string) $this->entryA, 'noteId' => (string) $noteId]);
        self::assertSame(404, $again['status']);
    }

    public function testPinnedNotesSortFirst(): void
    {
        $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => (string) $this->entryA], ['body' => 'Obyčejná první.']);
        $pinned = $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => (string) $this->entryA], ['body' => 'Připnutá.', 'pinned' => true]);
        self::assertTrue($pinned['body']['pinned']);
        $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => (string) $this->entryA], ['body' => 'Obyčejná druhá.']);

        $items = $this->invokeJson($this->listAction, 'GET', 'accountant',
            ['id' => (string) $this->entryA])['body']['items'];
        self::assertCount(3, $items);
        self::assertSame('Připnutá.', $items[0]['body'], 'Připnuté jdou vždy nahoru.');
        self::assertTrue($items[0]['pinned']);
    }

    public function testTogglePinViaPatch(): void
    {
        $created = $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => (string) $this->entryA], ['body' => 'Text zůstane.']);
        $noteId = (int) $created['body']['id'];

        // PATCH je částečný: pinned bez body nesmí text přepsat.
        $res = $this->invokeJson($this->patchAction, 'PATCH', 'accountant',
            ['id' => (string) $this->entryA, 'noteId' => (string) $noteId], ['pinned' => true]);
        self::assertSame(200, $res['status']);
        self::assertTrue($res['body']['pinned']);
        self::assertSame('Text zůstane.', $res['body']['body']);
    }

    public function testNoteAllowedOnEntryWithReadOnlyDescription(): void
    {
        // Jádro featury: u zápisu ze zdrojového dokladu (invoice) NELZE editovat
        // description, ale poznámku napsat MUSÍ jít.
        $entry = $this->makeEntry('invoice', 987654);
        $res = $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => (string) $entry], ['body' => 'Faktura přišla s chybným VS, řešeno mailem.']);
        self::assertSame(201, $res['status']);
        self::assertCount(1, $this->notes->list($entry, $this->supplierId));
    }

    public function testCrossEntryAndCrossTenantIsolation(): void
    {
        $created = $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => (string) $this->entryA], ['body' => 'Patří jen zápisu A.']);
        $noteId = (int) $created['body']['id'];

        // Cross-entry: poznámka zápisu A není dosažitelná přes zápis B.
        self::assertNull($this->notes->find($noteId, $this->entryB, $this->supplierId));
        self::assertCount(0, $this->notes->list($this->entryB, $this->supplierId));
        $patch = $this->invokeJson($this->patchAction, 'PATCH', 'accountant',
            ['id' => (string) $this->entryB, 'noteId' => (string) $noteId], ['body' => 'hack']);
        self::assertSame(404, $patch['status']);
        $del = $this->invokeJson($this->deleteAction, 'DELETE', 'accountant',
            ['id' => (string) $this->entryB, 'noteId' => (string) $noteId]);
        self::assertSame(404, $del['status']);

        // Cross-tenant: cizí dodavatel nevidí nic.
        self::assertNull($this->notes->find($noteId, $this->entryA, $this->supplierId + 99999));
        self::assertCount(0, $this->notes->list($this->entryA, $this->supplierId + 99999));
    }

    public function testUnknownEntryReturns404(): void
    {
        $res = $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => '999999999'], ['body' => 'nikam']);
        self::assertSame(404, $res['status']);
        self::assertSame('not_found', $res['body']['error']['code']);
    }

    public function testValidationRejectsEmptyAndOversizedBody(): void
    {
        $missing = $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => (string) $this->entryA], ['pinned' => true]);
        self::assertSame(422, $missing['status']);

        $blank = $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => (string) $this->entryA], ['body' => "   \n  "]);
        self::assertSame(422, $blank['status']);
        self::assertSame('validation_failed', $blank['body']['error']['code']);

        $tooLong = $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => (string) $this->entryA],
            ['body' => str_repeat('x', JournalEntryNoteRepository::MAX_BODY_LENGTH + 1)]);
        self::assertSame(422, $tooLong['status']);

        // PATCH bez jediného měnitelného pole taky neprojde.
        $created = $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => (string) $this->entryA], ['body' => 'ok']);
        $noop = $this->invokeJson($this->patchAction, 'PATCH', 'accountant',
            ['id' => (string) $this->entryA, 'noteId' => (string) $created['body']['id']], []);
        self::assertSame(422, $noop['status']);
    }

    public function testReadonlyCannotWriteButCanRead(): void
    {
        $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => (string) $this->entryA], ['body' => 'viditelné pro readonly']);

        $create = $this->invokeJson($this->createAction, 'POST', 'readonly',
            ['id' => (string) $this->entryA], ['body' => 'nesmí projít']);
        self::assertSame(403, $create['status']);

        $list = $this->invokeJson($this->listAction, 'GET', 'readonly', ['id' => (string) $this->entryA]);
        self::assertSame(200, $list['status'], 'Čtení poznámek je readonly+.');
        self::assertCount(1, $list['body']['items']);
    }

    public function testCreateIsAudited(): void
    {
        $this->invokeJson($this->createAction, 'POST', 'accountant',
            ['id' => (string) $this->entryA], ['body' => 'auditovaná']);

        $logged = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log WHERE supplier_id = {$this->supplierId}
              AND action = 'accounting.journal_note_created' AND entity_id = {$this->entryA}"
        )->fetchColumn();
        self::assertSame(1, $logged);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeEntry(string $sourceType = 'manual', ?int $sourceId = null): int
    {
        return $this->journal->insert(
            [
                'supplier_id' => $this->supplierId,
                'period_id'   => $this->periodId,
                'entry_date'  => self::YEAR . '-06-15',
                'source_type' => $sourceType,
                'source_id'   => $sourceId,
                'posted_at'   => date('Y-m-d H:i:s'),
                'posted_by'   => $this->userId,
            ],
            [
                ['account_id' => $this->accountId, 'side' => 'debit', 'amount' => 100.0],
                ['account_id' => $this->accountId, 'side' => 'credit', 'amount' => 100.0],
            ],
        );
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function invokeJson(object $action, string $httpMethod, string $role, array $args, array $body = []): array
    {
        $req = $this->request($httpMethod, $role);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        return $this->decode($action->__invoke($req, new Psr7Response(), $args));
    }

    private function request(string $httpMethod, string $role): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function decode(\Psr\Http\Message\ResponseInterface $resp): array
    {
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
