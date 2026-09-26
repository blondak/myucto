<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction;
use MyInvoice\Action\Accounting\Note\CreateJournalNoteAction;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Banka ↔ deník: poznámka zápisu v přehledu pohybů a řazení pohybů podle sloupce.
 *
 * Poznámka žije jen u zápisu deníku (journal_entry_notes); pohyb ji ukazuje přes
 * `posting.journal_notes` a zakládá stejným endpointem jako deník. Test hlídá, že
 * cizí firma do přehledu neprosákne a že řazení jde přes whitelist na serveru.
 *
 * DB běží v transakci (rollback v tearDown).
 */
#[Group('integration')]
final class BankTransactionNotesAndSortTest extends TestCase
{
    private const YEAR = 2097;
    private const ACCOUNT = '9990561185';
    private const MARK = '__TEST-BANKNOTE';

    private Connection $db;
    private BankStatementAction $bank;
    private BankPostingSuggestionAction $unposted;
    private CreateJournalNoteAction $createNote;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $accountId = 0;
    private int $statementId = 0;
    /** @var array<string,int> */
    private array $tx = [];
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db         = $container->get(Connection::class);
            $this->bank       = $container->get(BankStatementAction::class);
            $this->unposted   = $container->get(BankPostingSuggestionAction::class);
            $this->createNote = $container->get(CreateJournalNoteAction::class);
            $this->journal    = $container->get(JournalEntryRepository::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
            $this->seeder     = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/uživatel v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $this->seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->accountId = (int) $pdo->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '221' LIMIT 1"
        )->fetchColumn();
        if ($this->accountId === 0) {
            $this->markTestSkipped('Osnova nemá účet 221.');
        }

        $pdo->prepare(
            "INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, account_number, bank_code, currency,
                 statement_number, statement_date, prev_balance, curr_balance, transaction_count, imported_by)
             VALUES (?, 'gpc', ?, ?, ?, '0100', 'CZK', '1', ?, 0, 0, 3, NULL)"
        )->execute([
            $this->supplierId,
            self::MARK . '.gpc',
            hash('sha256', self::MARK . uniqid('', true)),
            self::ACCOUNT,
            self::YEAR . '-03-31',
        ]);
        $this->statementId = (int) $pdo->lastInsertId();

        // Záměrně tak, aby každé řazení dalo jiné pořadí než datum.
        $this->tx['a'] = $this->addTx(self::YEAR . '-03-01', 500.0, '300', 'Cyril');
        $this->tx['b'] = $this->addTx(self::YEAR . '-03-02', -1500.0, '100', 'Adam');
        $this->tx['c'] = $this->addTx(self::YEAR . '-03-03', 2500.0, null, 'Bohumil');
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

    public function testPostedTransactionShowsJournalNoteCreatedThroughJournalApi(): void
    {
        $entryId = $this->postTx($this->tx['a']);

        // Pohyb zakládá poznámku týmž endpointem jako deník — žádné druhé úložiště.
        $created = $this->call($this->createNote, 'POST', 'accountant', ['id' => (string) $entryId], ['body' => 'Záloha na nájem, doklad dodá nájemce.']);
        self::assertSame(201, $created['status']);

        $detail = $this->detail([]);
        $byId = array_column($detail['transactions'], null, 'id');
        self::assertSame($entryId, $byId[$this->tx['a']]['posting']['journal_entry_id']);
        self::assertSame(
            ['Záloha na nájem, doklad dodá nájemce.'],
            array_column($byId[$this->tx['a']]['posting']['journal_notes'], 'body'),
        );
        // Nezaúčtovaný pohyb poznámku nemá kam uložit, žádné journal_notes nenese.
        self::assertArrayNotHasKey('journal_notes', (array) ($byId[$this->tx['b']]['posting'] ?? []));

        // Tatáž informace i na záložce „Všechny pohyby".
        $all = $this->unpostedList(['scope' => 'all', 'q' => self::MARK]);
        $allById = array_column($all['items'], null, 'id');
        self::assertSame(
            ['Záloha na nájem, doklad dodá nájemce.'],
            array_column($allById[$this->tx['a']]['posting']['journal_notes'], 'body'),
        );
    }

    public function testReadonlyCannotWriteNoteAndForeignTenantNoteDoesNotLeak(): void
    {
        $entryId = $this->postTx($this->tx['a']);

        $denied = $this->call($this->createNote, 'POST', 'readonly', ['id' => (string) $entryId], ['body' => 'nesmí projít']);
        self::assertSame(403, $denied['status']);

        // Cizí firma má „bankovní" zápis se stejným source_id jako náš pohyb b a na
        // něm poznámku. Do našeho přehledu nesmí prosáknout zápis ani poznámka.
        $foreign = $this->cloneSupplier();
        $this->seeder->seedForSupplier($foreign);
        $foreignPeriod = $this->periods->create($foreign, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $foreignAccount = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$foreign} AND account_code = '221' LIMIT 1"
        )->fetchColumn();
        $foreignEntry = $this->journal->insert(
            [
                'supplier_id' => $foreign, 'period_id' => $foreignPeriod, 'entry_date' => self::YEAR . '-03-15',
                'source_type' => 'bank', 'source_id' => $this->tx['b'],
                'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $this->userId,
            ],
            [
                ['account_id' => $foreignAccount, 'side' => 'debit', 'amount' => 100.0],
                ['account_id' => $foreignAccount, 'side' => 'credit', 'amount' => 100.0],
            ],
        );
        $this->db->pdo()->prepare(
            'INSERT INTO journal_entry_notes (entry_id, supplier_id, body, pinned, created_by) VALUES (?, ?, ?, 0, ?)'
        )->execute([$foreignEntry, $foreign, 'cizí poznámka', $this->userId]);

        $detail = $this->detail([]);
        $byId = array_column($detail['transactions'], null, 'id');
        self::assertSame([], $byId[$this->tx['a']]['posting']['journal_notes']);
        self::assertStringNotContainsString('cizí poznámka', (string) json_encode($detail, JSON_UNESCAPED_UNICODE));
        self::assertNotSame($foreignEntry, $byId[$this->tx['b']]['posting']['journal_entry_id'] ?? null);
    }

    public function testStatementDetailSortsOnServerByWhitelistedColumn(): void
    {
        $ids = fn (array $q): array => array_column($this->detail($q)['transactions'], 'id');

        self::assertSame([$this->tx['a'], $this->tx['b'], $this->tx['c']], $ids([]), 'Výchozí pořadí = datum vzestupně.');
        self::assertSame([$this->tx['c'], $this->tx['a'], $this->tx['b']], $ids(['sort' => 'amount', 'direction' => 'desc']));
        self::assertSame([$this->tx['b'], $this->tx['c'], $this->tx['a']], $ids(['sort' => 'counterparty', 'direction' => 'asc']));
        // Bez VS jde na konec v obou směrech.
        self::assertSame([$this->tx['b'], $this->tx['a'], $this->tx['c']], $ids(['sort' => 'variable_symbol', 'direction' => 'asc']));
        self::assertSame([$this->tx['a'], $this->tx['b'], $this->tx['c']], $ids(['sort' => 'variable_symbol', 'direction' => 'desc']));
        // Mimo whitelist → výchozí řazení, žádný SQL fragment z požadavku.
        self::assertSame([$this->tx['a'], $this->tx['b'], $this->tx['c']], $ids(['sort' => 'bt.id; DROP TABLE x', 'direction' => 'sideways']));

        // Řadí se v SQL přes stránky, ne jen načtená stránka.
        $page2 = $this->detail(['sort' => 'amount', 'direction' => 'asc', 'per_page' => '5', 'page' => '1']);
        self::assertSame($this->tx['b'], $page2['transactions'][0]['id']);
    }

    public function testUnpostedListSortsByWhitelistedColumn(): void
    {
        $this->postTx($this->tx['c']);
        $ids = fn (array $q): array => array_column($this->unpostedList(['scope' => 'all', 'q' => self::MARK] + $q)['items'], 'id');

        self::assertSame([$this->tx['c'], $this->tx['b'], $this->tx['a']], $ids([]), 'Výchozí pořadí = nejnovější nahoře.');
        self::assertSame([$this->tx['b'], $this->tx['a'], $this->tx['c']], $ids(['sort' => 'amount', 'direction' => 'asc']));
        // Zaúčtované (c) až za nezaúčtovanými při vzestupném řazení podle stavu zaúčtování.
        self::assertSame($this->tx['c'], $ids(['sort' => 'posting', 'direction' => 'asc'])[2]);
        self::assertSame([$this->tx['c'], $this->tx['b'], $this->tx['a']], $ids(['sort' => 'nope']));
    }

    private function addTx(string $date, float $amount, ?string $vs, string $counterparty): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol, counterparty_account, counterparty_name,
                 description, import_fingerprint)
             VALUES (?, ?, ?, 'CZK', ?, '1000000005', ?, ?, ?)"
        )->execute([
            $this->statementId, $date, $amount, $vs, $counterparty, self::MARK . ' ' . $counterparty,
            hash('sha256', self::MARK . $counterparty . uniqid('', true)),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function postTx(int $txId): int
    {
        return $this->journal->insert(
            [
                'supplier_id' => $this->supplierId,
                'period_id'   => $this->periodId,
                'entry_date'  => self::YEAR . '-03-15',
                'source_type' => 'bank',
                'source_id'   => $txId,
                'posted_at'   => date('Y-m-d H:i:s'),
                'posted_by'   => $this->userId,
            ],
            [
                ['account_id' => $this->accountId, 'side' => 'debit', 'amount' => 100.0],
                ['account_id' => $this->accountId, 'side' => 'credit', 'amount' => 100.0],
            ],
        );
    }

    private function cloneSupplier(): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO supplier
                (company_name,display_name,street,city,zip,country_id,is_vat_payer,email,
                 default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode)
             SELECT '__TEST BANKNOTE B','__TEST BANKNOTE B',street,city,zip,country_id,0,
                    CONCAT('banknote-', id, '-', UNIX_TIMESTAMP(), '@example.test'),
                    default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode
               FROM supplier WHERE id=?"
        )->execute([$this->supplierId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,string> $query */
    private function detail(array $query): array
    {
        $req = $this->request('GET', 'accountant')->withQueryParams($query);
        $res = $this->decode($this->bank->detail($req, new Psr7Response(), ['id' => (string) $this->statementId]));
        self::assertSame(200, $res['status']);
        return $res['body'];
    }

    /** @param array<string,string> $query */
    private function unpostedList(array $query): array
    {
        $req = $this->request('GET', 'accountant')->withQueryParams($query);
        $res = $this->decode($this->unposted->unposted($req, new Psr7Response()));
        self::assertSame(200, $res['status']);
        return $res['body'];
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(object $action, string $method, string $role, array $args, array $body = []): array
    {
        $req = $this->request($method, $role);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        return $this->decode($action->__invoke($req, new Psr7Response(), $args));
    }

    private function request(string $method, string $role): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/bank')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function decode(ResponseInterface $resp): array
    {
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
